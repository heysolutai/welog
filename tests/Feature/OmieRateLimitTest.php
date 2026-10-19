<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Integrations\Omie\OmieApiException;
use App\Integrations\Omie\OmieApiGateway;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * O bloqueio de consumo da Omie ("Consumo redundante detectado. Aguarde 51
 * segundos") derrubou a reconciliação 243 vezes seguidas, uma por hora.
 *
 * Eram duas causas somadas: consultávamos o MESMO produto uma vez por pedido,
 * e retentávamos a chamada idêntica meio segundo depois — que é exatamente o
 * gatilho do bloqueio.
 */
class OmieRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const REDUNDANT = '{"faultstring":"ERROR: Consumo redundante detectado. Aguarde 51 segundos para tentar novamente (REDUNDANT).","faultcode":"SOAP-ENV:Client-6"}';

    /**
     * A reconciliação percorre dezenas de pedidos que compartilham os mesmos
     * kits. O produto já espelhado não volta para a Omie.
     */
    public function test_o_mesmo_kit_nao_e_consultado_duas_vezes(): void
    {
        $omie = Mockery::mock(OmieGateway::class);
        $omie->shouldReceive('findProductByCode')
            ->once() // ← o ponto do teste
            ->with('KIT-ONBOARD-01')
            ->andReturn(['omie_code' => 'KIT-ONBOARD-01', 'name' => 'Kit Onboarding']);

        $mirror = new MirrorOmieProduct(app(OmieAccounts::class), $omie);

        $primeiro = $mirror->handle('KIT-ONBOARD-01');
        $segundo = $mirror->handle('KIT-ONBOARD-01');

        $this->assertNotNull($primeiro);
        $this->assertTrue($primeiro->is($segundo));
    }

    /** Código ausente na Omie também é lembrado — senão o `null` é reconsultado sem fim. */
    public function test_produto_inexistente_tambem_nao_e_reconsultado(): void
    {
        $omie = Mockery::mock(OmieGateway::class);
        $omie->shouldReceive('findProductByCode')->once()->andReturn(null);

        $mirror = new MirrorOmieProduct(app(OmieAccounts::class), $omie);

        $this->assertNull($mirror->handle('NAO-EXISTE'));
        $this->assertNull($mirror->handle('NAO-EXISTE'));
    }

    /**
     * A Omie devolve o bloqueio como HTTP 500. Antes ele caía na regra
     * "5xx é transitório" e virava três chamadas idênticas em 1,5s — o próprio
     * gatilho do bloqueio. Agora sobe de uma vez, e quem espera é o backoff do
     * job, que trabalha em minutos.
     */
    public function test_bloqueio_de_consumo_nao_e_retentado_em_milissegundos(): void
    {
        Http::fake(['*' => Http::response(self::REDUNDANT, 500)]);

        $gateway = new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's');

        try {
            $gateway->getOrder('123');
            $this->fail('Esperava OmieApiException.');
        } catch (OmieApiException $e) {
            $this->assertStringContainsString('REDUNDANT', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    /** 5xx comum continua sendo retentado: o que mudou vale só para o bloqueio. */
    public function test_erro_de_servidor_comum_continua_sendo_retentado(): void
    {
        Http::fake(['*' => Http::response('erro interno', 500)]);

        $gateway = new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's');

        try {
            $gateway->getOrder('123');
        } catch (OmieApiException) {
            // esperado
        }

        $this->assertGreaterThan(1, Http::recorded()->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
