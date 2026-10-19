<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieApiGateway;
use App\Integrations\Support\ApiThrottle;
use App\Integrations\Support\ApiThrottleException;
use App\Jobs\Integrations\ReconcileOmieOrders;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\WebhookEvent;
use App\Services\FluxoA\CreateProjectFromOmieOrder;
use App\Services\FluxoA\HandleOmieStockSignal;
use App\Services\FluxoA\OmieProjectMarker;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contratos do Fluxo A contra o formato REAL das APIs (não o do gateway fake).
 * A revisão de 25/07 apontou a divergência fake × api como o risco dominante:
 * a suíte passava e a produção quebraria.
 */
class FluxoAContratosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // trello_list_mappings: sem o mapeamento de "design" a criação do card
        // (etapa seguinte do Fluxo A) aborta o Projeto.
        $this->seed();
    }

    private function apiGateway(): OmieApiGateway
    {
        return new OmieApiGateway('https://app.omie.com.br/api/v1', 'key', 'secret');
    }

    // ------------------------------------------------------------------
    // Pedido → Projeto
    // ------------------------------------------------------------------

    public function test_prazo_de_entrega_da_omie_nao_troca_dia_por_mes(): void
    {
        Http::fake(['*' => Http::response([
            'pedido_venda_produto' => [
                'cabecalho' => [
                    'numero_pedido' => 'PV-12345',
                    'codigo_cliente' => 'CLI-1',
                    'data_previsao' => '05/12/2026',   // formato real: dd/mm/aaaa
                ],
                'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME Ltda'],
                'det' => [['produto' => ['codigo' => 'KIT-ONBOARD-01', 'quantidade' => 10]]],
            ],
        ])]);

        $project = app(CreateProjectFromOmieOrder::class, ['omie' => $this->apiGateway()])
            ->handle('900001');

        $this->assertNotNull($project);
        $this->assertSame('2026-12-05', $project->delivery_deadline->toDateString());
    }

    /**
     * O ConsultarPedido real NÃO traz o bloco `cliente` — só
     * `cabecalho.codigo_cliente`. O fake trazia (porque o teste o enviava no
     * createOrder), e por isso toda empresa virava "Cliente desconhecido" em
     * produção.
     */
    public function test_empresa_e_resolvida_pelo_codigo_quando_o_pedido_nao_traz_o_cliente(): void
    {
        Http::fake([
            '*produtos/pedido*' => Http::response([
                'pedido_venda_produto' => [
                    'cabecalho' => [
                        'numero_pedido' => 'PV-4242',
                        'codigo_cliente' => 7788,       // só o código, sem CNPJ
                        'data_previsao' => '10/08/2026',
                    ],
                    'det' => [],
                ],
            ]),
            '*geral/clientes*' => Http::response([
                'codigo_cliente_omie' => 7788,
                'cnpj_cpf' => '12.345.678/0001-90',
                'razao_social' => 'Suvinil Tintas S/A',
            ]),
        ]);

        $project = app(CreateProjectFromOmieOrder::class, ['omie' => $this->apiGateway()])
            ->handle('900010');

        $this->assertSame('Suvinil Tintas S/A', $project->company->name);
        $this->assertSame('12345678000190', $project->company->cnpj);
        $this->assertSame('7788', (string) $project->company->omie_customer_code);
    }

    public function test_cliente_sem_cnpj_nao_agrupa_tudo_numa_empresa_desconhecida(): void
    {
        Http::fake([
            '*produtos/pedido*' => Http::response([
                'pedido_venda_produto' => [
                    'cabecalho' => ['numero_pedido' => 'PV-1', 'codigo_cliente' => 555],
                    'det' => [],
                ],
            ]),
            // Cadastro sem documento
            '*geral/clientes*' => Http::response(['codigo_cliente_omie' => 555]),
        ]);

        $project = app(CreateProjectFromOmieOrder::class, ['omie' => $this->apiGateway()])
            ->handle('900011');

        // Chave estável por código, em vez de juntar clientes distintos
        $this->assertSame('omie-555', $project->company->cnpj);
    }

    public function test_reimportacao_nao_sobrescreve_o_nome_da_empresa_com_o_generico(): void
    {
        $company = Company::create([
            'cnpj' => '12345678000190',
            'name' => 'Suvinil Tintas S/A',
            'omie_customer_code' => '7788',
        ]);

        Http::fake([
            '*produtos/pedido*' => Http::response([
                'pedido_venda_produto' => [
                    'cabecalho' => ['numero_pedido' => 'PV-2', 'codigo_cliente' => 7788],
                    'det' => [],
                ],
            ]),
            // Retorno degradado: sem razão social
            '*geral/clientes*' => Http::response([
                'codigo_cliente_omie' => 7788,
                'cnpj_cpf' => '12345678000190',
            ]),
        ]);

        app(CreateProjectFromOmieOrder::class, ['omie' => $this->apiGateway()])->handle('900012');

        $this->assertSame('Suvinil Tintas S/A', $company->fresh()->name);
    }

    public function test_prazo_com_dia_maior_que_12_nao_derruba_a_criacao_do_projeto(): void
    {
        Http::fake(['*' => Http::response([
            'pedido_venda_produto' => [
                'cabecalho' => ['numero_pedido' => 'PV-9', 'data_previsao' => '25/12/2026'],
                'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME Ltda'],
                'det' => [],
            ],
        ])]);

        $project = app(CreateProjectFromOmieOrder::class, ['omie' => $this->apiGateway()])
            ->handle('900002');

        $this->assertSame('2026-12-25', $project->delivery_deadline->toDateString());
    }

    public function test_prazo_ilegivel_vira_null_em_vez_de_bloquear_o_pedido(): void
    {
        Http::fake(['*' => Http::response([
            'pedido_venda_produto' => [
                'cabecalho' => ['numero_pedido' => 'PV-10', 'data_previsao' => 'sem previsão'],
                'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME Ltda'],
                'det' => [],
            ],
        ])]);

        $project = app(CreateProjectFromOmieOrder::class, ['omie' => $this->apiGateway()])
            ->handle('900003');

        $this->assertNotNull($project, 'Projeto tem de ser criado mesmo sem prazo legível');
        $this->assertNull($project->delivery_deadline);
    }

    // ------------------------------------------------------------------
    // Reconciliação paginada
    // ------------------------------------------------------------------

    public function test_reconciliacao_percorre_todas_as_paginas_de_pedidos(): void
    {
        $gateway = new class implements OmieGateway
        {
            public array $pagesRequested = [];

            public function listOrders(?CarbonInterface $since = null, int $page = 1, ?string $projectCode = null): array
            {
                $this->pagesRequested[] = $page;

                return [
                    'items' => [['omie_order_id' => "90000{$page}"]],
                    'total_pages' => 3,
                ];
            }

            public function getOrder(string $omieOrderId): ?array
            {
                return null;
            }

            public function listProjects(int $page = 1): array
            {
                return ['items' => [], 'total_pages' => 1];
            }

            public function createOrder(array $payload): array
            {
                return ['omie_order_id' => '', 'omie_order_number' => ''];
            }

            public function findCustomerByDocument(string $document): ?array
            {
                return null;
            }

            public function getCustomer(string $omieCustomerCode): ?array
            {
                return null;
            }

            public function upsertCustomer(array $data): array
            {
                return ['omie_customer_code' => ''];
            }

            public function listProducts(int $page = 1): array
            {
                return ['items' => [], 'total_pages' => 1];
            }

            public function findProductByCode(string $code): ?array
            {
                return null;
            }

            public function getStockPositions(array $productCodes): array
            {
                return [];
            }

            public function decreaseStock(string $productCode, float $quantity, string $reason): void {}

            public function increaseStock(string $productCode, float $quantity, string $reason): void {}

            public function listInvoices(?CarbonInterface $since = null, int $page = 1): array
            {
                return [];
            }

            public function findInvoiceByOrder(string $omieOrderId): ?array
            {
                return null;
            }

            public function getInvoiceXml(string $nfKey): ?string
            {
                return null;
            }
        };

        app()->instance(OmieGateway::class, $gateway);

        app(ReconcileOmieOrders::class)->handle(
            $gateway,
            app(CreateProjectFromOmieOrder::class),
            app(OmieProjectMarker::class),
        );

        // Parar na página 1 faria a rede de segurança perder os picos de volume
        $this->assertSame([1, 2, 3], $gateway->pagesRequested);
    }

    // ------------------------------------------------------------------
    // Estoque
    // ------------------------------------------------------------------

    /**
     * O saldo vem do relatório EM LOTE (`ListarPosEstoque`), não de um
     * `PosicaoEstoque` por código.
     *
     * A consulta individual custava uma chamada por kit distinto — ~140 por
     * reconciliação, de hora em hora, vezes três tentativas. A Omie responde a
     * esse volume com uma janela de castigo no endpoint inteiro ("Consumo
     * redundante detectado. Aguarde N segundos"), e foi ela que derrubou a
     * reconciliação 243 vezes seguidas.
     */
    public function test_estoque_vem_do_relatorio_em_lote_e_nao_de_uma_chamada_por_kit(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);

        // Cinco kits DISTINTOS, um Projeto cada: o que antes custava 5 chamadas.
        foreach (range(1, 5) as $i) {
            $kit = Product::create(['omie_code' => "KIT-{$i}", 'name' => "Kit {$i}"]);
            $project = Project::create(['company_id' => $company->id, 'name' => "Proj {$i}", 'quantity' => 1]);
            $project->products()->sync([$kit->id => ['quantity' => 1]]);
        }

        Http::fake(['*' => Http::response([
            'nPagina' => 1,
            'nTotPaginas' => 1,
            'produtos' => collect(range(1, 5))
                ->map(fn (int $i) => ['cCodigo' => "KIT-{$i}", 'nSaldo' => 500])
                ->all(),
        ])]);

        app(HandleOmieStockSignal::class, ['omie' => $this->apiGateway()])->handle();

        Http::assertSentCount(1);
        $this->assertSame(5, Project::whereNotNull('stock_available_at')->count());
    }

    /** Código fora do relatório é saldo zero — não pode virar "tem estoque". */
    public function test_kit_ausente_do_relatorio_de_estoque_nao_libera_o_projeto(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $kit = Product::create(['omie_code' => 'KIT-SUMIDO', 'name' => 'Kit sumido']);
        $project = Project::create(['company_id' => $company->id, 'name' => 'Proj', 'quantity' => 1]);
        $project->products()->sync([$kit->id => ['quantity' => 1]]);

        Http::fake(['*' => Http::response([
            'nPagina' => 1,
            'nTotPaginas' => 1,
            'produtos' => [['cCodigo' => 'OUTRO-KIT', 'nSaldo' => 999]],
        ])]);

        app(HandleOmieStockSignal::class, ['omie' => $this->apiGateway()])->handle();

        $this->assertNull($project->fresh()->stock_available_at);
    }

    // ------------------------------------------------------------------
    // Throttle
    // ------------------------------------------------------------------

    public function test_throttle_falha_em_vez_de_estourar_o_limite_da_omie(): void
    {
        // Teto de 1 chamada/min: a segunda esperaria mais que o tolerado
        ApiThrottle::acquire('teste-omie', perMinute: 1, maxWaitSeconds: 0);

        $this->expectException(ApiThrottleException::class);

        ApiThrottle::acquire('teste-omie', perMinute: 1, maxWaitSeconds: 0);
    }

    public function test_throttle_desligado_quando_limite_e_zero(): void
    {
        foreach (range(1, 5) as $i) {
            ApiThrottle::acquire('teste-sem-limite', perMinute: 0);
        }

        $this->assertTrue(true); // não lançou
    }

    // ------------------------------------------------------------------
    // Trello — assinatura
    // ------------------------------------------------------------------

    public function test_webhook_trello_sem_secret_e_recusado_fora_de_dev(): void
    {
        config(['integrations.trello.secret' => null]);
        app()['env'] = 'production';

        $this->postJson('/webhooks/trello', ['action' => ['type' => 'updateCard']])
            ->assertForbidden();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_webhook_trello_com_assinatura_valida_e_aceito(): void
    {
        $secret = 'segredo-do-trello';
        config([
            'integrations.trello.secret' => $secret,
            'integrations.trello.webhook_callback_url' => 'https://welog.test/webhooks/trello',
        ]);

        $payload = [
            'action' => [
                'id' => 'act-1',
                'type' => 'updateCard',
                'data' => ['card' => ['id' => 'card-1'], 'listAfter' => ['id' => 'list-production']],
            ],
        ];
        $body = json_encode($payload);

        $signature = base64_encode(hash_hmac(
            'sha1',
            $body.'https://welog.test/webhooks/trello',
            $secret,
            true,
        ));

        $this->call(
            'POST',
            '/webhooks/trello',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TRELLO_WEBHOOK' => $signature,
            ],
            content: $body,
        )->assertOk();

        $this->assertDatabaseHas('webhook_events', [
            'source' => WebhookEvent::SOURCE_TRELLO,
            'external_event_id' => 'act-1',
        ]);
    }
}
