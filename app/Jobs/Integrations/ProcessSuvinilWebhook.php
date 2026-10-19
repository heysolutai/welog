<?php

namespace App\Jobs\Integrations;

use App\Jobs\Concerns\IntegrationJob;
use App\Models\AppnozImport;
use App\Models\WebhookEvent;
use App\Services\FluxoB\IngestPartnerRows;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa um lote de resgates da Suvinil já persistido em webhook_events.
 *
 * Cada linha vira um Pedido de Venda na Omie, e isso é exatamente o que não
 * cabe numa requisição HTTP. O lote inteiro vira uma Importação Parceiros,
 * igual à que nasce de um relatório — a tela de acompanhamento, os bloqueios
 * e o histórico continuam sendo um só, sem uma segunda tela para explicar.
 *
 * Fila `webhooks`, como o irmão da Omie: é caminho interativo (o parceiro
 * está esperando o resgate aparecer) e não pode ficar atrás das varreduras.
 */
class ProcessSuvinilWebhook implements ShouldQueue
{
    use IntegrationJob, Queueable;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(IngestPartnerRows $ingest): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        // O job pode rodar ANTES de o INSERT do webhook estar visível: o
        // dispatch (Redis) costuma ser mais rápido que o commit no banco
        // gerenciado. Reenfileira em vez de estourar — mas com teto, senão um
        // id que nunca aparece cicla na fila para sempre.
        if ($event === null) {
            if ($this->attempts() >= 3) {
                Log::channel('integrations')->warning('Webhook Suvinil descartado: evento inexistente.', [
                    'webhook_event_id' => $this->webhookEventId,
                    'attempts' => $this->attempts(),
                ]);

                return;
            }

            $this->release(10);

            return;
        }

        if ($event->status === WebhookEvent::STATUS_PROCESSED) {
            return;
        }

        $payload = (array) $event->payload;
        $linhas = $payload['resgates'] ?? $payload['linhas'] ?? $payload['rows'] ?? (array_is_list($payload) ? $payload : []);
        $linhas = array_values(array_filter((array) $linhas, 'is_array'));

        /*
         * A trava de reenvio é o próprio identificador do lote, não o conteúdo.
         *
         * Um mesmo lote reenviado com uma linha corrigida tem outro hash de
         * corpo e passaria batido, duplicando as outras. O `external_event_id`
         * já é o que o parceiro chamou de lote — é ele que amarra.
         */
        $trava = hash('sha256', WebhookEvent::SOURCE_SUVINIL.':'.$event->external_event_id);

        /*
         * Retentativa depois de uma falha no meio do lote: CONTINUA o lote.
         *
         * O caso real é a Omie ocupada ("Consumo redundante, aguarde 59
         * segundos"): o resgate fica criado mas sem pedido, e a fila tenta de
         * novo depois do intervalo (ver IntegrationJob::backoff). Antes, a nova
         * tentativa via o lote aberto, marcava o evento como processado e saía —
         * o erro sumia do log e o resgate ficava em "Recebido" para sempre.
         * Reprocessar o mesmo lote é seguro: linha que já virou pedido não é
         * tocada, e o pedido na Omie é idempotente pela chave de integração.
         */
        $import = AppnozImport::where('file_hash', $trava)->first()
            ?? $ingest->abrir(
                // A porta por onde o lote chegou (suvinil-…, reconhece-…).
                "{$event->source}-{$event->external_event_id}",
                $trava,
                AppnozImport::SOURCE_WEBHOOK,
            );

        try {
            // Primeira linha = 1: aqui não existe cabeçalho para descontar, e
            // "linha 2" para o primeiro resgate mandaria o operador procurar
            // o problema no lugar errado.
            $ingest->processar($import, $linhas, primeiraLinha: 1);
        } catch (Throwable $e) {
            $import->update(['status' => AppnozImport::STATUS_FAILED, 'error' => $e->getMessage()]);
            $event->markFailed($e->getMessage());

            throw $e;
        }

        $import->update(['error' => null]);
        $event->markProcessed();
    }
}
