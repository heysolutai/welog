<?php

namespace App\Jobs\FluxoB;

use App\Contracts\Integrations\FlashGateway;
use App\Integrations\Support\TrackingStatus;
use App\Models\AppnozImportRow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fluxo B: acompanha o pedido na Flash do faturamento até a entrega
 * (módulos 8 e 9).
 *
 *   NF emitida (invoiced) ──1º evento de transporte──▶ em transporte (dispatched)
 *                          ──evento 4300──────────────▶ entregue (delivered)
 *
 * Não há envio de XML: a Flash recebe a nota por fora, então a consulta começa
 * no faturamento. Enquanto a Flash não localiza a encomenda (ou só diz
 * "aguardando postagem"), a linha fica em "NF emitida".
 *
 * Em lote: a API consulta vários números por requisição e limita por
 * requisição — uma chamada por linha esgotaria a cota com poucas centenas.
 */
class PollFlashTracking implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(FlashGateway $flash): void
    {
        $rows = AppnozImportRow::query()
            ->whereIn('status', [AppnozImportRow::STATUS_INVOICED, AppnozImportRow::STATUS_DISPATCHED])
            ->whereNotNull('nf_key')
            ->get()
            ->keyBy(fn (AppnozImportRow $r) => (string) self::referencia($r))
            ->forget('');

        if ($rows->isEmpty()) {
            return;
        }

        try {
            $rastreios = $flash->getTrackingMany($rows->keys()->all());
        } catch (Throwable $e) {
            // Falha da consulta inteira (token, rede, 5xx): nada é gravado e a
            // próxima rodada (2h) tenta de novo.
            Log::channel('integrations')->error('Falha ao consultar rastreio na Flash.', [
                'linhas' => $rows->count(),
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($rastreios as $referencia => $tracking) {
            $row = $rows->get((string) $referencia);

            if ($row === null || $tracking === null) {
                continue; // coleta ainda não efetivada
            }

            try {
                $this->sync($row, $tracking);
            } catch (Throwable $e) {
                // Isolado por item: uma linha com problema não pode interromper
                // o acompanhamento das demais.
                Log::channel('integrations')->error('Falha ao gravar rastreio da Flash.', [
                    'appnoz_import_row_id' => $row->id,
                    'referencia' => $referencia,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Qual dado da linha vai como número da encomenda (`numEncCli`) — o
     * formato combinado no cadastro com a Flash/Bridge Link.
     */
    public static function campoDeReferencia(): string
    {
        $campo = (string) config('integrations.flash.tracking_reference', 'nf_number');

        return in_array($campo, ['nf_number', 'nf_key', 'omie_order_number'], true) ? $campo : 'nf_number';
    }

    /**
     * O número pelo qual a Flash conhece a encomenda desta linha.
     *
     * No combinado com a Bridge Link é o prefixo da conta mais o número da NF
     * sem os zeros à esquerda: WEBOX44883. O número sai da própria chave da
     * NF, que a linha já tem desde o faturamento.
     */
    public static function referencia(AppnozImportRow $row): ?string
    {
        return match (self::campoDeReferencia()) {
            'nf_number' => $row->nfNumber() === null
                ? null
                : config('integrations.flash.tracking_prefix', '').$row->nfNumber(),
            'omie_order_number' => $row->omie_order_number,
            default => $row->nf_key,
        };
    }

    private function sync(AppnozImportRow $row, array $tracking): void
    {
        if ($row->tracking_code === null && $tracking['tracking_code'] !== null) {
            $row->update(['tracking_code' => $tracking['tracking_code']]);
        }

        foreach ($tracking['events'] as $event) {
            $row->trackingEvents()->firstOrCreate(
                [
                    'occurred_at' => Carbon::parse($event['occurred_at']),
                    'status' => $event['status'],
                ],
                [
                    'description' => $event['description'],
                    'location' => $event['location'] ?? null,
                ],
            );

            $status = TrackingStatus::tryFrom($event['status']);

            if ($status?->isDelivered() && $row->status !== AppnozImportRow::STATUS_DELIVERED) {
                $row->update([
                    'status' => AppnozImportRow::STATUS_DELIVERED,
                    'delivered_at' => Carbon::parse($event['occurred_at']),
                ]);
            }

            if (self::iniciaTransporte($status) && $row->status === AppnozImportRow::STATUS_INVOICED) {
                $row->update(['status' => AppnozImportRow::STATUS_DISPATCHED]);
            }
        }
    }

    /**
     * Evento que prova que a encomenda saiu: postada, em trânsito, saindo para
     * entrega, tentativa de entrega. "Aguardando postagem" (1100) e códigos
     * desconhecidos (`Other`) não contam — a Flash só conhece a nota.
     */
    private static function iniciaTransporte(?TrackingStatus $status): bool
    {
        return $status !== null && $status !== TrackingStatus::Other;
    }
}
