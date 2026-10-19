<?php

namespace App\Contracts\Integrations;

/**
 * Contrato da integração com os Correios (Fluxo A).
 * Rastreio via API Rastro (CWS); prepostagem (PPN) condicionada ao contrato
 * do cliente — quando indisponível, o código de rastreio é inserido
 * manualmente (mesmo caminho de MRB/DDM).
 */
interface CorreiosGateway
{
    /**
     * Cria uma prepostagem e devolve o código de rastreio gerado.
     *
     * @return array{tracking_code: string}
     */
    public function createPrePostagem(array $payload): array;

    /**
     * Eventos de rastreio de um objeto.
     *
     * @return array<int, array{occurred_at: string, status: string, description: string, location: ?string}>
     */
    public function track(string $trackingCode): array;
}
