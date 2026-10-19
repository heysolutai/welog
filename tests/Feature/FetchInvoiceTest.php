<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieApiGateway;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Buscar NF na Omie" na página do resgate: a nota do pedido pelo ID interno
 * (ConsultarNF com nIdPedido), sem esperar o webhook nem a varredura.
 */
class FetchInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35260951785932000128550010000689811935151876';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['integrations.omie.min_interval_ms' => 0]);
        $this->seed();
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function resgate(?string $pedido = '7830935972'): AppnozImportRow
    {
        $import = AppnozImport::create([
            'filename' => 'reconhece-TESTE', 'file_hash' => 'hash-'.uniqid(), 'source' => AppnozImport::SOURCE_WEBHOOK,
        ]);

        return $import->rows()->create([
            'row_number' => 1, 'cpf' => '04166533169', 'customer_name' => 'Yago Machado',
            'product_code' => '1034', 'quantity' => 1,
            'status' => $pedido ? AppnozImportRow::STATUS_ORDER_CREATED : AppnozImportRow::STATUS_PENDING,
            'omie_order_id' => $pedido, 'omie_order_number' => $pedido ? '70669' : null,
        ]);
    }

    public function test_botao_acha_a_nota_e_o_resgate_vai_para_nf_emitida(): void
    {
        $resgate = $this->resgate();

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('findInvoiceByOrder')->with('7830935972')->andReturn([
            'nf_key' => self::CHAVE, 'nf_number' => '68981', 'series' => '001',
            'issued_at' => CarbonImmutable::parse('2026-09-30 17:18:38'), 'omie_invoice_id' => '7831068037',
        ]);

        $this->actingAs($this->operador())
            ->post(route('imports.rows.invoice', $resgate))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'NF 68981 encontrada e gravada no resgate.');

        $resgate->refresh();
        $this->assertSame(AppnozImportRow::STATUS_INVOICED, $resgate->status);
        $this->assertSame(self::CHAVE, $resgate->nf_key);
    }

    public function test_pedido_ainda_sem_nota_avisa_e_nao_muda_nada(): void
    {
        $resgate = $this->resgate();

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('findInvoiceByOrder')->andReturn(null);

        $this->actingAs($this->operador())
            ->post(route('imports.rows.invoice', $resgate))
            ->assertSessionHas('status', 'A Omie ainda não tem nota para o pedido 70669.');

        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $resgate->fresh()->status);
    }

    public function test_resgate_sem_pedido_nao_consulta(): void
    {
        $resgate = $this->resgate(null);

        $this->actingAs($this->operador())
            ->post(route('imports.rows.invoice', $resgate))
            ->assertSessionHasErrors(['nf' => 'Este resgate ainda não tem pedido na Omie.']);
    }

    public function test_botao_aparece_so_com_pedido_e_para_perfil_critico(): void
    {
        $this->actingAs($this->operador())
            ->get(route('imports.rows.show', $this->resgate()))
            ->assertInertia(fn (Assert $p) => $p->where('permissions.fetch_invoice', true));

        $consulta = User::where('email', 'consulta@welog.local')->firstOrFail();

        $this->actingAs($consulta)
            ->get(route('imports.rows.show', $this->resgate('111')))
            ->assertInertia(fn (Assert $p) => $p->where('permissions.fetch_invoice', false));
    }

    /** Contra a API: ConsultarNF com nIdPedido, data e hora de Brasília. */
    public function test_consulta_na_omie_pelo_id_do_pedido(): void
    {
        Http::fake(['*' => Http::response([
            'ide' => ['nNF' => '00068981', 'serie' => '001', 'dEmi' => '30/09/2026', 'hEmi' => '14:18:38'],
            'compl' => ['cChaveNFe' => self::CHAVE, 'nIdNF' => 7831068037, 'nIdPedido' => 7830935972],
        ])]);

        $nota = (new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's'))->findInvoiceByOrder('7830935972');

        $this->assertSame(self::CHAVE, $nota['nf_key']);
        $this->assertSame('68981', $nota['nf_number']);
        $this->assertSame('2026-09-30 14:18:38', $nota['issued_at']->setTimezone(config('integrations.business_timezone'))->format('Y-m-d H:i:s'));

        Http::assertSent(fn (Request $r) => $r['call'] === 'ConsultarNF' && $r['param'][0]['nIdPedido'] === 7830935972);
    }

    /** Pedido sem nota: a Omie diz "não encontrada", e isso é null, não erro. */
    public function test_pedido_sem_nota_na_omie_e_null(): void
    {
        Http::fake(['*' => Http::response(['faultstring' => 'ERROR: Nota fiscal não encontrada!', 'faultcode' => 'SOAP-ENV:Client-103'], 500)]);

        $this->assertNull((new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's'))->findInvoiceByOrder('7830935972'));
    }
}
