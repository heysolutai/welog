<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Itens do pedido × componentes do kit (falha UAT).
 *
 * Um pedido pode conter o kit E um dos componentes dele como item AVULSO
 * (ex.: 10 kits + 25 canecas extras). São dois papéis do mesmo produto:
 * `project_products` guarda uma linha para cada item DO PEDIDO com a
 * quantidade pedida, e o BOM do kit segue em `kit_items` com a quantidade POR
 * KIT. A tela recebia tudo certo do banco, mas as props não diziam o papel de
 * cada linha — e o item avulso sumia, "engolido" pelo kit.
 */
class ProjectOrderItemsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        // O estado dos gateways fake vive em storage (fake/omie.json) e
        // sobrevive entre execuções; sem o fake do disco, a idempotência por
        // codigo_pedido_integracao devolveria um pedido de um run antigo.
        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
        $this->seed();
    }

    /**
     * Pedido com kit + item avulso no fake da Omie. CANECA-350 está no BOM do
     * KIT-ONBOARD-01 (ver OmieFakeGateway::KIT_BOM) — é exatamente o cenário
     * da UAT: o mesmo produto dentro do kit E como linha própria do pedido.
     */
    private function pedidoComKitEAvulso(): string
    {
        $result = app(OmieGateway::class)->createOrder([
            'codigo_pedido_integracao' => 'kit-e-avulso-1',
            'cabecalho' => [
                'numero_pedido' => 'PV-66001',
                'codigo_cliente' => 'CLI-00099',
                'data_previsao' => now()->addDays(30)->toDateString(),
            ],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME Tecnologia LTDA'],
            'det' => [
                ['produto' => ['codigo' => 'KIT-ONBOARD-01', 'quantidade' => 10]],
                ['produto' => ['codigo' => 'CANECA-350', 'quantidade' => 25]],
            ],
        ]);

        return $result['omie_order_id'];
    }

    private function webhookInclusao(string $orderId): void
    {
        $this->postJson('/webhooks/omie/'.self::TOKEN, [
            'messageId' => 'msg-avulso-'.$orderId,
            'topic' => 'VendaProduto.Incluida',
            'event' => ['idPedido' => $orderId],
        ])->assertOk();
    }

    public function test_item_avulso_que_tambem_compoe_o_kit_vira_linha_propria_do_projeto(): void
    {
        $orderId = $this->pedidoComKitEAvulso();
        $this->webhookInclusao($orderId);

        $project = Project::where('omie_order_id', $orderId)->firstOrFail();

        // Duas linhas em project_products: o kit e o avulso — cada uma com a
        // quantidade DO PEDIDO. É o número que o HandleOmieStockSignal compara
        // com o saldo para liberar o Projeto.
        $this->assertSame(2, $project->products()->count());

        $kit = $project->products->firstWhere('omie_code', 'KIT-ONBOARD-01');
        $caneca = $project->products->firstWhere('omie_code', 'CANECA-350');

        $this->assertNotNull($kit, 'O kit precisa estar entre os itens do pedido.');
        $this->assertNotNull($caneca, 'O item avulso não pode ser engolido pelo kit.');
        $this->assertSame(10, (int) $kit->pivot->quantity);
        $this->assertSame(25, (int) $caneca->pivot->quantity);
        $this->assertSame(35, $project->quantity);

        // O papel de componente continua existindo: o BOM do kit segue
        // trazendo a caneca com a quantidade POR KIT.
        $this->assertSame(1, (int) $kit->components->firstWhere('omie_code', 'CANECA-350')->pivot->quantity);
    }

    /** As props do show distinguem os papéis: item do pedido × componente do BOM. */
    public function test_show_apresenta_o_item_nos_dois_papeis(): void
    {
        $orderId = $this->pedidoComKitEAvulso();
        $this->webhookInclusao($orderId);

        $project = Project::where('omie_order_id', $orderId)->firstOrFail();
        $operator = User::where('email', 'operador@welog.local')->first();

        $this->actingAs($operator)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('project.products', 2)
                ->where('project.products', function (Collection $products) {
                    $kit = $products->firstWhere('omie_code', 'KIT-ONBOARD-01');
                    $caneca = $products->firstWhere('omie_code', 'CANECA-350');

                    return $kit !== null && $caneca !== null
                        // `is_kit` é o que permite à tela dar o expandir só ao kit
                        && $kit['is_kit'] === true
                        && $caneca['is_kit'] === false
                        && (int) $caneca['quantity'] === 25
                        // e a caneca segue aninhada no BOM do kit, "por kit"
                        && collect($kit['components'])->contains('omie_code', 'CANECA-350');
                }),
            );
    }

    /**
     * Mesmo código em mais de uma linha do `det` (avulso repetido, preços
     * diferentes): as quantidades somam. Antes a última linha sobrescrevia a
     * primeira e o Projeto registrava menos do que o pedido pede.
     */
    public function test_linhas_repetidas_do_mesmo_item_somam_a_quantidade(): void
    {
        $result = app(OmieGateway::class)->createOrder([
            'codigo_pedido_integracao' => 'linhas-repetidas-1',
            'cabecalho' => [
                'numero_pedido' => 'PV-66002',
                'codigo_cliente' => 'CLI-00099',
                'data_previsao' => now()->addDays(30)->toDateString(),
            ],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME Tecnologia LTDA'],
            'det' => [
                ['produto' => ['codigo' => 'CANECA-350', 'quantidade' => 30]],
                ['produto' => ['codigo' => 'CANECA-350', 'quantidade' => 12]],
            ],
        ]);

        $this->webhookInclusao($result['omie_order_id']);

        $project = Project::where('omie_order_id', $result['omie_order_id'])->firstOrFail();
        $caneca = $project->products->firstWhere('omie_code', 'CANECA-350');

        $this->assertSame(1, $project->products()->count());
        $this->assertSame(42, (int) $caneca->pivot->quantity);
    }
}
