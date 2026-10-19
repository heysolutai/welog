<?php

namespace App\Jobs\FluxoB;

use App\Models\AppnozImportRow;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Nota autorizada na Omie (webhook NFe.NotaAutorizada) → resgate em "NF emitida".
 *
 * O webhook traz `id_pedido`, o ID interno do pedido — o mesmo que a WeLog
 * gravou no resgate ao criar o pedido (`omie_order_id`). A ligação é direta,
 * sem buscar pelo número de tela, e a nota entra no instante da autorização
 * em vez de esperar a varredura de hora em hora (SyncOmieInvoices, que segue
 * como reserva).
 *
 * Só é despachado para nota de pedido que É resgate: a conta emite nota da
 * operação inteira, e o resto é ignorado ainda no recebimento.
 */
class ProcessOmieInvoiceWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null || $event->status === WebhookEvent::STATUS_PROCESSED) {
            return;
        }

        $nota = (array) ($event->payload['event'] ?? []);
        $pedido = trim((string) ($nota['id_pedido'] ?? ''));
        $chave = preg_replace('/\D/', '', (string) ($nota['nfe_chave'] ?? ''));

        $row = $pedido === '' ? null : AppnozImportRow::where('omie_order_id', $pedido)->first();

        if ($row === null || strlen($chave) !== 44) {
            $event->update([
                'status' => WebhookEvent::STATUS_IGNORED,
                'error' => $row === null
                    ? "O pedido {$pedido} não é de um resgate de Importações Parceiros."
                    : 'A nota veio sem chave de acesso de 44 dígitos.',
                'processed_at' => now(),
            ]);

            return;
        }

        $row->registrarNota($chave, $this->emissao($nota));

        $event->markProcessed();
    }

    /** "2026-09-30T00:00:00-03:00" + "14:18:38", no fuso do negócio. */
    private function emissao(array $nota): ?Carbon
    {
        $data = substr((string) ($nota['data_emis'] ?? ''), 0, 10);

        if ($data === '') {
            return null;
        }

        try {
            // Hora de Brasília convertida para UTC, que é como o app grava:
            // sem a conversão, 14:18 voltava do banco como 11:18.
            return Carbon::parse(trim($data.' '.($nota['hora_emis'] ?? '')), config('integrations.business_timezone'))->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
