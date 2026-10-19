<?php

namespace App\Contracts\Integrations;

/**
 * Flash Courier (hoje servida pela Bridge Link): importação de encomendas e
 * rastreio.
 */
interface FlashGateway
{
    /**
     * Consulta o rastreio de uma encomenda.
     *
     * `$reference` é o número da encomenda combinado com a Flash
     * (`integrations.flash.tracking_reference`: WEBOX + nº da NF, chave da NF
     * ou nº do pedido).
     *
     * @return array{tracking_code: ?string, events: array<int, array{occurred_at: string, status: string, description: string, location?: ?string}>}|null
     */
    public function getTracking(string $reference): ?array;

    /**
     * Rastreio de várias encomendas numa consulta, por referência.
     *
     * @param  array<int, string>  $references
     * @return array<string, array<string, mixed>|null>
     */
    public function getTrackingMany(array $references): array;

    /**
     * Importa encomendas na transportadora, em lote.
     *
     * Cada item já vem no formato da importação Flash (`num_enc_cli`,
     * `endHawbs`, `chave_nf`…); conta, contrato, centro de custo e remetente
     * são do gateway. Reenviar é seguro: `num_enc_cli` já importado é
     * ignorado do lado de lá.
     *
     * @param  array<int, array<string, mixed>>  $encomendas
     * @return array{mensagem: ?string, arquivo: ?string}
     *
     * @throws \RuntimeException quando o lote é recusado (credencial, contrato, nenhuma criada)
     */
    public function importShipments(array $encomendas): array;

    /**
     * O corpo exato que `importShipments` manda para estas encomendas, com
     * conta, contrato, centro de custo e remetente: o mesmo texto, para a
     * tela do resgate mostrar o que vai (ou foi) para a transportadora.
     *
     * @param  array<int, array<string, mixed>>  $encomendas
     */
    public function corpoDaImportacao(array $encomendas): string;
}
