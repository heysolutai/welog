<?php

namespace App\Contracts\Integrations;

/**
 * Contrato da integração com o Trello — pipeline operacional da CONTRATANTE.
 * A WeLog cria cards e recebe webhooks; é espelho passivo (nunca move cards).
 */
interface TrelloGateway
{
    /**
     * Cria um card no board/lista informados.
     *
     * `$sourceCardId` copia um card MODELO do quadro: o card novo nasce com as
     * etiquetas (e com elas a cor), os checklists, os campos personalizados,
     * os anexos e a capa dele. É o que a plataforma não conseguia reproduzir
     * criando o card do zero — dava para pintar uma etiqueta, não para trazer
     * o checklist de produção que a fábrica usa. O título e a lista continuam
     * sendo os informados aqui.
     *
     * @return array{id: string, url: string}
     */
    public function createCard(string $listId, string $name, string $description = '', ?string $sourceCardId = null): array;

    /**
     * Consulta um card pelo id.
     */
    public function getCard(string $cardId): ?array;

    /**
     * Listas do board (usado na tela de mapeamento lista → status).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getBoardLists(string $boardId): array;

    /**
     * Registra o webhook do board apontando para a URL pública da WeLog.
     *
     * @return array{id: string}
     */
    public function registerWebhook(string $callbackUrl, string $modelId): array;

    /**
     * Garante que o board tem a etiqueta (procura pelo nome; cria se faltar)
     * e devolve o id dela. Usada pela etiqueta de estoque ("Em estoque",
     * amarela) que a operação já usava à mão no quadro.
     */
    public function ensureBoardLabel(string $boardId, string $name, string $color): string;

    /**
     * Põe (`present` true) ou tira (`present` false) a etiqueta do card, de
     * forma idempotente: repetir a mesma operação não é erro.
     */
    public function setCardLabel(string $cardId, string $labelId, bool $present): void;
}
