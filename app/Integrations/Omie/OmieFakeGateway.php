<?php

namespace App\Integrations\Omie;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Support\FakeState;
use App\Integrations\Support\OmieDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Simulação da Omie para desenvolvimento/testes (driver 'fake').
 *
 * Comportamento modelado:
 *  - Catálogo fixo de produtos (kits corporativos + linha Suvinil);
 *  - createOrder idempotente por codigo_pedido_integracao, e a "emissão" da
 *    NF acontece junto (insumo do despacho Flash às 19h no Fluxo B);
 *  - Clientes criados via upsertCustomer ficam consultáveis por CPF/CNPJ.
 */
class OmieFakeGateway implements OmieGateway
{
    private FakeState $state;

    /** Catálogo "cadastrado na Omie" — códigos usados nos seeds e fixtures. */
    public const CATALOG = [
        ['omie_code' => 'KIT-ONBOARD-01', 'name' => 'Kit Onboarding Premium', 'is_kit' => true],
        ['omie_code' => 'KIT-NATAL-01', 'name' => 'Kit Natal Corporativo', 'is_kit' => true],
        ['omie_code' => 'CANECA-350', 'name' => 'Caneca Personalizada 350ml', 'is_kit' => false],
        ['omie_code' => 'CAMISETA-M', 'name' => 'Camiseta Personalizada M', 'is_kit' => false],
        ['omie_code' => 'MOCHILA-15', 'name' => 'Mochila para Notebook 15"', 'is_kit' => false],
        ['omie_code' => 'SUV-PINCEL-01', 'name' => 'Kit Pincéis Profissional Suvinil', 'is_kit' => false],
        ['omie_code' => 'SUV-ROLO-23', 'name' => 'Rolo de Lã 23cm Antirrespingo', 'is_kit' => false],
        ['omie_code' => 'SUV-BANDEJA-01', 'name' => 'Bandeja para Pintura com Grade', 'is_kit' => false],
    ];

    public const KIT_BOM = [
        'KIT-ONBOARD-01' => ['CANECA-350' => 1, 'CAMISETA-M' => 1, 'MOCHILA-15' => 1],
        'KIT-NATAL-01' => ['CANECA-350' => 2, 'CAMISETA-M' => 1],
    ];

    public function __construct()
    {
        $this->state = new FakeState('omie');
    }

    /** Paginado como a API real, para que o consumidor exercite o laço de páginas. */
    public function listOrders(?CarbonInterface $since = null, int $page = 1, ?string $projectCode = null): array
    {
        $orders = array_values($this->state->get()['orders'] ?? []);

        if ($since !== null) {
            $orders = array_values(array_filter(
                $orders,
                fn (array $o) => Carbon::parse($o['created_at'])->gte($since),
            ));
        }

        // Como o `filtrar_por_projeto` do real: quem filtra é a origem, e o
        // consumidor recebe só o recorte. Sem isso o fake devolveria pedido de
        // outro projeto e esconderia um erro de filtro em produção.
        if ($projectCode !== null && $projectCode !== '') {
            $orders = array_values(array_filter(
                $orders,
                fn (array $o) => (string) ($o['informacoes_adicionais']['codProj'] ?? '') === $projectCode,
            ));
        }

        $perPage = 100;

        return [
            'items' => array_slice($orders, ($page - 1) * $perPage, $perPage),
            'total_pages' => max(1, (int) ceil(count($orders) / $perPage)),
        ];
    }

    /**
     * Como o `ConsultarPedido` real: o pedido devolve apenas
     * `cabecalho.codigo_cliente`, NÃO os dados cadastrais do cliente. Quem
     * precisa de CNPJ/razão social chama `getCustomer`.
     *
     * O fake devolvia o bloco `cliente` que o teste tinha enviado no
     * createOrder, escondendo em produção um Projeto sem empresa identificada.
     */
    public function getOrder(string $omieOrderId): ?array
    {
        $order = $this->state->get()['orders'][$omieOrderId] ?? null;

        if ($order === null) {
            return null;
        }

        unset($order['cliente']);

        return $order;
    }

    /**
     * Os dois projetos que existem na conta real da Santa Origem, com os
     * códigos de verdade: é o que permite ao teste exercitar o filtro sem
     * inventar identificadores que não existiriam em produção.
     */
    public function listProjects(int $page = 1): array
    {
        $cadastro = $this->state->get()['projects'] ?? [
            ['codigo' => '7407717220', 'nome' => 'Venda', 'inativo' => false],
            ['codigo' => '7386090746', 'nome' => 'Suvinil', 'inativo' => false],
        ];

        return ['items' => $page === 1 ? $cadastro : [], 'total_pages' => 1];
    }

