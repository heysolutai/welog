<?php

namespace App\Integrations\Trello;

use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Support\ResilientHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * Cliente REST do Trello (driver 'api') — https://api.trello.com/1.
 * Autenticação por key/token em query string, padrão da API do Trello.
 */
class TrelloApiGateway implements TrelloGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $key,
        private readonly string $token,
    ) {}

    private function client(): PendingRequest
    {
        return ResilientHttp::for('trello', $this->baseUrl, timeout: 15)
            ->withQueryParameters(['key' => $this->key, 'token' => $this->token]);
    }

    public function createCard(string $listId, string $name, string $description = '', ?string $sourceCardId = null): array
    {
        $param = [
            'idList' => $listId,
            'name' => $name,
            'desc' => $description,
        ];

        if ($sourceCardId !== null && $sourceCardId !== '') {
            $param['idCardSource'] = $sourceCardId;
            /*
             * O que vem do modelo.
             *
             * `due`/`start` ficam de fora de propósito: a data do card modelo é
             * do dia em que ele foi desenhado e viraria um prazo vencido em
             * todo card novo. Comentários também não — conversa do modelo não é
             * conversa desta campanha.
             *
             * `labels` saiu depois de ver o resultado no quadro: as etiquetas
             * do modelo são as que marcam o MODELO ("Projeto COM estoque no
             * WeLog"), e copiá-las fazia todo card do pipeline parecer um
             * template. A etiqueta do tipo quem pinta é a plataforma.
             */
            $param['keepFromSource'] = 'attachments,checklists,customFields,members,stickers';
        }

        $response = $this->client()->post('/cards', $param)->throw()->json();

        return ['id' => $response['id'], 'url' => $response['url']];
    }

    public function getCard(string $cardId): ?array
    {
        $response = $this->client()->get("/cards/{$cardId}");

        return $response->status() === 404 ? null : $response->throw()->json();
    }

    public function getBoardLists(string $boardId): array
    {
        $lists = $this->client()->get("/boards/{$boardId}/lists")->throw()->json();

        return array_map(fn (array $l) => ['id' => $l['id'], 'name' => $l['name']], $lists);
    }

    public function registerWebhook(string $callbackUrl, string $modelId): array
    {
        $response = $this->client()->post('/webhooks', [
            'callbackURL' => $callbackUrl,
            'idModel' => $modelId,
            'description' => 'WeLog pipeline mirror',
        ])->throw()->json();

        return ['id' => $response['id']];
    }

    public function ensureBoardLabel(string $boardId, string $name, string $color): string
    {
        $labels = $this->client()->get("/boards/{$boardId}/labels")->throw()->json();

        foreach ($labels as $label) {
            // Nome E cor: o quadro tem "SEM Estoque" em azul (campanha comum) e
            // em verde (Bosch). Casar só pelo nome devolveria a azul para os
            // dois, e o card do Bosch nasceria da cor errada.
            if (mb_strtolower((string) ($label['name'] ?? '')) === mb_strtolower($name)
                && (string) ($label['color'] ?? '') === $color) {
                return (string) $label['id'];
            }
        }

        $created = $this->client()->post('/labels', [
            'idBoard' => $boardId,
            'name' => $name,
            'color' => $color,
        ])->throw()->json();

        return (string) $created['id'];
    }

    public function setCardLabel(string $cardId, string $labelId, bool $present): void
    {
        if ($present) {
            $response = $this->client()->post("/cards/{$cardId}/idLabels", ['value' => $labelId]);

            // "that label is already on the card" — repetição não é erro.
            if ($response->failed() && ! str_contains(mb_strtolower($response->body()), 'already on the card')) {
                $response->throw();
            }

            return;
        }

        $response = $this->client()->delete("/cards/{$cardId}/idLabels/{$labelId}");

        // 404 = a etiqueta já não estava lá; o estado desejado já vale.
        if ($response->failed() && $response->status() !== 404) {
            $response->throw();
        }
    }
}
