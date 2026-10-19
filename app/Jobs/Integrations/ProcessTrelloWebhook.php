<?php

namespace App\Jobs\Integrations;

use App\Jobs\Concerns\IntegrationJob;
use App\Models\AppnozImportRow;
use App\Models\Project;
use App\Models\TrelloListMapping;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reflete a movimentação de um card do Trello no Projeto (Fluxo A) ou na
 * linha de importação appnoz (Fluxo B) correspondente.
 */
class ProcessTrelloWebhook implements ShouldQueue
{
    use IntegrationJob, Queueable;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $event = WebhookEvent::findOrFail($this->webhookEventId);

        if ($event->status === WebhookEvent::STATUS_PROCESSED) {
            return;
        }

        try {
            $action = $event->payload['action'] ?? [];
            $cardId = $action['data']['card']['id'] ?? null;
            $listAfterId = $action['data']['listAfter']['id'] ?? null;

            $status = $listAfterId !== null ? TrelloListMapping::statusForList($listAfterId) : null;

            if ($cardId === null || $status === null) {
                Log::info('Webhook Trello ignorado (card/lista não mapeados)', [
                    'webhook_event_id' => $event->id,
                    'card_id' => $cardId,
                    'list_after' => $listAfterId,
                ]);
                $event->markProcessed();

                return;
            }

            $project = Project::where('trello_card_id', $cardId)->first();
            if ($project !== null) {
                $project->update(['pipeline_status' => $status]);
            }

            AppnozImportRow::where('trello_card_id', $cardId)
                ->update(['trello_stage' => $status]);

            $event->markProcessed();
        } catch (Throwable $e) {
            $event->markFailed($e->getMessage());

            throw $e;
        }
    }
}