    public function createOrder(array $payload): array
    {
        return $this->state->update(function (array &$state) use ($payload) {
            $integrationKey = $payload['codigo_pedido_integracao']
                ?? throw new \InvalidArgumentException('codigo_pedido_integracao é obrigatório.');

            // Idempotência: mesmo código de integração devolve o mesmo pedido
            foreach ($state['orders'] ?? [] as $order) {
                if (($order['codigo_pedido_integracao'] ?? null) === $integrationKey) {
                    return [
                        'omie_order_id' => $order['omie_order_id'],
                        'omie_order_number' => $order['omie_order_number'],
                    ];
                }
            }

            $seq = ($state['order_seq'] ?? 0) + 1;
            $state['order_seq'] = $seq;
            $orderId = (string) (900000 + $seq);
            $orderNumber = 'PV-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

            // O cliente enviado no pedido vira cadastro consultável por código,
            // como acontece na Omie de verdade.
            $customerCode = $payload['cabecalho']['codigo_cliente'] ?? null;
            if ($customerCode !== null && isset($payload['cliente'])) {
                $cliente = $payload['cliente'];

                // Mesmo formato normalizado do driver `api`, incluindo contato
                // e endereço — é o que completa o cadastro da empresa.
                $state['customers_by_code'][(string) $customerCode] = [
                    'omie_customer_code' => (string) $customerCode,
                    'document' => $cliente['cnpj'] ?? $cliente['cnpj_cpf'] ?? null,
                    'name' => $cliente['razao_social'] ?? null,
                    'trade_name' => $cliente['nome_fantasia'] ?? null,
                    'contact_name' => $cliente['contato'] ?? null,
                    'contact_email' => $cliente['email'] ?? null,
                    'contact_phone' => $cliente['telefone'] ?? null,
                    'zip' => $cliente['cep'] ?? null,
                    'street' => $cliente['endereco'] ?? null,
                    'number' => $cliente['endereco_numero'] ?? null,
                    'complement' => $cliente['complemento'] ?? null,
                    'district' => $cliente['bairro'] ?? null,
                    'city' => $cliente['cidade'] ?? null,
                    'state' => $cliente['estado'] ?? null,
                ];
            }

            $state['orders'][$orderId] = array_merge($payload, [
                'omie_order_id' => $orderId,
                'omie_order_number' => $orderNumber,
                'created_at' => now()->toIso8601String(),
                // Como no `ConsultarPedido` real: a Omie carimba a data de
                // inclusão em `infoCadastro.dInc`, no formato dd/mm/aaaa (o
                // mesmo pt-BR de `data_previsao`, documentado em OmieDate). É
                // daqui que o Projeto tira o `ordered_at` ("Data da compra").
                'infoCadastro' => ($payload['infoCadastro'] ?? []) + [
                    'dInc' => OmieDate::forFilter(now()),
                ],
            ]);

            // A Omie emite a NF; no fake, emitimos junto com o pedido
            // 44 dígitos no layout de uma chave real (UF, AAMM, CNPJ, modelo,
            // série, nº da NF…), com o id do pedido no lugar do número da NF:
            // o rastreio da Flash procura por ele (WEBOX + nº da NF).
            $nfKey = '35'.now()->format('ym').'12345678000190'.'55'.'001'
                .str_pad(substr($orderId, -9), 9, '0', STR_PAD_LEFT)
                .'1'.str_pad(substr($orderId, -8), 8, '0', STR_PAD_LEFT).'0';
            $state['invoices'][$nfKey] = [
                'nf_key' => $nfKey,
                'omie_order_id' => $orderId,
                'issued_at' => now()->toIso8601String(),
                'xml' => '<?xml version="1.0"?><nfeProc><infNFe Id="NFe'.$nfKey.'">'
                    .'<total>1</total></infNFe></nfeProc>',
            ];

            return ['omie_order_id' => $orderId, 'omie_order_number' => $orderNumber];
        });
    }

    public function findCustomerByDocument(string $document): ?array
    {
        return $this->state->get()['customers'][$document] ?? null;
    }

    public function getCustomer(string $omieCustomerCode): ?array
    {
        return $this->state->get()['customers_by_code'][$omieCustomerCode] ?? null;
    }

    public function upsertCustomer(array $data): array
    {
        return $this->state->update(function (array &$state) use ($data) {
            $document = $data['cnpj_cpf'] ?? throw new \InvalidArgumentException('cnpj_cpf é obrigatório.');

            // A mesma exigência da Omie real: sem uma das duas chaves, o
            // UpsertCliente é recusado. O fake aceitava, e foi assim que o
            // cadastro de cliente novo passou nos testes e falhou em produção.
            if (blank($data['codigo_cliente_omie'] ?? null) && blank($data['codigo_cliente_integracao'] ?? null)) {
                throw new OmieApiException('Omie UpsertCliente falhou: ERROR: O preenchimento das tags '
                    .'[codigo_cliente_omie] ou [codigo_cliente_integracao] é obrigatório!');
            }

            $code = $state['customers'][$document]['omie_customer_code']
                ?? 'CLI-'.str_pad((string) (count($state['customers'] ?? []) + 1), 5, '0', STR_PAD_LEFT);

            $state['customers'][$document] = array_merge($data, ['omie_customer_code' => $code]);

            return ['omie_customer_code' => $code];
        });
    }

