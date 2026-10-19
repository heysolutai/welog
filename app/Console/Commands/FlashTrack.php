<?php

namespace App\Console\Commands;

use App\Jobs\FluxoB\PollFlashTracking;
use App\Models\AppnozImportRow;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Roda o rastreio da Flash agora, sem esperar o agendamento de 2h.
 *
 *   php artisan welog:flash:rastrear
 *   php artisan welog:flash:rastrear --pedido=67518
 *
 * É o mesmo PollFlashTracking do agendador, executado na hora, com o antes e
 * depois de cada pedido na tela. Com FLASH_DRIVER=fake cada execução avança
 * um estágio (coletado → em trânsito → entregue); com `api`, mostra o que a
 * Flash tem de verdade.
 */
class FlashTrack extends Command
{
    protected $signature = 'welog:flash:rastrear
                            {--pedido= : Mostra só este pedido da Omie (o rastreio roda para todos)}';

    protected $description = 'Consulta agora o rastreio na Flash dos pedidos faturados e em transporte';

    private const ACOMPANHADOS = [AppnozImportRow::STATUS_INVOICED, AppnozImportRow::STATUS_DISPATCHED];

    private const ROTULOS = [
        AppnozImportRow::STATUS_INVOICED => 'NF emitida',
        AppnozImportRow::STATUS_DISPATCHED => 'Em transporte',
        AppnozImportRow::STATUS_DELIVERED => 'Entregue',
    ];

    public function handle(): int
    {
        $antes = AppnozImportRow::query()
            ->whereIn('status', self::ACOMPANHADOS)
            ->when($this->option('pedido'), fn (Builder $q, $p) => $q->where('omie_order_number', $p))
            ->pluck('status', 'id');

        if ($antes->isEmpty()) {
            $this->warn('Nenhum pedido faturado ou em transporte'.($this->option('pedido') ? ' com esse número' : '').'.');
            $this->line('Um pedido entra aqui depois de faturado (SyncOmieInvoices, ou welog:nf:simular em homologação).');

            return self::SUCCESS;
        }

        $this->line('Flash: <options=bold>'.config('integrations.flash.driver').'</> · referência: <options=bold>'
            .PollFlashTracking::campoDeReferencia().'</> · '.$antes->count().' pedido(s)');

        PollFlashTracking::dispatchSync();

        $linhas = AppnozImportRow::query()
            ->with(['trackingEvents' => fn ($q) => $q->limit(1)])
            ->whereIn('id', $antes->keys())
            ->orderBy('id')
            ->get();

        $this->table(
            ['Linha', 'Pedido', 'Antes', 'Agora', 'Rastreio', 'Último evento'],
            $linhas->map(fn (AppnozImportRow $r) => [
                $r->id,
                $r->omie_order_number,
                self::ROTULOS[$antes[$r->id]] ?? $antes[$r->id],
                $r->status !== $antes[$r->id]
                    ? '<fg=green>'.(self::ROTULOS[$r->status] ?? $r->status).'</>'
                    : (self::ROTULOS[$r->status] ?? $r->status),
                $r->tracking_code ?? '—',
                $r->trackingEvents->first()?->description ?? 'não localizado na Flash ainda',
            ]),
        );

        $this->line('Erros da consulta, se houver, ficam em storage/logs/integrations-*.log.');

        return self::SUCCESS;
    }
}
