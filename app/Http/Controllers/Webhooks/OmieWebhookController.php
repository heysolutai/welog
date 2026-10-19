<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\Omie\OmieAccounts;
use App\Jobs\FluxoB\ProcessOmieInvoiceWebhook;
use App\Jobs\Integrations\EvaluateOmieStock;
use App\Jobs\Integrations\ProcessOmieWebhook;
use App\Models\AppnozImportRow;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe as notificações da Omie (Pedido de Venda incluído/alterado,
 * movimentação de estoque) — gatilho primário do Fluxo A.
 *
 * A Omie não assina os webhooks; a verificação é feita pelo token secreto
 * embutido na URL registrada no painel da Omie (/webhooks/omie/{token}).
 * O processamento pesado acontece de forma assíncrona no job — aqui apenas
 * persistimos o evento (idempotente por messageId) e respondemos 200 rápido,
 * para a Omie não reenviar por timeout.
 */
class OmieWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, OmieAccounts $accounts): JsonResponse
    {
        /*
         * O token diz QUAL conta (CNPJ) da Omie mandou o evento.
         *
         * Cada aplicativo Omie registra a própria URL /webhooks/omie/{token};
         * guardar a conta aqui é o que permite, depois, consultar o pedido com
         * a chave certa — a chave de um CNPJ não enxerga o pedido do outro.
         */
        $account = $accounts->accountForToken($token);

        if ($account === null) {
            /*
             * Recusa deixa rastro.
             *
             * Sem este log, um webhook registrado com o token errado (ou de uma
             * conta que ainda não está no .env) some sem deixar linha nenhuma:
             * o operador vê "não chegou nada" e não tem como distinguir isso de
             * "a Omie nunca chamou". Só o começo do token vai para o log.
             */
            Log::channel('integrations')->warning('Webhook Omie RECUSADO: token não confere.', [
                'token_recebido' => substr($token, 0, 6).'…',
                'contas_configuradas' => $accounts->names(),
                'topic' => $request->json('topic'),
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid webhook token.');
        }

        $payload = $request->json()->all();

        // Omie envia "ping" de verificação ao cadastrar a URL do webhook
        if (($payload['ping'] ?? null) === 'omie') {
            return response()->json(['pong' => 'omie']);
        }

        $topic = $payload['topic'] ?? 'unknown';

        /*
         * A `appKey` do corpo manda mais que o token da URL.
         *
         * O token autoriza; quem o registrou no painel foi uma pessoa, e uma
         * conta pode acabar registrada com a URL da outra — aí o token diz
         * "Webox" para um evento que saiu da Santa, e a consulta seguinte usa
         * a chave errada e não acha o pedido. A `appKey` vem da própria Omie,
         * dentro do evento, e não depende de configuração nossa.
         *
         * Chave desconhecida não derruba nada: o evento é legítimo (o token
         * conferiu) e o que vale continua sendo a conta do token.
         */
        $porAppKey = $accounts->accountForAppKey($payload['appKey'] ?? null);

        if ($porAppKey !== null && $porAppKey !== $account) {
            Log::channel('integrations')->warning('Webhook Omie: o token aponta uma conta e a appKey outra — vale a appKey.', [
                'conta_do_token' => $account,
                'conta_da_appkey' => $porAppKey,
                'topic' => $topic,
            ]);

            $account = $porAppKey;
        }

        $event = WebhookEvent::firstOrCreate(
            [
                'source' => WebhookEvent::SOURCE_OMIE,
                // Sem messageId (não deveria ocorrer), cai no hash do corpo
                'external_event_id' => $payload['messageId'] ?? hash('sha256', $request->getContent()),
            ],
            [
                'omie_account' => $account,
                'topic' => $topic,
                'payload' => $payload,
            ],
        );

        // Evento já conhecido (reentrega da Omie): não despacha de novo
        if (! $event->wasRecentlyCreated) {
            return response()->json(['received' => true]);
        }

        /*
         * Só entra na fila o que temos o que fazer com.
         *
         * A Omie notifica dezenas de tópicos (financeiro, NF, cadastro de
         * cliente, produto…) e tratamos dois. Antes, TODO evento virava job
         * para o processamento concluir que não havia nada a fazer — numa
         * conta em operação real isso é a maior parte do volume da fila.
         *
         * O evento continua gravado: o log em /admin/webhooks segue completo,
         * e o status `ignored` deixa explícito que a decisão foi nossa, não
         * uma falha.
         */
        match (true) {
            str_starts_with($topic, 'VendaProduto.') => ProcessOmieWebhook::dispatch($event->id),

            // Estoque não vai para o processamento individual: a rajada
            // inteira é coalescida num único job (ver EvaluateOmieStock).
            str_starts_with($topic, 'Estoque.') => $this->scheduleStockEvaluation($event),

            // Nota de um resgate (Importações Parceiros): vira "NF emitida". A
            // conta emite nota da operação inteira; a dos outros pedidos fica
            // no ignorado, sem ocupar a fila.
            $topic === 'NFe.NotaAutorizada' && $this->eNotaDeResgate($payload) => ProcessOmieInvoiceWebhook::dispatch($event->id),

            default => $event->update([
                'status' => WebhookEvent::STATUS_IGNORED,
                'processed_at' => now(),
            ]),
        };

        return response()->json(['received' => true]);
    }

    /** A nota é de um pedido criado por um resgate? O `id_pedido` é o ID interno. */
    private function eNotaDeResgate(array $payload): bool
    {
        $pedido = trim((string) ($payload['event']['id_pedido'] ?? ''));

        return $pedido !== '' && AppnozImportRow::where('omie_order_id', $pedido)->exists();
    }

    /**
     * Marca o evento como tratado e agenda UMA reavaliação para a rajada.
     *
     * O atraso dá tempo de a rajada terminar: numa importação de nota, a Omie
     * dispara dezenas de movimentações em segundos, e todas cabem na mesma
     * janela de unicidade do job.
     */
    private function scheduleStockEvaluation(WebhookEvent $event): void
    {
        EvaluateOmieStock::dispatch()->delay(now()->addSeconds(20));

        $event->update([
            'status' => WebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
    }
}
