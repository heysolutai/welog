<?php

namespace App\Contracts\Integrations;

use Carbon\CarbonInterface;

/**
 * Contrato da integração com o ERP Omie — fonte da verdade para cadastros de
 * produtos, pedidos de venda e emissão fiscal (ambos os fluxos).
 *
 * A Omie NOTIFICA a WeLog via webhook (pedido de venda incluído/alterado,
 * movimentação de estoque) — ver OmieWebhookController. Este gateway cobre a
 * direção WeLog → Omie: consultas de detalhe disparadas pelos webhooks e as
 * escritas do Fluxo B. Os métodos de listagem servem como reconciliação
 * (fallback) caso algum webhook se perca.
 */
interface OmieGateway
{
    /**
     * Lista Pedidos de Venda incluídos/alterados desde o marco informado.
     * Reconciliação periódica — o gatilho primário do Fluxo A é o webhook.
     *
     * Paginado: quem chama DEVE percorrer até `total_pages`, ou pedidos acima
     * do primeiro lote somem em silêncio (a rede de segurança deixa de existir).
     *
     * `$projectCode` filtra na ORIGEM pelo projeto da Omie. A conta atende a
     * operação inteira — 745 pedidos em dois dias, 15 páginas — e quase nenhum
     * é WeLog: sem o filtro, a varredura baixa tudo para descartar no cliente.
     * Com ele, o mesmo recorte cabe em uma página.
     *
     * @return array{items: array<int, array<string, mixed>>, total_pages: int}
     */
    public function listOrders(?CarbonInterface $since = null, int $page = 1, ?string $projectCode = null): array;

    /**
     * Consulta um Pedido de Venda pelo código Omie.
     */
    public function getOrder(string $omieOrderId): ?array;

    /**
     * Cadastro de Projetos da Omie (`geral/projetos` → `ListarProjetos`).
     *
     * Nada a ver com o Projeto da WeLog: é a classificação que a Santa Origem
     * dá a cada Pedido de Venda ("Suvinil", "Venda", …). É ela que separa o
     * pedido que vira Projeto WeLog do pedido comum — e, ao contrário da
     * marcação escrita à mão em `obs_venda`, vem de uma lista, não da memória
     * de quem digita.
     *
     * Paginado como os demais: quem chama percorre até `total_pages`.
     *
     * @return array{items: array<int, array{codigo: string, nome: string, inativo: bool}>, total_pages: int}
     */
    public function listProjects(int $page = 1): array;

    /**
     * Cria um Pedido de Venda na Omie (Fluxo B — importação appnoz).
     * O payload DEVE conter `codigo_pedido_integracao` (chave de idempotência):
     * reenvios com o mesmo código não geram pedido duplicado.
     *
     * @return array{omie_order_id: string, omie_order_number: string}
     */
    public function createOrder(array $payload): array;

    /**
     * Busca cliente final na Omie pelo CPF/CNPJ.
     */
    public function findCustomerByDocument(string $document): ?array;

    /**
     * Consulta um cliente pelo código Omie, com o retorno normalizado.
     *
     * Necessário porque o `ConsultarPedido` devolve apenas
     * `cabecalho.codigo_cliente` — os dados cadastrais do cliente exigem uma
     * segunda chamada. Sem isso o Projeto nasce sem CNPJ nem razão social.
     *
     * Traz também contato e endereço quando a Omie os tiver — é o que completa
     * o cadastro da empresa cliente sem o operador redigitar.
     *
     * @return array{omie_customer_code: string, document: ?string, name: ?string,
     *               trade_name?: ?string, contact_name?: ?string, contact_email?: ?string,
     *               contact_phone?: ?string, zip?: ?string, street?: ?string, number?: ?string,
     *               complement?: ?string, district?: ?string, city?: ?string, state?: ?string}|null
     */
    public function getCustomer(string $omieCustomerCode): ?array;

    /**
     * Cria (ou atualiza) cadastro de cliente na Omie — usado no auto-cadastro
     * de clientes finais do Fluxo B, sem interromper o processamento.
     *
     * @return array{omie_customer_code: string}
     */
    public function upsertCustomer(array $data): array;

    /**
     * Lista produtos cadastrados na Omie (sync do catálogo, paginado).
     *
     * @return array{items: array<int, array<string, mixed>>, total_pages: int}
     */
    public function listProducts(int $page = 1): array;

    /**
     * Consulta um produto pelo código Omie. Retorna null se não cadastrado
     * (condição bloqueante do Fluxo B).
     */
    public function findProductByCode(string $code): ?array;

    /**
     * Posição de estoque dos produtos informados. Usado para confirmar a
     * disponibilidade sinalizada pelo webhook de estoque (Fluxo A).
     *
     * @param  array<int, string>  $productCodes
     * @return array<string, float> mapa código do produto => saldo disponível
     */
    public function getStockPositions(array $productCodes): array;

    /**
     * Baixa (SAÍDA) de estoque na Omie — ajuste manual via `estoque/ajuste`
     * (call `IncluirAjusteEstoque`, tipo "SAI").
     *
     * NÃO é mais chamado pelo resgate: o estoque do Projeto é controlado na
     * WeLog e a baixa fica lá. O saldo da Omie é do PRODUTO, somado entre
     * todos os projetos que usam o mesmo kit, e mexer nele a cada resgate
     * alterava um número que não era daquele Projeto.
     *
     * O método continua no contrato para ajuste manual e para o Fluxo B, que
     * tem outra dinâmica de estoque. O gateway não deduplica: dois chamados
     * são duas saídas.
     *
     * @param  string  $productCode  código do produto no catálogo Omie
     * @param  float  $quantity  unidades a baixar (sempre positivo)
     * @param  string  $reason  observação que aparece no ajuste, para auditoria
     */
    public function decreaseStock(string $productCode, float $quantity, string $reason): void;

    /**
     * Entrada de ajuste no estoque da Omie — o inverso de `decreaseStock`,
     * usado quando um resgate é cancelado e o saldo baixado precisa voltar.
     * Mesmas ressalvas: não idempotente na Omie, quem deduplica é o chamador.
     *
     * @param  string  $productCode  código do produto no catálogo Omie
     * @param  float  $quantity  unidades a devolver (sempre positivo)
     * @param  string  $reason  observação que aparece no ajuste, para auditoria
     */
    public function increaseStock(string $productCode, float $quantity, string $reason): void;

    /**
     * Lista NFs emitidas desde o marco informado (Fluxo B — insumo do envio
     * diário para a Flash).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(?CarbonInterface $since = null, int $page = 1): array;

    /**
     * A NF emitida para um pedido, pelo ID interno do pedido (ConsultarNF com
     * nIdPedido). Null quando o pedido ainda não foi faturado.
     *
     * @return array{nf_key: string, nf_number: ?string, series: ?string, issued_at: ?CarbonInterface, omie_invoice_id: ?string}|null
     */
    public function findInvoiceByOrder(string $omieOrderId): ?array;

    /**
     * Baixa o XML de uma NF pela chave de acesso.
     */
    public function getInvoiceXml(string $nfKey): ?string;
}
