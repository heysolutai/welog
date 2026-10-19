<?php

namespace App\Console\Commands;

use App\Contracts\Integrations\TrelloGateway;
use App\Models\Project;
use App\Models\TrelloListMapping;
use App\Services\FluxoA\CreateTrelloCardForProject;
use Illuminate\Console\Command;
use Throwable;

/**
 * Liga o WeLog ao quadro real do Trello, de uma vez e quantas vezes for
 * preciso (é idempotente — roda no deploy):
 *
 *   1. lê as listas do quadro e grava o mapeamento lista → etapa pelo NOME;
 *   2. registra o webhook do quadro (se houver URL e ainda não existir);
 *   3. cria o card real dos Projetos que não têm (nasceram com o driver fake).
 *
 *   php artisan welog:trello:setup              (faz tudo)
 *   php artisan welog:trello:setup --dry-run    (só mostra o que faria)
 *
 * Com TRELLO_DRIVER=fake não faz nada e sai em silêncio — assim o deploy pode
 * chamar sempre, ligado ou não.
 *
 * O quadro da operação tem mais listas do que etapas (Compras e Recebimento
 * são as duas "Produção") e listas de apoio que não movem Projeto nenhum
 * (Templates, Exceções, Compras Indiretas…).
 *
 * São TRÊS as movimentações que o quadro governa: Design, Produção e Montagem.
 * "Envio", "Em estoque" e "Concluído" NÃO são mapeados de propósito — o kit no
 * estoque vem do sinal da Omie, o envio da expedição dos resgates e a
 * conclusão do fim das entregas. Card arrastado para essas listas não move o
 * Projeto, e é assim que uma lista de apoio no board não desfaz o que a Omie
 * ou a expedição já disseram.
 */
class TrelloSetup extends Command
{
    protected $signature = 'welog:trello:setup
                            {--dry-run : Só mostra o que faria, sem gravar nem criar nada}
                            {--map=* : Sobrescreve/adiciona de-para no formato "Nome da lista=estagio" (design|production|assembly)}
                            {--sem-cards : Mapeia listas e registra o webhook, sem criar card nenhum}
                            {--pedido=* : Cria card SÓ do(s) Projeto(s) com este nº de pedido Omie (os demais ficam como estão)}';

    protected $description = 'Liga o Trello: mapeia as listas do quadro, registra o webhook e cria os cards que faltam';

    /** Nome da lista (sem acento/caixa) → etapa. Espelha o quadro atual da operação. */
    private const DEFAULT_MAP = [
        'design' => Project::STATUS_DESIGN,
        'aguardando pedido e/ou pagamento' => Project::STATUS_PRODUCTION,
        'aguardando pedido e ou pagamento' => Project::STATUS_PRODUCTION,
        'compras' => Project::STATUS_PRODUCTION,
        'recebimento' => Project::STATUS_PRODUCTION,
        'pre - montagem' => Project::STATUS_ASSEMBLY,
        'pre-montagem' => Project::STATUS_ASSEMBLY,
        'pre montagem' => Project::STATUS_ASSEMBLY,
        'montagem' => Project::STATUS_ASSEMBLY,
        'producao hoje' => Project::STATUS_ASSEMBLY,
    ];

