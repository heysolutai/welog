<?php

namespace App\Services\FluxoA;

use App\Contracts\Integrations\TrelloGateway;
use App\Jobs\Integrations\SyncTrelloStockLabel;
use App\Models\Project;
use App\Models\TrelloListMapping;
use App\Support\Projects\ProjectTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fluxo A, etapa 3: cria o card do Projeto no board do Trello. O card é o
 * elo com a produção: é arrastando ELE que o webhook move o Projeto.
 */
class CreateTrelloCardForProject
{
    public function __construct(private readonly TrelloGateway $trello) {}

    /** Projeto novo: card na lista de Design, uma vez só. */
    public function handle(Project $project): void
    {
        if ($project->trello_card_id !== null) {
            return;
        }

        $this->createIn($project, Project::STATUS_DESIGN);
        $project->update(['pipeline_status' => Project::STATUS_DESIGN]);
    }

    /**
     * Projeto que já existe mas não tem card REAL (nasceu com o driver fake
     * ou sem Trello): cria o card na lista do estágio ATUAL, sem mexer no
     * estágio. Devolve false quando o estágio não tem lista no board (Em
     * Estoque e Concluído são do WeLog, não do Trello).
     */
    public function recreate(Project $project): bool
    {
        if (! self::needsRealCard($project)) {
            return false;
        }

        if (! in_array($project->pipeline_status, Project::TRELLO_STATUSES, true)) {
            return false;
        }

        return $this->createIn($project, $project->pipeline_status);
    }

    /** Sem card, ou card do driver fake (`fake-card-N`) que não existe no Trello. */
    public static function needsRealCard(Project $project): bool
    {
        return $project->trello_card_id === null || str_starts_with($project->trello_card_id, 'fake-card-');
    }

    private function createIn(Project $project, string $status): bool
    {
        $listId = TrelloListMapping::where('pipeline_status', $status)->value('trello_list_id');

        if ($listId === null) {
            throw new \RuntimeException(
                "Nenhuma lista do Trello mapeada para \"{$status}\". Rode `php artisan welog:trello:setup --apply`.",
            );
        }

        $description = implode("\n", array_filter([
            "Projeto WeLog #{$project->id}",
            "Pedido Omie: {$project->omie_order_number}",
            "Cliente: {$project->company->name}",
            "Quantidade: {$project->quantity}",
            $project->delivery_deadline !== null
                ? 'Prazo de entrega: '.$project->delivery_deadline->format('d/m/Y')
                : null,
        ]));

        /*
         * O quadro da operação lê o card pelo PREFIXO do tipo da campanha
         * ([Evento], [COM Estoque], [SEM Estoque]…), e o tipo vem do projeto em
         * que o pedido foi classificado na Omie — ver `ProjectTemplate`.
         *
         * Sem template configurado, vale o formato antigo: "número do pedido -
         * nome do cliente", com o nome vindo do campo "Nº do pedido do cliente"
         * da Omie. Nos dois casos o título é montado aqui, e não a partir do
         * nome do Projeto, porque o nome é editável na plataforma — e o card é
         * a referência da FÁBRICA, que procura pelo pedido.
         */
        $template = $project->templateType();

        $titulo = $template !== null
            ? $template->cardTitle($project)
            : trim(($project->omie_order_number ?: "Projeto {$project->id}")
                .' - '.($project->client_order_number ?: $project->company->name));

        // Card MODELO do tipo: o card novo nasce cópia dele — checklists,
        // campos personalizados, anexos e a descrição padrão. Sem modelo
        // configurado, é criado do zero, só com os dados do pedido.
        $modelo = $template?->sourceCardId();

        $card = $modelo === null
            ? null
            : $this->copiarDoModelo($listId, $titulo, $description, $modelo, $project);

        // Modelo recusado (card arquivado, link errado, token sem acesso ao
        // quadro do modelo) NÃO pode custar o card. Um card sem checklist a
        // fábrica completa à mão; card nenhum é o Projeto invisível para ela.
        $card ??= $this->trello->createCard($listId, $titulo, $description);

        $project->update([
            'trello_card_id' => $card['id'],
            'trello_card_url' => $card['url'],
        ]);

        $this->aplicarEtiquetaDoTipo($project, $card['id'], $template);

        // Card criado depois do fato (backfill/--pedido) para Projeto que já
        // tem estoque nasce com a etiqueta amarela em dia.
        SyncTrelloStockLabel::dispatch($project->id);

        return true;
    }

