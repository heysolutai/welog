<?php

namespace App\Integrations\Trello;

use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Support\FakeState;

/**
 * Simulação do Trello (driver 'fake'). As listas espelham o pipeline
 * operacional descrito no Functional Design.
 */
class TrelloFakeGateway implements TrelloGateway
{
    public const LISTS = [
        ['id' => 'list-design', 'name' => 'Design'],
        ['id' => 'list-production', 'name' => 'Produção'],
        ['id' => 'list-picking', 'name' => 'Picking'],
        ['id' => 'list-packing', 'name' => 'Packing'],
        ['id' => 'list-invoice', 'name' => 'Emissão de NF'],
        ['id' => 'list-done', 'name' => 'Concluído'],
    ];

    private FakeState $state;

    public function __construct()
    {
        $this->state = new FakeState('trello');
    }

    public function createCard(string $listId, string $name, string $description = '', ?string $sourceCardId = null): array
    {
        return $this->state->update(function (array &$state) use ($listId, $name, $description, $sourceCardId) {
            $seq = ($state['card_seq'] ?? 0) + 1;
            $state['card_seq'] = $seq;
            $id = "fake-card-{$seq}";

            // Como o `keepFromSource` real: checklists, campos personalizados
            // e anexos vêm do modelo; título, lista e descrição são os desta
            // chamada. Etiqueta NÃO: as do modelo marcam o modelo, e quem
            // pinta a do tipo é a plataforma.
            $modelo = $sourceCardId === null
                ? []
                : (self::acharCard($state['cards'] ?? [], $sourceCardId) ?? []);

            $state['cards'][$id] = [
                'id' => $id,
                'idList' => $listId,
                'name' => $name,
                'desc' => $description,
                'url' => "https://trello.com/c/fake/{$seq}",
                'idLabels' => [],
                'checklists' => $modelo['checklists'] ?? [],
                'customFields' => $modelo['customFields'] ?? [],
                'copiadoDe' => $sourceCardId,
            ];

            return ['id' => $id, 'url' => $state['cards'][$id]['url']];
        });
    }

    public function getCard(string $cardId): ?array
    {
        return self::acharCard($this->state->get()['cards'] ?? [], $cardId);
    }

    /**
     * Como o Trello: o card responde tanto pelo id longo quanto pelo código
     * curto da URL. É o que permite configurar um modelo pelo link do
     * navegador e ainda assim copiá-lo pelo id, que é o que o `idCardSource`
     * exige.
     *
     * @param  array<string, array<string, mixed>>  $cards
     */
    private static function acharCard(array $cards, string $identificador): ?array
    {
        if (isset($cards[$identificador])) {
            return $cards[$identificador];
        }

        foreach ($cards as $card) {
            if (($card['id'] ?? null) === $identificador) {
                return $card;
            }
        }

        return null;
    }

    public function getBoardLists(string $boardId): array
    {
        return self::LISTS;
    }

    public function registerWebhook(string $callbackUrl, string $modelId): array
    {
        return $this->state->update(function (array &$state) use ($callbackUrl, $modelId) {
            $id = 'fake-webhook-'.(count($state['webhooks'] ?? []) + 1);
            $state['webhooks'][$id] = ['callbackUrl' => $callbackUrl, 'idModel' => $modelId];

            return ['id' => $id];
        });
    }

    /**
     * Helper de teste: põe um card pronto no quadro simulado — o card MODELO
     * que a cópia usa como fonte.
     */
    public function seedCard(string $id, array $card): void
    {
        $this->state->update(function (array &$state) use ($id, $card) {
            $state['cards'][$id] = $card + ['id' => $id, 'url' => "https://trello.com/c/{$id}"];

            return null;
        });
    }

    /**
     * Helper de teste: etiquetas do quadro simulado, por id.
     *
     * @return array<string, array{name: string, color: string}>
     */
    public function labels(): array
    {
        return $this->state->get()['labels'] ?? [];
    }

    public function ensureBoardLabel(string $boardId, string $name, string $color): string
    {
        return $this->state->update(function (array &$state) use ($name, $color) {
            // Nome E cor, como no real: "SEM Estoque" existe em azul e em
            // verde (Bosch), e são etiquetas diferentes no quadro.
            foreach ($state['labels'] ?? [] as $id => $label) {
                if (mb_strtolower($label['name']) === mb_strtolower($name) && $label['color'] === $color) {
                    return $id;
                }
            }

            $id = 'fake-label-'.(count($state['labels'] ?? []) + 1);
            $state['labels'][$id] = ['name' => $name, 'color' => $color];

            return $id;
        });
    }

    public function setCardLabel(string $cardId, string $labelId, bool $present): void
    {
        $this->state->update(function (array &$state) use ($cardId, $labelId, $present) {
            if (! isset($state['cards'][$cardId])) {
                return null;
            }

            $atuais = $state['cards'][$cardId]['idLabels'] ?? [];

            $state['cards'][$cardId]['idLabels'] = $present
                ? array_values(array_unique([...$atuais, $labelId]))
                : array_values(array_diff($atuais, [$labelId]));

            return null;
        });
    }
}
