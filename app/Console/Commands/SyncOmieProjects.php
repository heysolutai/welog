<?php

namespace App\Console\Commands;

use App\Services\FluxoA\OmieProjectMarker;
use App\Services\FluxoA\SyncOmieOrdersIntoProjects;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Carga inicial / reprocessamento: puxa da Omie os Pedidos de Venda do
 * projeto configurado (`OMIE_PROJECT_CODES`) e cria os Projetos WeLog que
 * ainda não existem.
 *
 * Existe porque o webhook só cobre o que acontece DEPOIS de ligado, e a
 * reconciliação horária olha só 2 dias para trás. Tudo que já estava na Omie
 * antes disso precisa de uma varredura com janela maior — uma vez.
 *
 * Duas consultas na Omie, como no webhook: `ListarPedidos` (paginado, já traz o
 * código do projeto) e, só para os aprovados que ainda não existem,
 * `ConsultarPedido` (itens, cliente, prazo). Ver `SyncOmieOrdersIntoProjects`.
 */
class SyncOmieProjects extends Command
{
    protected $signature = 'welog:omie:sync-projects
        {--desde= : Data inicial (YYYY-MM-DD). Padrão: --dias atrás}
        {--dias=90 : Janela em dias quando --desde não é informado}
        {--dry-run : Só lista o que seria criado; não consulta pedido nem grava}
        {--sem-filtro : Permite rodar SEM OMIE_PROJECT_CODES/MARKER (todo pedido vira Projeto)}';

    protected $description = 'Sincroniza Pedidos de Venda da Omie do projeto configurado em Projetos WeLog';

    public function handle(SyncOmieOrdersIntoProjects $sync, OmieProjectMarker $marker): int
    {
        $driver = config('integrations.omie.driver');
        $this->line("Driver Omie: <options=bold>{$driver}</>");

        if ($driver !== 'api') {
            $this->warn('Em `fake` a listagem é fixture — não reflete a conta real.');
        }

        // Sem filtro, "sincronizar" significa transformar a operação inteira da
        // Santa Origem em Projeto WeLog. Ninguém quer isso por acidente.
        if (! $marker->configured() && ! $this->option('sem-filtro')) {
            $this->error('Nenhum filtro de projeto configurado (OMIE_PROJECT_CODES vazio).');
            $this->line('  Sem ele TODO pedido de venda da conta viraria Projeto WeLog.');
            $this->line('  Descubra o código com <options=bold>php artisan welog:omie:projetos</>, defina');
            $this->line('  <options=bold>OMIE_PROJECT_CODES=...</> no .env, rode <options=bold>config:cache</> e volte aqui.');
            $this->line('  (Se for intencional mesmo, use --sem-filtro.)');

            return self::FAILURE;
        }

        $this->line('Critério: <options=bold>'.$marker->criterio().'</> '.match ($marker->criterio()) {
            'projeto' => '(códigos '.implode(', ', $marker->projectCodes()).')',
            'observacao' => '(termos '.implode(', ', $marker->termos()).')',
            default => '(NENHUM — todo pedido vira Projeto)',
        });

        try {
            $since = $this->option('desde')
                ? Carbon::createFromFormat('Y-m-d', (string) $this->option('desde'))->startOfDay()
                : now()->subDays((int) $this->option('dias'))->startOfDay();
        } catch (Throwable) {
            $this->error('--desde precisa estar no formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line('Janela: pedidos desde <options=bold>'.$since->format('d/m/Y').'</>'
            .($dryRun ? '  <fg=yellow>[dry-run — nada será gravado]</>' : ''));
        $this->newLine();

        try {
            $stats = $sync->handle($since, $dryRun, function (string $evento, array $ctx): void {
                $pedido = $ctx['numero_pedido'] ?? $ctx['omie_order_id'];

                match ($evento) {
                    'criaria' => $this->line("  <fg=cyan>→</> pedido {$pedido}: seria criado"),
                    'criado' => $this->line("  <fg=green>✓</> pedido {$pedido}: Projeto #{$ctx['project_id']} — {$ctx['nome']}"),
                    'nao_criado' => $this->line("  <fg=yellow>·</> pedido {$pedido}: não criado (ver storage/logs/integrations-*.log)"),
                    'falha' => $this->line("  <fg=red>✗</> pedido {$pedido}: {$ctx['erro']}"),
                    default => null,
                };
            });
        } catch (Throwable $e) {
            $this->error('Falha ao consultar a Omie: '.$e->getMessage());
            $this->line('  Se for bloqueio por consumo ("Aguarde N segundos"), espere e rode de novo — o que já foi criado não se repete.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Métrica', 'Qtd'], [
            ['Páginas consultadas (ListarPedidos)', $stats['paginas']],
            ['Pedidos listados', $stats['listados']],
            ['Fora do filtro de projeto', $stats['fora_do_filtro']],
            ['Já eram Projeto WeLog', $stats['ja_existiam']],
            [$dryRun ? 'Seriam criados' : 'Candidatos (ConsultarPedido)', $stats['candidatos']],
            ['Criados', $stats['criados']],
            ['Falhas', $stats['falhas']],
        ]);

        if ($dryRun && $stats['candidatos'] > 0) {
            $this->info('Rode sem --dry-run para criar.');
        }

        return $stats['falhas'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