    /**
     * Cria o card como CÓPIA do modelo. Devolve null quando o Trello recusa o
     * modelo — aí o chamador cria o card do zero.
     *
     * O motivo vai para o log de integrações porque é silencioso por natureza:
     * o card aparece no quadro, só que sem o checklist de produção, e ninguém
     * relaciona isso a um link de modelo que mudou.
     */
    private function copiarDoModelo(
        string $listId,
        string $titulo,
        string $description,
        string $modelo,
        Project $project,
    ): ?array {
        try {
            $dados = $this->modelo($modelo);

            // Dados do pedido em cima, formulário do modelo embaixo. A
            // descrição não vem no `keepFromSource`: mandar a nossa apagaria a
            // do modelo, que é justamente o formulário que a operação preenche
            // (contato, endereço de entrega, link da planilha).
            if (($dados['desc'] ?? '') !== '') {
                $description .= "\n\n---\n\n".$dados['desc'];
            }

            /*
             * O `idCardSource` exige o id LONGO do card (24 hexadecimais); o
             * código curto da URL (`trello.com/c/Zmz776Yy/...`) é recusado, e
             * é justamente ele que se copia do navegador para configurar.
             *
             * O Trello aceita o curto em quase todo o resto da API — inclusive
             * no `GET /cards/{id}` que usamos aqui —, então a configuração
             * continua sendo o que está na URL, e a conversão acontece neste
             * ponto, uma vez por hora por modelo.
             */
            return $this->trello->createCard($listId, $titulo, $description, $dados['id'] ?? $modelo);
        } catch (Throwable $e) {
            Log::channel('integrations')->warning('Trello recusou o card modelo; criando o card do zero.', [
                'project_id' => $project->id,
                'card_modelo' => $modelo,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * O card modelo lido do quadro: id longo e descrição padrão, em cache.
     *
     * Os dois mudam quando a operação reescreve o modelo, não a cada card
     * criado. Modelo ilegível devolve vazio em vez de estourar — o card ainda
     * vale com os dados do pedido, e quem copia já trata a falha.
     *
     * @return array{id: ?string, desc: string}
     */
    private function modelo(string $modelo): array
    {
        return Cache::remember(
            "trello:template:{$modelo}",
            3600,
            function () use ($modelo): array {
                try {
                    $card = $this->trello->getCard($modelo) ?? [];

                    return [
                        'id' => isset($card['id']) ? (string) $card['id'] : null,
                        'desc' => trim((string) ($card['desc'] ?? '')),
                    ];
                } catch (Throwable) {
                    return ['id' => null, 'desc' => ''];
                }
            },
        );
    }

    /**
     * Etiqueta do TIPO da campanha — a cor que o quadro já usa para ler o card
     * de relance (Evento laranja, Bosch verde, os demais azuis).
     *
     * Falha aqui não derruba nada: o Projeto e o card já existem, e um card
     * sem cor é um detalhe visual perto de perder a criação inteira por causa
     * de uma etiqueta. O erro fica no log de integrações.
     *
     * Convive com a etiqueta amarela de estoque (`SyncTrelloStockLabel`): uma
     * diz que campanha é, a outra diz se há saldo agora.
     */
    private function aplicarEtiquetaDoTipo(Project $project, string $cardId, ?ProjectTemplate $template): void
    {
        $boardId = (string) config('integrations.trello.board_id');

        if ($template === null || $boardId === '') {
            return;
        }

        ['nome' => $nome, 'cor' => $cor] = $template->boardLabel();

        try {
            // O id da etiqueta é imutável no quadro: resolver uma vez por dia
            // evita um GET /labels a cada card criado.
            $labelId = Cache::remember(
                "trello:type-label:{$boardId}:{$nome}:{$cor}",
                86400,
                fn () => $this->trello->ensureBoardLabel($boardId, $nome, $cor),
            );

            $this->trello->setCardLabel($cardId, $labelId, true);
        } catch (Throwable $e) {
            Log::channel('integrations')->warning('Card criado sem a etiqueta do tipo.', [
                'project_id' => $project->id,
                'template' => $template->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
