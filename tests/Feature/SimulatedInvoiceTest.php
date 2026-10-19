<?php

namespace Tests\Feature;

use App\Contracts\Integrations\FlashGateway;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Services\FluxoB\ImportAppnozFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `welog:nf:simular`: o Fluxo B testado em homologação sem emitir nota.
 *
 * O que estes testes guardam é a fronteira: a nota simulada percorre o mesmo
 * caminho de uma nota de verdade, mas nunca sai do ambiente de teste — nem
 * com o comando desligado, nem rumo à Flash real.
 */
class SimulatedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        config(['integrations.simulated_invoices' => true]);
    }

    /** Importa uma linha válida: pedido criado na Omie (fake), ainda sem NF. */
    private function pedidoCriado(): AppnozImportRow
    {
        $header = 'cpf,nome,email,telefone,codigo_produto,produto,quantidade,cep,endereco,numero,complemento,bairro,cidade,uf';
        $linha = '52998224725,José Pintor,jose@pintores.com.br,41988887777,SUV-PINCEL-01,Kit Pincéis,2,80010000,Rua das Tintas,45,,Centro,Curitiba,PR';
        $path = sys_get_temp_dir().'/appnoz-'.uniqid().'.csv';
        file_put_contents($path, "{$header}\n{$linha}\n");

        $row = app(ImportAppnozFile::class)->handle($path, AppnozImport::SOURCE_UPLOAD)->rows()->first();
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);

        return $row;
    }

    public function test_fatura_o_pedido_com_nf_ficticia(): void
    {
        $row = $this->pedidoCriado();

        $this->artisan('welog:nf:simular', ['linhas' => [$row->id]])->assertSuccessful();

        $row->refresh();
        $this->assertSame(AppnozImportRow::STATUS_INVOICED, $row->status);
        $this->assertTrue($row->nf_simulated);
        $this->assertNotNull($row->nf_issued_at);
        // 44 dígitos, UF 99: nunca colide com chave de verdade.
        $this->assertMatchesRegularExpression('/^99\d{42}$/', $row->nf_key);
    }

    public function test_por_numero_do_pedido(): void
    {
        $row = $this->pedidoCriado();

        $this->artisan('welog:nf:simular', ['--pedido' => $row->omie_order_number])->assertSuccessful();

        $this->assertTrue($row->fresh()->nf_simulated);
    }

    public function test_por_importacao(): void
    {
        $row = $this->pedidoCriado();

        $this->artisan('welog:nf:simular', ['--importacao' => $row->appnoz_import_id])->assertSuccessful();

        $this->assertTrue($row->fresh()->nf_simulated);
    }

    /** Desligado é o padrão — e é o estado da produção. */
    public function test_desligado_nao_toca_em_nada(): void
    {
        config(['integrations.simulated_invoices' => false]);
        $row = $this->pedidoCriado();

        $this->artisan('welog:nf:simular', ['linhas' => [$row->id]])->assertFailed();

        $this->assertNull($row->fresh()->nf_key);
    }

    /** Linha bloqueada ou já faturada não é a que a Omie faturaria. */
    public function test_so_fatura_pedido_criado_sem_nota(): void
    {
        $row = $this->pedidoCriado();
        $row->update(['nf_key' => str_repeat('3', 44), 'status' => AppnozImportRow::STATUS_INVOICED]);

        $this->artisan('welog:nf:simular', ['linhas' => [$row->id]])->assertSuccessful();

        $this->assertFalse($row->fresh()->nf_simulated);
        $this->assertSame(str_repeat('3', 44), $row->fresh()->nf_key);
    }

    /**
     * O passo a passo de homologação: NF simulada → rastrear → rastrear →
     * rastrear. Com a Flash fake cada rodada avança um estágio.
     */
    public function test_passo_a_passo_ate_a_entrega_com_a_flash_fake(): void
    {
        $row = $this->pedidoCriado();

        $this->artisan('welog:nf:simular', ['linhas' => [$row->id]])
            ->expectsOutputToContain('welog:flash:rastrear')
            ->assertSuccessful();
        $this->assertSame(AppnozImportRow::STATUS_INVOICED, $row->fresh()->status);

        $this->artisan('welog:flash:rastrear')->expectsOutputToContain('Em transporte')->assertSuccessful();
        $this->assertSame(AppnozImportRow::STATUS_DISPATCHED, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->tracking_code);

        $this->artisan('welog:flash:rastrear')->assertSuccessful();
        $this->artisan('welog:flash:rastrear')->expectsOutputToContain('Entregue')->assertSuccessful();

        $row->refresh();
        $this->assertSame(AppnozImportRow::STATUS_DELIVERED, $row->status);
        $this->assertSame(3, $row->trackingEvents()->count());
    }

    /**
     * Com a Flash real, a nota simulada simplesmente não é localizada (204):
     * a consulta é só leitura, nada quebra e o pedido fica em "NF emitida".
     */
    public function test_flash_real_nao_localiza_nota_simulada_e_nada_muda(): void
    {
        $row = $this->pedidoCriado();
        $this->artisan('welog:nf:simular', ['linhas' => [$row->id]])->assertSuccessful();

        config(['integrations.flash' => [
            'driver' => 'api', 'base_url' => 'https://bridgelink.test',
            'login' => 'ws.teste', 'senha' => 'segredo', 'key' => null,
            'cliente_id' => 5343, 'ctt_ids' => ['7185'], 'tracking_reference' => 'nf_key',
        ]]);
        $this->app->forgetInstance(FlashGateway::class);
        Http::fake([
            '*/token' => Http::response(['access_token' => 'jwt']),
            '*/consulta' => Http::response(null, 204),
        ]);

        $this->artisan('welog:flash:rastrear')->expectsOutputToContain('não localizado')->assertSuccessful();

        $row->refresh();
        $this->assertSame(AppnozImportRow::STATUS_INVOICED, $row->status);
        $this->assertSame(0, $row->trackingEvents()->count());
    }
}
