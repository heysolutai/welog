<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Integrations\ProcessTrelloWebhook;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe os webhooks do Trello a cada mudança de estágio dos cards.
 * A WeLog é espelho passivo: só reflete o pipeline, nunca move cards.
 *
 * O Trello valida a URL com um HEAD/GET no registro do webhook (responder
 * 200) e assina os POSTs com HMAC-SHA1 (base64(body + callbackURL)) no
 * header X-Trello-Webhook quando há secret configurado.
 */
class TrelloWebhookController extends Controller
{
    public function verify(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $payload = $request->json()->all();
        $action = $payload['action'] ?? [];

        // Só interessa movimentação de card entre listas
        if (($action['type'] ?? null) !== 'updateCard'
            || ! isset($action['data']['listAfter'])) {
            return response()->json(['ignored' => true]);
        }

        $event = WebhookEvent::firstOrCreate(
            [
                'source' => WebhookEvent::SOURCE_TRELLO,
                'external_event_id' => $action['id'] ?? hash('sha256', $request->getContent()),
            ],
            [
                'topic' => 'card.moved',
                'payload' => $payload,
            ],
        );

        if ($event->wasRecentlyCreated) {
            ProcessTrelloWebhook::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }

    private function verifySignature(Request $request): void
    {
        $secret = config('integrations.trello.secret');

        if (empty($secret)) {
            // Sem secret não há como validar. Em dev/teste seguimos (o fake não
            // assina); fora disso, aceitar seria deixar qualquer um mover o
            // pipeline dos Projetos por um POST.
            abort_unless(app()->environment(['local', 'testing']), 403, 'Trello signature secret not configured.');

            return;
        }

        $callbackUrl = config('integrations.trello.webhook_callback_url') ?: $request->fullUrl();
        $expected = base64_encode(hash_hmac('sha1', $request->getContent().$callbackUrl, $secret, true));

        if (! hash_equals($expected, $request->header('X-Trello-Webhook', ''))) {
            abort(403, 'Invalid Trello signature.');
        }
    }
}
