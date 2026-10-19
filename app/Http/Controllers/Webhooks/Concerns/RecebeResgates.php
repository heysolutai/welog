<?php

namespace App\Http\Controllers\Webhooks\Concerns;

use App\Jobs\Integrations\ProcessSuvinilWebhook;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;

/**
 * Recebimento de um lote de resgates, comum às portas que trazem resgate.
 *
 * A Suvinil manda pela URL dela e a Reconhece pela dela; o que acontece depois
 * é o mesmo — e TEM de ser o mesmo: trava de reenvio pelo lote, teto por
 * chamada, corpo desconhecido guardado em vez de recusado e o processamento
 * na fila (cliente e Pedido de Venda na Omie). Duas cópias desta lógica
 * divergiriam justamente na parte que impede brinde duplicado.
 */
trait RecebeResgates
{
    protected function receberResgates(array $payload, string $corpo, string $source): JsonResponse
    {
        $linhas = $this->linhasDeResgate($payload);

        $teto = (int) config('integrations.suvinil.max_rows', 200);

        if (count($linhas) > $teto) {
            return response()->json([
                'received' => false,
                'error' => 'Lote de '.count($linhas)." resgates excede o limite de {$teto} por chamada. Divida em lotes menores.",
            ], 422);
        }

        /*
         * O `lote` é a chave de idempotência, e por isso ele é obrigatório na
         * prática: reenvio é normal (timeout, retry do lado deles) e sem um
         * identificador estável o mesmo resgate vira dois Pedidos de Venda na
         * Omie. Quando ele não vem, caímos no hash do corpo — que resolve o
         * reenvio idêntico e só ele.
         */
        $referencia = $this->referenciaDoLote($payload, $corpo);

        $event = WebhookEvent::firstOrCreate(
            [
                'source' => $source,
                'external_event_id' => $referencia,
            ],
            [
                'topic' => 'Resgate.Lote',
                'payload' => $payload,
            ],
        );

        // Lote já conhecido (reenvio): não processa de novo.
        if (! $event->wasRecentlyCreated) {
            return response()->json([
                'received' => true,
                'duplicate' => true,
                'lote' => $referencia,
            ]);
        }

        /*
         * Só processa o que foi reconhecido. O resto fica gravado e visível
         * em /admin/webhooks, com status `ignored` para deixar explícito que
         * a decisão foi nossa e não uma falha — é exatamente o que se quer
         * ver enquanto o formato do parceiro ainda está sendo descoberto.
         */
        if ($linhas === []) {
            $event->update([
                'status' => WebhookEvent::STATUS_IGNORED,
                'processed_at' => now(),
            ]);

            return response()->json([
                'received' => true,
                'resgates' => 0,
                'aviso' => 'Corpo recebido e registrado, mas nenhum resgate foi reconhecido.',
                'esperado' => '{"lote":"...","resgates":[{...}]}',
                'campos_recebidos' => $this->camposDoTopo($payload),
            ]);
        }

        ProcessSuvinilWebhook::dispatch($event->id);

        return response()->json([
            'received' => true,
            'lote' => $referencia,
            'resgates' => count($linhas),
        ]);
    }

    /**
     * Os nomes de campo do topo, devolvidos na resposta.
     *
     * É o atalho que evita uma volta inteira de e-mail: quem mandou vê na
     * hora o que chegou do jeito que chegou, sem depender de alguém aqui
     * abrir a tela e responder.
     */
    protected function camposDoTopo(array $payload): array
    {
        return array_slice(array_map('strval', array_keys($payload)), 0, 40);
    }

    /** Aceita `{"resgates":[...]}`, `{"linhas":[...]}` ou a lista pura. */
    protected function linhasDeResgate(array $payload): array
    {
        $linhas = $payload['resgates'] ?? $payload['linhas'] ?? $payload['rows'] ?? null;

        if ($linhas === null && array_is_list($payload)) {
            $linhas = $payload;
        }

        return array_values(array_filter((array) $linhas, 'is_array'));
    }

    private function referenciaDoLote(array $payload, string $corpo): string
    {
        $lote = trim((string) ($payload['lote'] ?? $payload['batch_id'] ?? ''));

        return $lote !== '' ? $lote : hash('sha256', $corpo);
    }
}
