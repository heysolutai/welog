<?php

namespace Tests\Feature;

use App\Integrations\Omie\OmieApiGateway;
use App\Integrations\Support\ApiCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chamadas iguais saem espaçadas; chamadas diferentes não esperam uma pela
 * outra.
 *
 * O que suspendeu a conta em produção não foi o volume: foi a rajada. O log da
 * Omie mostrava vários `ListarPedidos` no MESMO segundo — a varredura dispara
 * um por projeto configurado — as primeiras com 200 e as seguintes com 500.
 */
class OmieCallSpacingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ApiCircuitBreaker::close('omie');
        Cache::flush();
    }

    private function gateway(): OmieApiGateway
    {
        return new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's');
    }

    public function test_duas_chamadas_do_mesmo_metodo_saem_espacadas(): void
    {
        config(['integrations.omie.min_interval_ms' => 400]);
        Http::fake(['*' => Http::response(['cadastro' => [], 'total_de_paginas' => 1])]);

        $gateway = $this->gateway();

        $inicio = microtime(true);
        $gateway->listProjects();
        $gateway->listProjects();
        $decorridoMs = (microtime(true) - $inicio) * 1000;

        Http::assertSentCount(2);
        $this->assertGreaterThanOrEqual(
            380,
            $decorridoMs,
            'A segunda chamada saiu junto da primeira — é essa rajada que a Omie pune.'
        );
    }

    /** Um webhook faz pedido → cliente → produto; penalizar isso seria só lentidão. */
    public function test_metodos_diferentes_nao_esperam_um_pelo_outro(): void
    {
        config(['integrations.omie.min_interval_ms' => 2000]);
        Http::fake(['*' => Http::response(['cadastro' => [], 'total_de_paginas' => 1])]);

        $gateway = $this->gateway();

        $inicio = microtime(true);
        $gateway->listProjects();
        $gateway->listOrders();
        $decorridoMs = (microtime(true) - $inicio) * 1000;

        Http::assertSentCount(2);
        $this->assertLessThan(500, $decorridoMs, 'Chamadas de métodos diferentes não deveriam esperar.');
    }

    /** Intervalo zerado desliga o espaçamento — é como a suíte roda. */
    public function test_intervalo_zero_nao_espera(): void
    {
        config(['integrations.omie.min_interval_ms' => 0]);
        Http::fake(['*' => Http::response(['cadastro' => [], 'total_de_paginas' => 1])]);

        $gateway = $this->gateway();

        $inicio = microtime(true);
        $gateway->listProjects();
        $gateway->listProjects();

        $this->assertLessThan(500, (microtime(true) - $inicio) * 1000);
    }
}
