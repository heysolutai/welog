<?php

namespace App\Console\Commands;

use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Support\ApiCircuitBreaker;
use App\Models\Project;
use App\Models\TrelloListMapping;
use App\Models\WebhookEvent;
use App\Services\FluxoA\CreateTrelloCardForProject;
use Illuminate\Console\Command;
use Throwable;

/**
 * Por que ESTE pedido não virou card no quadro.
 *
 * "Não criou no Trello" tem meia dúzia de causas que se parecem de fora: o
 * Projeto já tinha card (e reprocessar o webhook não faz um segundo), o driver
 * está em `fake`, o token perdeu o acesso ao quadro, a etapa atual não tem
 * lista mapeada, o card modelo foi recusado, a integração está pausada. Cada
 * uma se resolve de um jeito, e sem um lugar que responda isso a investigação
 * começa abrindo log.
 *
 *   php artisan welog:trello:card 69848            (só diagnostica)
 *   php artisan welog:trello:card 69848 --criar    (cria o card que falta)
 *
 * Diferente do `welog:trello:setup`, não toca no mapeamento de listas nem no
 * webhook do quadro: dá para rodar em produção sem mudar nada.
 */
class TrelloCardDiagnose extends Command
{
    protected $signature = 'welog:trello:card
                            {pedido : Nº do pedido na Omie (ou #id do Projeto)}
                            {--criar : Cria o card se estiver faltando}';

    protected $description = 'Diz por que um pedido não tem card no Trello e, com --criar, cria';

    /** Preenchido no diagnóstico: o card gravado ainda existe no quadro? */
    private ?bool $cardSumiu = null;

    public function handle(TrelloGateway $trello, CreateTrelloCardForProject $cards): int
    {
        $pedido = trim((string) $this->argument('pedido'));

        $project = str_starts_with($pedido, '#')
            ? Project::with('company')->find((int) ltrim($pedido, '#'))
            : Project::with('company')->where('omie_order_number', $pedido)->orderByDesc('id')->first();

        if ($project === null) {
            return $this->semProjeto($pedido);
        }

        $this->line('<options=bold>Projeto</>');
        $this->table([], [
            ['#', $project->id],
            ['Nome', $project->name],
            ['Pedido Omie', $project->omie_order_number ?: '—'],
            ['Etapa', $project->pipeline_status],
            ['Tipo (template)', $project->template ?: '— não classificado'],
            ['Card modelo', $project->templateType()?->sourceCardId() ?: '— nenhum configurado'],
            ['Card', $project->trello_card_id ?: 'nenhum'],
            ['URL', $project->trello_card_url ?: '—'],
        ]);

        $impedimentos = $this->impedimentos($project, $trello);

        $this->newLine();
        $this->line('<options=bold>Diagnóstico</>');

        foreach ($impedimentos as $linha) {
            $this->line('  '.$linha);
        }

        if ($impedimentos === []) {
            $this->info('  Nada impede a criação do card.');
        }

        if (! CreateTrelloCardForProject::needsRealCard($project)) {
            /*
             * Card apagado no quadro: a plataforma ainda guarda o id, e é ele
             * que faz a criação sair pela porta do "já tem card". Soltar a
             * referência aqui é o que permite refazer o card de um pedido —
             * apagar no Trello e rodar com --criar, o caminho que a operação
             * usa para conferir o modelo depois de mexer nele.
             */
            if ($this->cardSumiu === true && $this->option('criar')) {
                $project->update(['trello_card_id' => null, 'trello_card_url' => null]);
                $this->newLine();
                $this->line('O card gravado não existia mais no quadro; referência solta, criando outro.');

                return $this->criar($project, $cards);
            }

            $this->newLine();
            $this->line('Este Projeto JÁ tem card, e reprocessar o webhook não cria um segundo — de propósito:');
            $this->line('  seria um card órfão no quadro a cada reentrega da Omie.');
            $this->line('  Para ver a criação do zero, apague o card no quadro e rode com <options=bold>--criar</>.');

            return self::SUCCESS;
        }

        if (! $this->option('criar')) {
            $this->newLine();
            $this->line('Rode de novo com <options=bold>--criar</> para criar o card agora.');

            return self::SUCCESS;
        }

        return $this->criar($project, $cards);
    }

    /** Sem Projeto, o desfecho está no evento do webhook, não no Trello. */
    private function semProjeto(string $pedido): int
    {
        $this->error("Nenhum Projeto com o pedido {$pedido}.");

        $evento = WebhookEvent::where('source', 'omie')
            ->where('payload', 'like', '%"'.$pedido.'"%')
            ->latest('id')
            ->first();

        if ($evento === null) {
            $this->line('  Nenhum webhook recebido com esse número. Confira em `php artisan welog:webhooks:status`.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Último webhook desse pedido: #{$evento->id} · {$evento->topic} · <options=bold>{$evento->status}</>");
        $this->line('  Motivo: '.($evento->error ?: '—'));
        $this->line('  O card só existe depois do Projeto: resolva o evento primeiro.');

        return self::FAILURE;
    }

    /**
     * Tudo que, hoje, atrapalharia o card deste Projeto.
     *
     * Lista em vez de parar no primeiro achado: quem está diagnosticando em
     * produção quer ver os três de uma vez, não descobrir um por execução.
     *
     * @return list<string>
     */
    private function impedimentos(Project $project, TrelloGateway $trello): array
    {
        $achados = [];

        if (config('integrations.trello.driver') !== 'api') {
            $achados[] = 'TRELLO_DRIVER está em `'.config('integrations.trello.driver')
                .'`: o card é simulado (`fake-card-N`) e nada sai para o quadro.';
        }

        if ((string) config('integrations.trello.board_id') === '') {
            $achados[] = 'TRELLO_BOARD_ID vazio: sem quadro não há como pintar a etiqueta do tipo.';
        }

        if (($faltam = ApiCircuitBreaker::remaining('trello')) !== null) {
            $achados[] = "Integração PAUSADA por recusa do Trello; volta em {$faltam}s. "
                .'Ver `welog:integracoes:status`.';
        }

        $etapa = $project->pipeline_status;

        if (! in_array($etapa, Project::TRELLO_STATUSES, true)) {
            $achados[] = "A etapa \"{$etapa}\" não é do Trello (Em Estoque e Concluído são do WeLog). "
                .'Card só nasce em Design, Produção ou Montagem.';
        } elseif (TrelloListMapping::where('pipeline_status', $etapa)->value('trello_list_id') === null) {
            $achados[] = "Nenhuma lista do quadro mapeada para \"{$etapa}\". "
                .'Rode `php artisan welog:trello:setup`.';
        }

        $modelo = $project->templateType()?->sourceCardId();

        if ($project->template === null) {
            $achados[] = 'Projeto sem tipo de campanha: o card nasce do zero, sem modelo. '
                .'O tipo vem do projeto da Omie, via OMIE_PROJECT_TEMPLATES.';
        } elseif ($modelo === null) {
            $achados[] = "Tipo `{$project->template}` sem card modelo em TRELLO_CARD_TEMPLATES: "
                .'o card nasce do zero, com a etiqueta do tipo.';

            /*
             * Preencher o .env e reiniciar NÃO basta, e é onde se perde tempo.
             * O .env não está na imagem (o .dockerignore o exclui): ele entra
             * como variável de ambiente pelo `env_file`, que o Compose lê ao
             * CRIAR o container. `restart` reaproveita o ambiente antigo.
             */
            $achados[] = 'Se a variável JÁ está no .env do servidor: `restart` não relê o `env_file`. '
                .'Recrie com `docker compose -f docker-compose.prod.yml up -d`'
                .(app()->configurationIsCached() ? ' e depois `php artisan config:cache`' : '')
                .'. Confira com `printenv TRELLO_CARD_TEMPLATES` dentro do container.';
        } else {
            // Configurado não é o mesmo que alcançável: o modelo pode ter sido
            // arquivado, ou viver num quadro que este token não enxerga. Nesse
            // caso a cópia é recusada e o card nasce do zero, silenciosamente.
            try {
                if ($trello->getCard($modelo) === null) {
                    $achados[] = "Card modelo `{$modelo}` NÃO foi encontrado pelo token "
                        .'(arquivado, apagado, ou em quadro sem acesso). A cópia é recusada e o '
                        .'card nasce do zero.';
                }
            } catch (Throwable $e) {
                $achados[] = "Não consegui ler o card modelo `{$modelo}`: ".$e->getMessage();
            }
        }

        /*
         * Card que a plataforma acha que existe mas não está mais no quadro.
         * Some quando alguém apaga o card à mão, e deixa o Projeto surdo: não
         * há mais o que arrastar para movê-lo.
         *
         * ARQUIVADO conta como ausente. A API devolve o card arquivado
         * normalmente, com `closed: true` — só que ele saiu do quadro, ninguém
         * o arrasta, e arquivar é o que a interface do Trello oferece primeiro
         * (excluir está escondido atrás do card já arquivado).
         */
        if (! CreateTrelloCardForProject::needsRealCard($project)) {
            try {
                $card = $trello->getCard((string) $project->trello_card_id);
                $arquivado = (bool) ($card['closed'] ?? false);
                $this->cardSumiu = $card === null || $arquivado;

                if ($this->cardSumiu) {
                    $achados[] = 'O card gravado não está mais no quadro ('
                        .($arquivado ? 'arquivado' : 'apagado à mão').'). '
                        .'Nada move este Projeto até ele ser recriado: rode com --criar.';
                }
            } catch (Throwable $e) {
                $achados[] = 'Não consegui ler o card no Trello: '.$e->getMessage()
                    .' · confira TRELLO_API_KEY/TRELLO_API_TOKEN e o acesso do token ao quadro.';
            }
        }

        return $achados;
    }

    private function criar(Project $project, CreateTrelloCardForProject $cards): int
    {
        try {
            if (! $cards->recreate($project)) {
                $this->warn('Card não criado: a etapa atual não tem lista no quadro.');

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->error('Falha ao criar o card: '.$e->getMessage());

            return self::FAILURE;
        }

        $project->refresh();
        $this->info('Card criado: '.$project->trello_card_url);

        return self::SUCCESS;
    }
}