    public function handle(TrelloGateway $trello, CreateTrelloCardForProject $cards): int
    {
        $dry = (bool) $this->option('dry-run');
        $boardId = (string) config('integrations.trello.board_id');
        $callback = (string) config('integrations.trello.webhook_callback_url');
        $secret = (string) config('integrations.trello.secret');

        if (config('integrations.trello.driver') !== 'api') {
            $this->line('Trello desligado (TRELLO_DRIVER != api) — nada a fazer.');

            return self::SUCCESS;
        }

        if ($boardId === '') {
            $this->error('TRELLO_BOARD_ID vazio. É o id da URL do quadro: trello.com/b/<ID>/...');

            return self::FAILURE;
        }

        // ── 1. Listas → etapas ───────────────────────────────────────────
        try {
            $lists = $trello->getBoardLists($boardId);
        } catch (Throwable $e) {
            $this->error('Não consegui ler as listas do quadro: '.$e->getMessage());
            $this->line('  Confira TRELLO_API_KEY / TRELLO_API_TOKEN e se o token tem acesso ao quadro.');

            return self::FAILURE;
        }

        $map = $this->buildMap();
        $rows = [];
        $mapped = [];

        foreach ($lists as $list) {
            $status = $map[$this->normalize($list['name'])] ?? null;
            $rows[] = [$list['name'], $status ?? '<fg=gray>— ignorada</>'];
            if ($status !== null) {
                $mapped[] = [$list['id'], $list['name'], $status];
            }
        }

        $this->line('<options=bold>1. Listas do quadro → etapas do WeLog</>');
        $this->table(['Lista no Trello', 'Etapa'], $rows);

        $faltando = array_diff(Project::TRELLO_STATUSES, array_column($mapped, 2));
        if ($faltando !== []) {
            $this->warn('   Nenhuma lista mapeada para: '.implode(', ', $faltando).'. Use --map="Nome da lista=estagio".');
        }

        if (! $dry) {
            // Substitui o mapeamento inteiro: sobra de lista antiga (Picking,
            // Packing, Emissão de NF…) apontaria para id que não existe mais.
            TrelloListMapping::query()->delete();
            foreach ($mapped as [$id, $name, $status]) {
                TrelloListMapping::create(['trello_list_id' => $id, 'trello_list_name' => $name, 'pipeline_status' => $status]);
            }
            $this->info('   '.count($mapped).' lista(s) mapeada(s).');
        }

        // ── 2. Webhook ────────────────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>2. Webhook</>');

        if ($callback === '') {
            $this->warn('   TRELLO_WEBHOOK_CALLBACK_URL vazio — sem webhook o Trello não avisa o WeLog quando um card se move.');
            $this->line('   Preencha com https://SEU-DOMINIO/webhooks/trello e rode de novo.');
        } elseif ($dry) {
            $this->line('   Registraria o webhook em '.$callback);
        } else {
            if ($secret === '') {
                $this->warn('   TRELLO_API_SECRET vazio: em produção o webhook é RECUSADO (403). Preencha e rode de novo.');
            }

            try {
                $hook = $trello->registerWebhook($callback, $boardId);
                $this->info('   Webhook registrado ('.$hook['id'].') → '.$callback);
            } catch (Throwable $e) {
                // O Trello recusa duplicata do mesmo quadro+URL+token: isso é
                // "já estava registrado", não erro — o comando roda a cada deploy.
                if (str_contains(mb_strtolower($e->getMessage()), 'already exists')) {
                    $this->info('   Webhook já registrado → '.$callback);
                } else {
                    $this->error('   Falha ao registrar o webhook: '.$e->getMessage());
                    $this->line('   O Trello faz um HEAD/GET na URL na hora do registro — ela precisa responder 200 pela internet.');
                }
            }
        }

        // ── 3. Cards que faltam ───────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>3. Cards dos Projetos</>');

        /*
         * Ligar o Trello numa base já povoada criaria dezenas de cards de uma
         * vez — e o teste da operação começa por UM pedido. `--sem-cards` pula
         * a etapa; `--pedido=66906` restringe ao(s) pedido(s) indicado(s).
         */
        if ($this->option('sem-cards')) {
            $this->line('   Pulado (--sem-cards). Crie depois com --pedido=<nº do pedido>.');
            $this->newLine();
            $this->line($dry
                ? '<comment>Simulação — nada gravado. Rode sem --dry-run para aplicar.</comment>'
                : 'Pronto. Mova um card no quadro e confira com <options=bold>php artisan welog:webhooks:status</>.');

            return self::SUCCESS;
        }

        $pedidos = array_values(array_filter(array_map('trim', (array) $this->option('pedido'))));

        $semCard = Project::with('company')
            ->where(fn ($q) => $q->whereNull('trello_card_id')->orWhere('trello_card_id', 'like', 'fake-card-%'))
            ->when($pedidos !== [], fn ($q) => $q->whereIn('omie_order_number', $pedidos))
            ->orderBy('id')->get();

        if ($pedidos !== []) {
            $naoAchados = array_diff($pedidos, $semCard->pluck('omie_order_number')->all());
            if ($naoAchados !== []) {
                $this->warn('   Pedido(s) sem Projeto correspondente ou que já têm card real: '.implode(', ', $naoAchados));
            }
        }
        $criaveis = $semCard->filter(fn (Project $p) => in_array($p->pipeline_status, Project::TRELLO_STATUSES, true));
        $fora = $semCard->count() - $criaveis->count();

        $this->line('   Sem card real: '.$semCard->count().' · a criar agora: '.$criaveis->count()
            .($fora ? " · já em Em Estoque/Concluído (não ganham card): {$fora}" : ''));

        if ($dry) {
            foreach ($criaveis as $p) {
                $this->line("   · #{$p->id} {$p->name} → lista de \"{$p->pipeline_status}\"");
            }
        } else {
            $ok = 0;
            $erros = 0;
            foreach ($criaveis as $project) {
                try {
                    if ($cards->recreate($project)) {
                        $ok++;
                    }
                } catch (Throwable $e) {
                    $erros++;
                    $this->error("   #{$project->id} {$project->name}: ".$e->getMessage());
                }
            }
            if ($ok || $erros) {
                $this->info("   Cards criados: {$ok}".($erros ? "  · erros: {$erros}" : ''));
            }
        }

        $this->newLine();
        $this->line($dry
            ? '<comment>Simulação — nada gravado. Rode sem --dry-run para aplicar.</comment>'
            : 'Pronto. Mova um card no quadro e confira com <options=bold>php artisan welog:webhooks:status</>.');

        return self::SUCCESS;
    }

    /** @return array<string, string> nome normalizado → estágio */
    private function buildMap(): array
    {
        $map = self::DEFAULT_MAP;

        foreach ((array) $this->option('map') as $pair) {
            [$name, $status] = array_pad(explode('=', $pair, 2), 2, null);
            $status = trim((string) $status);

            if (! in_array($status, Project::TRELLO_STATUSES, true)) {
                $this->warn("--map ignorado: \"{$pair}\" (estágio deve ser ".implode('|', Project::TRELLO_STATUSES).')');

                continue;
            }

            $map[$this->normalize((string) $name)] = $status;
        }

        return $map;
    }

    /** Sem acento, sem caixa, espaços colapsados — o nome no quadro varia. */
    private function normalize(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $ascii = mb_strtolower(trim($ascii));

        return preg_replace('/\s+/', ' ', $ascii) ?? $ascii;
    }
}
