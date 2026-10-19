<?php

namespace App\Services\FluxoA;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Varre os Pedidos de Venda da Omie a partir de uma data e cria os Projetos
 * WeLog que faltam. É a mesma esteira do webhook, só que puxada em vez de
 * empurrada — serve de rede de segurança horária (`ReconcileOmieOrders`) e de
 * carga inicial (`welog:omie:sync-projects`).
 *
 * ── Duas consultas, de propósito ────────────────────────────────────────────
 *
 * 1. `ListarPedidos` com `filtrar_por_projeto`, uma passada por projeto
 *    configurado: a Omie já devolve só o recorte WeLog, e o `codProj` de cada
 *    item ainda é reconferido aqui (o filtro da origem não substitui a trava).
 * 2. `ConsultarPedido` só para os pedidos que passaram no filtro e ainda não
 *    são Projeto — é ele que devolve os itens (`det`), o cliente e o prazo.
 *
 * Inverter a ordem (consultar todos e filtrar depois) custa uma chamada por
 * pedido da conta inteira: ~530 a cada 5 dias, quase nenhum WeLog. Foi
 * exatamente isso que levou a Omie a bloquear a conta por consumo indevido.
 */
class SyncOmieOrdersIntoProjects
{
    public function __construct(
        private readonly OmieGateway $omie,
        private readonly CreateProjectFromOmieOrder $createProject,
        private readonly OmieProjectMarker $marker,
    ) {}

    /**
     * @param  bool  $dryRun  true = só conta e relata; não chama `ConsultarPedido`
     *                        nem grava nada.
     * @param  (callable(string $evento, array<string, mixed> $ctx): void)|null  $onEvent
     *
     * O callback recebe `fora_do_filtro`, `ja_existe`, `criaria`, `criado`,
     * `nao_criado` ou `falha` — é como o comando narra o progresso.
     * @return array{paginas: int, listados: int, fora_do_filtro: int, ja_existiam: int, candidatos: int, criados: int, falhas: int}
     */
    public function handle(CarbonInterface $since, bool $dryRun = false, ?callable $onEvent = null): array
    {
        $onEvent ??= static fn () => null;

        $stats = [
            'paginas' => 0,
            'listados' => 0,
            'fora_do_filtro' => 0,
            'ja_existiam' => 0,
            'candidatos' => 0,
            'criados' => 0,
            'falhas' => 0,
        ];

        /*
         * Uma varredura POR PROJETO configurado, com o filtro na origem.
         *
         * Antes era uma varredura só, sem filtro, jogando fora no cliente o
         * que não interessava: dois dias da conta da Santa Origem são 745
         * pedidos em 15 páginas, e quase nenhum é WeLog. Por projeto, o mesmo
         * recorte cabe numa página cada — é isso que permite a varredura rodar
         * de cinco em cinco minutos em vez de de hora em hora.
         *
         * Sem código configurado (filtro por observação, ou sem filtro
         * nenhum), volta a ser uma varredura única sem `filtrar_por_projeto`.
         */
        $codigos = $this->marker->projectCodes() ?: [null];

        foreach ($codigos as $codigo) {
            $this->varrer($since, $codigo, $dryRun, $onEvent, $stats);
        }

        return $stats;
    }

    /**
     * Uma passada completa da listagem (todas as páginas) para um projeto.
     *
     * @param  array<string, int>  $stats
     */
    private function varrer(
        CarbonInterface $since,
        ?string $projectCode,
        bool $dryRun,
        callable $onEvent,
        array &$stats,
    ): void {
        $page = 1;

        // Percorre TODAS as páginas: parar na primeira faria a rede de
        // segurança perder justamente os picos de volume.
        do {
            $result = $this->omie->listOrders($since, $page, $projectCode);
            $stats['paginas']++;

            foreach ($result['items'] as $order) {
                $orderId = (string) ($order['omie_order_id'] ?? $order['cabecalho']['codigo_pedido'] ?? '');

                if ($orderId === '') {
                    continue;
                }

                $stats['listados']++;

                $ctx = [
                    'omie_order_id' => $orderId,
                    'numero_pedido' => $order['cabecalho']['numero_pedido'] ?? null,
                    'cod_projeto' => $this->marker->projectCodeOf($order),
                ];

                if (! $this->marker->matches($order)) {
                    $stats['fora_do_filtro']++;
                    $onEvent('fora_do_filtro', $ctx);

                    continue;
                }

                if (Project::where('omie_order_id', $orderId)->exists()) {
                    $stats['ja_existiam']++;
                    $onEvent('ja_existe', $ctx);

                    continue;
                }

                $stats['candidatos']++;

                if ($dryRun) {
                    $onEvent('criaria', $ctx);

                    continue;
                }

                try {
                    $project = $this->createProject->handle($orderId);
                } catch (Throwable $e) {
                    // Um pedido problemático não pode derrubar a carga dos
                    // outros. Registra e segue; o resumo mostra a contagem.
                    $stats['falhas']++;
                    Log::channel('integrations')->error('Falha ao criar Projeto a partir de pedido Omie na sincronização.', [
                        ...$ctx,
                        'exception' => $e->getMessage(),
                    ]);
                    $onEvent('falha', [...$ctx, 'erro' => $e->getMessage()]);

                    continue;
                }

                if ($project === null) {
                    // `CreateProjectFromOmieOrder` já logou o motivo (pedido
                    // sumiu, ou o ConsultarPedido reprovou no filtro).
                    $onEvent('nao_criado', $ctx);

                    continue;
                }

                $stats['criados']++;
                $onEvent('criado', [...$ctx, 'project_id' => $project->id, 'nome' => $project->name]);
            }

            $page++;
        } while ($page <= ($result['total_pages'] ?? 1));
    }
}