    public function listProducts(int $page = 1): array
    {
        return ['items' => array_map($this->withBom(...), self::CATALOG), 'total_pages' => 1];
    }

    public function findProductByCode(string $code): ?array
    {
        foreach ([...self::CATALOG, ...($this->state->get()['catalog_extra'] ?? [])] as $product) {
            if ($product['omie_code'] === $code) {
                return $this->withBom($product);
            }
        }

        return null;
    }

    /** Anexa a estrutura BOM (componente => quantidade) quando o produto é kit. */
    private function withBom(array $product): array
    {
        $product['components'] = self::KIT_BOM[$product['omie_code']] ?? [];

        return $product;
    }

    public function getStockPositions(array $productCodes): array
    {
        $overrides = $this->state->get()['stock'] ?? [];

        $positions = [];
        foreach ($productCodes as $code) {
            // Sem override configurado, o fake responde estoque farto
            $positions[$code] = (float) ($overrides[$code] ?? 1000);
        }

        return $positions;
    }

    /**
     * Como o `IncluirAjusteEstoque` real: registra a saída e o saldo cai. O
     * lançamento fica no estado (`stock_adjustments`) para o teste conferir
     * que a baixa chegou "na Omie", não só no espelho local.
     */
    public function decreaseStock(string $productCode, float $quantity, string $reason): void
    {
        $this->state->update(function (array &$state) use ($productCode, $quantity, $reason) {
            $current = (float) ($state['stock'][$productCode] ?? 1000);
            $state['stock'][$productCode] = $current - $quantity;

            $state['stock_adjustments'][] = [
                'product_code' => $productCode,
                'quantity' => $quantity,
                'tipo' => 'SAI',
                'obs' => $reason,
                'at' => now()->toIso8601String(),
            ];

            return null;
        });
    }

    /** Como na Omie real: entrada de ajuste devolvendo saldo cancelado. */
    public function increaseStock(string $productCode, float $quantity, string $reason): void
    {
        $this->state->update(function (array &$state) use ($productCode, $quantity, $reason) {
            $current = (float) ($state['stock'][$productCode] ?? 1000);
            $state['stock'][$productCode] = $current + $quantity;

            $state['stock_adjustments'][] = [
                'product_code' => $productCode,
                'quantity' => $quantity,
                'tipo' => 'ENT',
                'obs' => $reason,
                'at' => now()->toIso8601String(),
            ];

            return null;
        });
    }

    /** Helper de teste: os ajustes (saídas e entradas) registrados. */
    public function stockAdjustments(): array
    {
        return $this->state->get()['stock_adjustments'] ?? [];
    }

    public function listInvoices(?CarbonInterface $since = null, int $page = 1): array
    {
        // Tudo cabe na primeira página; depois dela, nada — como a Omie.
        if ($page > 1) {
            return [];
        }

        $invoices = array_values($this->state->get()['invoices'] ?? []);

        if ($since !== null) {
            $invoices = array_values(array_filter(
                $invoices,
                fn (array $i) => Carbon::parse($i['issued_at'])->gte($since),
            ));
        }

        return array_map(fn (array $i) => collect($i)->except('xml')->all(), $invoices);
    }

    public function findInvoiceByOrder(string $omieOrderId): ?array
    {
        foreach ($this->state->get()['invoices'] ?? [] as $nf) {
            if ((string) ($nf['omie_order_id'] ?? '') === $omieOrderId) {
                return [
                    'nf_key' => $nf['nf_key'],
                    'nf_number' => ltrim(substr($nf['nf_key'], 25, 9), '0') ?: null,
                    'series' => '001',
                    'issued_at' => isset($nf['issued_at']) ? Carbon::parse($nf['issued_at']) : null,
                    'omie_invoice_id' => null,
                ];
            }
        }

        return null;
    }

    public function getInvoiceXml(string $nfKey): ?string
    {
        return $this->state->get()['invoices'][$nfKey]['xml'] ?? null;
    }

    /** Helper de dev/teste: cadastra um produto além do CATALOG fixo (ex.: uma grade). */
    public function addProduct(array $product): void
    {
        $this->state->update(function (array &$state) use ($product) {
            $state['catalog_extra'][] = $product + ['is_kit' => false];

            return null;
        });
    }

    /** Helper de dev/teste: define o saldo de um produto no fake. */
    public function setStock(string $productCode, float $qty): void
    {
        $this->state->update(function (array &$state) use ($productCode, $qty) {
            $state['stock'][$productCode] = $qty;

            return null;
        });
    }
}
