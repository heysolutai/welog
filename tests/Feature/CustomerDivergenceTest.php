<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieApiGateway;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\User;
use App\Services\FluxoB\ProcessAppnozRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Validações do Fluxo B (cronograma S5).
 *
 * Cliente ausente já era criado automaticamente e produto ausente já bloqueava.
 * Faltava o terceiro caso: cliente que EXISTE na Omie com dados diferentes dos
 * que vieram na planilha do parceiro — que alerta, não bloqueia (o Anexo I diz
 * que produto ausente é "a única condição bloqueante do fluxo").
 */
class CustomerDivergenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function linha(array $override = []): AppnozImportRow
    {
        $import = AppnozImport::create([
            'filename' => 'suvinil.xlsx',
            'file_hash' => 'hash-'.uniqid(),
            'source' => AppnozImport::SOURCE_DIRECTORY,
            'status' => AppnozImport::STATUS_COMPLETED,
        ]);

        return AppnozImportRow::create(array_merge([
            'appnoz_import_id' => $import->id,
            'row_number' => 2,
            'cpf' => '52998224725',
            'customer_name' => 'Maria Silva',
            'email' => 'maria@x.com',
            'phone' => '41999990000',
            'product_code' => 'SUV-PINCEL-01',
            'product_name' => 'Pincel',
            'quantity' => 1,
            'zip' => '80010000', 'street' => 'Rua XV', 'number' => '10',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
            'status' => AppnozImportRow::STATUS_PENDING,
        ], $override));
    }

    /** Cliente na Omie com endereço diferente do que veio na planilha. */
    private function clienteDivergenteNaOmie(): void
    {
        $omie = $this->mock(OmieGateway::class);

        $omie->shouldReceive('findCustomerByDocument')->andReturn([
            'omie_customer_code' => '7788',
            'name' => 'Maria Silva',
            'contact_email' => 'maria@x.com',
            'contact_phone' => '41999990000',
            'zip' => '80020000',            // ← diverge
            'street' => 'Avenida Batel',    // ← diverge
            'number' => '10',
            'district' => 'Centro',
            'city' => 'Curitiba',
            'state' => 'PR',
        ]);

        $omie->shouldReceive('findProductByCode')->andReturn(['omie_code' => 'SUV-PINCEL-01', 'name' => 'Pincel']);
        $omie->shouldReceive('createOrder')->andReturn([
            'omie_order_id' => '999', 'omie_order_number' => 'PV-999',
        ]);
        $omie->shouldNotReceive('upsertCustomer');
    }

    public function test_divergencia_e_registrada_sem_bloquear_o_pedido(): void
    {
        $this->clienteDivergenteNaOmie();

        $row = app(ProcessAppnozRow::class)->handle($this->linha());

        // Alerta, não bloqueio: o Pedido de Venda foi criado.
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);
        $this->assertSame('PV-999', $row->omie_order_number);

        $campos = collect($row->customer_divergences)->pluck('campo')->all();
        $this->assertContains('zip', $campos);
        $this->assertContains('street', $campos);
        $this->assertCount(2, $campos, 'só os campos que realmente diferem');
    }

    /** Cliente igual não gera alerta — painel cheio de falso positivo não é usado. */
    public function test_cliente_identico_nao_gera_divergencia(): void
    {
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('findCustomerByDocument')->andReturn([
            'omie_customer_code' => '7788',
            'name' => 'MARIA SILVA',           // caixa diferente
            'contact_email' => 'maria@x.com',
            'contact_phone' => '(41) 99999-0000', // pontuação diferente
            'zip' => '80010-000',              // pontuação diferente
            'street' => 'Rua XV', 'number' => '10',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);
        $omie->shouldReceive('findProductByCode')->andReturn(['omie_code' => 'SUV-PINCEL-01', 'name' => 'Pincel']);
        $omie->shouldReceive('createOrder')->andReturn(['omie_order_id' => '1', 'omie_order_number' => 'PV-1']);

        $row = app(ProcessAppnozRow::class)->handle($this->linha());

        $this->assertNull($row->customer_divergences);
    }

    /** Cliente ausente é criado a partir da planilha: não há dois lados para divergir. */
    public function test_cliente_ausente_nao_gera_divergencia(): void
    {
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('findCustomerByDocument')->andReturn(null);
        $omie->shouldReceive('upsertCustomer')->once()->andReturn(['omie_customer_code' => '7788']);
        $omie->shouldReceive('findProductByCode')->andReturn(['omie_code' => 'SUV-PINCEL-01', 'name' => 'Pincel']);
        $omie->shouldReceive('createOrder')->andReturn(['omie_order_id' => '1', 'omie_order_number' => 'PV-1']);

        $row = app(ProcessAppnozRow::class)->handle($this->linha());

        $this->assertNull($row->customer_divergences);
        $this->assertTrue($row->customer_auto_created);
    }

    public function test_operador_atualiza_a_omie_com_os_dados_da_planilha(): void
    {
        $this->clienteDivergenteNaOmie();
        $row = app(ProcessAppnozRow::class)->handle($this->linha());

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('upsertCustomer')->once()->andReturn(['omie_customer_code' => '7788']);

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('imports.rows.divergence', $row), ['resolucao' => 'usar_planilha'])
            ->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertNotNull($row->divergence_resolved_at);
        $this->assertSame('usar_planilha', $row->divergence_resolution);
    }

    /** Manter o cadastro da Omie não pode escrever nada lá. */
    public function test_manter_cadastro_da_omie_nao_chama_a_api(): void
    {
        $this->clienteDivergenteNaOmie();
        $row = app(ProcessAppnozRow::class)->handle($this->linha());

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldNotReceive('upsertCustomer');

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('imports.rows.divergence', $row), ['resolucao' => 'manter_omie'])
            ->assertSessionHasNoErrors();

        $this->assertSame('manter_omie', $row->fresh()->divergence_resolution);
    }

    public function test_n2_nao_resolve_divergencia(): void
    {
        $this->clienteDivergenteNaOmie();
        $row = app(ProcessAppnozRow::class)->handle($this->linha());

        $this->actingAs(User::where('email', 'consulta@welog.local')->firstOrFail())
            ->post(route('imports.rows.divergence', $row), ['resolucao' => 'manter_omie'])
            ->assertForbidden();
    }

    /**
     * O `findCustomerByDocument` da API devolvia o registro CRU da Omie, onde o
     * código se chama `codigo_cliente_omie` — e o Fluxo B lê `omie_customer_code`.
     * O gateway fake já guardava a chave normalizada, então os testes passavam e
     * o Pedido de Venda de um cliente JÁ CADASTRADO saía sem cliente.
     */
    public function test_cliente_encontrado_na_api_vem_com_o_codigo_normalizado(): void
    {
        Http::fake(['*' => Http::response([
            'clientes_cadastro' => [[
                'codigo_cliente_omie' => 7788,
                'cnpj_cpf' => '52998224725',
                'razao_social' => 'Maria Silva',
                'cep' => '80010-000',
            ]],
        ])]);

        $cliente = (new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's'))
            ->findCustomerByDocument('52998224725');

        $this->assertSame('7788', $cliente['omie_customer_code']);
        $this->assertSame('Maria Silva', $cliente['name']);
    }
}
