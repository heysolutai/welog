<?php

namespace Tests\Feature;

use App\Contracts\Integrations\FlashGateway;
use App\Jobs\FluxoB\PollFlashTracking;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O número pelo qual a Flash conhece a encomenda (`numEncCli`).
 *
 * Na Bridge Link ele está parametrizado como WEBOX + número da NF sem os
 * zeros à esquerda (WEBOX44883). Mandando a chave da NF ou o número do pedido,
 * a consulta volta vazia e o resgate fica parado em "NF emitida" para sempre.
 */
class FlashReferenceTest extends TestCase
{
    use RefreshDatabase;

    /** NF nº 44883: o número ocupa as posições 26 a 34 da chave. */
    private const CHAVE = '35261012345678000190550010000448831000448830';

    private const CONSULTA_URL = 'https://bridgelink.test/FlashPegasus/rest/padrao/v2/consulta';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function flashApi(string $referencia = 'nf_number'): void
    {
        config(['integrations.flash' => [
            'driver' => 'api', 'base_url' => 'https://bridgelink.test',
            'login' => 'ws.teste', 'senha' => 'segredo', 'key' => null,
            'cliente_id' => 5343, 'ctt_ids' => ['7185'],
            'tracking_reference' => $referencia, 'tracking_prefix' => 'WEBOX',
        ]]);
        $this->app->forgetInstance(FlashGateway::class);
    }

    private function faturado(string $chave = self::CHAVE): AppnozImportRow
    {
        $import = AppnozImport::create([
            'filename' => 'SUV-1', 'file_hash' => 'hash-'.uniqid(), 'source' => AppnozImport::SOURCE_WEBHOOK,
        ]);

        return $import->rows()->create([
            'row_number' => 1, 'cpf' => '52998224725', 'customer_name' => 'Ana',
            'product_code' => 'KIT-X', 'quantity' => 1,
            'status' => AppnozImportRow::STATUS_INVOICED,
            'omie_order_number' => '9107',
            'nf_key' => $chave,
        ]);
    }

    public function test_numero_da_nf_sai_da_chave_sem_zeros(): void
    {
        $this->assertSame('44883', $this->faturado()->nfNumber());
    }

    public function test_chave_que_nao_tem_44_digitos_nao_tem_numero(): void
    {
        $this->assertNull($this->faturado('NFE-123')->nfNumber());
    }

    public function test_consulta_a_flash_por_webox_mais_numero_da_nf(): void
    {
        $this->flashApi();
        $row = $this->faturado();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'jwt']),
            '*/consulta' => Http::response(['statusRetorno' => '00', 'hawbs' => [[
                'meuNumero' => 'WEBOX44883',
                'hawbId' => 'BL0009123456',
                'historico' => [
                    ['ocorrencia' => '02/07/2026 14:20', 'eventoId' => '1400', 'evento' => 'Postado', 'local' => 'SAO PAULO'],
                    ['ocorrencia' => '08/07/2026 11:47', 'eventoId' => '4300', 'evento' => 'Entrega efetuada', 'local' => 'SAO PAULO'],
                ],
            ]]], 201),
        ]);

        PollFlashTracking::dispatchSync();

        Http::assertSent(fn (Request $r) => $r->url() === self::CONSULTA_URL && $r['numEncCli'] === ['WEBOX44883']);

        $row->refresh();
        $this->assertSame(AppnozImportRow::STATUS_DELIVERED, $row->status);
        $this->assertSame('BL0009123456', $row->tracking_code);
    }

    /** As outras duas formas seguem disponíveis por configuração. */
    public function test_modo_chave_da_nf_continua_mandando_a_chave(): void
    {
        $this->flashApi('nf_key');
        $this->faturado();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'jwt']),
            '*/consulta' => Http::response(null, 204),
        ]);

        PollFlashTracking::dispatchSync();

        Http::assertSent(fn (Request $r) => $r->url() === self::CONSULTA_URL && $r['numEncCli'] === [self::CHAVE]);
    }

    /** Chave sem número aproveitável não vira consulta com "WEBOX" sozinho. */
    public function test_sem_numero_da_nf_nao_consulta(): void
    {
        $this->flashApi();
        $this->faturado('NFE-123');

        Http::fake();

        PollFlashTracking::dispatchSync();

        Http::assertNothingSent();
    }
}
