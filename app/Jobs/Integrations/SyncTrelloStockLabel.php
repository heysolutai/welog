<?php

namespace App\Jobs\Integrations;

use App\Contracts\Integrations\TrelloGateway;
use App\Jobs\Concerns\IntegrationJob;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Etiqueta de estoque no card do Trello — a convenção que a operação já usava
 * à mão no quadro: etiqueta amarela "Em estoque" quando o kit existe, nenhuma
 * quando não existe. A plataforma passa a mantê-la sozinha.
 *
 * A regra é a mesma do portal (`Project::STOCKED_STATUSES`): o kit existe em
 * "Estoque" e em "Envio"; antes disso, e em "Concluído", a etiqueta sai. O
 * estado desejado é calculado NA EXECUÇÃO, não no enfileiramento — se a etapa
 * mudar duas vezes antes de o worker rodar, vale a última.
 *
 * É a única escrita da WeLog em cards existentes: etiqueta, nunca mover card
 * de lista — a lista continua sendo o território da operação.
 */
class SyncTrelloStockLabel implements ShouldQueue
{
    use IntegrationJob, Queueable;

    public function __construct(private readonly int $projectId)
    {
        $this->onQueue('integrations');
    }

    public function handle(TrelloGateway $trello): void
    {
        $project = Project::find($this->projectId);

        if ($project === null || $project->trello_card_id === null) {
            return;
        }

        // Card do driver fake numa conta REAL: o Trello devolveria 404 e
        // queimaria retentativas à toa. No driver fake, o id fake é legítimo.
        if (config('integrations.trello.driver') === 'api'
            && str_starts_with($project->trello_card_id, 'fake-card-')) {
            return;
        }

        $boardId = (string) config('integrations.trello.board_id');

        if ($boardId === '') {
            return;
        }

        // O id da etiqueta é imutável no quadro: resolver uma vez por dia
        // evita um GET /labels a cada mudança de etapa.
        $labelId = Cache::remember(
            "trello:stock-label:{$boardId}",
            86400,
            fn () => $trello->ensureBoardLabel(
                $boardId,
                (string) config('integrations.trello.stock_label_name', 'Em estoque'),
                (string) config('integrations.trello.stock_label_color', 'yellow'),
            ),
        );

        $trello->setCardLabel($project->trello_card_id, $labelId, $project->isInStock());
    }
}
