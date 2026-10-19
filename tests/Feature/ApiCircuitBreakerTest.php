<?php

namespace Tests\Feature;

use App\Integrations\Omie\OmieApiGateway;
use App\Integrations\Support\ApiCircuitBreaker;
use App\Integrations\Support\ApiCircuitOpenException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quando a Omie fecha a porta, a plataforma para de bater.
 *
 * A conta foi SUSPENSA em produção depois de uma sequência de chamadas: cada
 * job enfileirado continuava tentando sozinho, levava o mesmo 403 e reforçava
 * o castigo. O disjuntor corta isso na primeira recusa — e a diferença entre
 * "porta fechada" e "resposta de negócio" é o que mantém o resto funcionando.
 */
class ApiCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ApiCircuitBreaker::close('omie');
    }

    private function gateway(): OmieApiGateway
    {
        return new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's');
    }

    public function test_aplicativo_suspenso_pausa_a_integracao(): void
    {
        Http::fake(['*' => Http::response([
            'faultstring' => 'A chave de acesso está inválida ou o aplicativo está suspenso [#123 NDAz]',
            'faultcode' => 'SOAP-ENV:Server',
        ], 403)]);

        try {
            $this->gateway()->listProjects();
            $this->fail('A chamada deveria ter falhado');
        } catch (\Throwable) {
            // esperado
        }

        $this->assertNotNull(ApiCircuitBreaker::remaining('omie'), 'A integração deveria ter pausado');
    }

    /** Pausada, a próxima chamada nem chega à rede — é esse o ponto. */
    public function test_enquanto_pausada_nenhuma_chamada_sai(): void
    {
        ApiCircuitBreaker::open('omie', 'aplicativo suspenso', 120);

        Http::fake();

        $this->expectException(ApiCircuitOpenException::class);

        try {
            $this->gateway()->listProjects();
        } finally {
            Http::assertNothingSent();
        }
    }

    /** "Aguarde 56 segundos": a pausa respeita o tempo que a Omie pediu. */
    public function test_a_pausa_usa_o_tempo_que_a_omie_pediu(): void
    {
        Http::fake(['*' => Http::response([
            'faultstring' => 'ERROR: Consumo redundante detectado. Aguarde 56 segundos para tentar novamente (REDUNDANT).',
        ], 500)]);

        try {
            $this->gateway()->listProjects();
        } catch (\Throwable) {
            // esperado
        }

        $faltam = ApiCircuitBreaker::remaining('omie');

        $this->assertNotNull($faltam);
        $this->assertGreaterThan(56, $faltam, 'A pausa deve cobrir o tempo pedido, com folga');
        $this->assertLessThanOrEqual(75, $faltam);
    }

    /**
     * Erro de NEGÓCIO não pausa nada: pedido inexistente é resposta, não porta
     * fechada. Pausar aqui derrubaria a integração inteira por um dado ausente.
     */
    public function test_erro_de_negocio_nao_pausa_a_integracao(): void
    {
        Http::fake(['*' => Http::response([
            'faultstring' => 'ERROR: Pedido não localizado na base de dados',
            'faultcode' => 'SOAP-ENV:Client-104',
        ], 500)]);

        $this->assertNull($this->gateway()->getOrder('999999'));
        $this->assertNull(ApiCircuitBreaker::remaining('omie'));
    }

    public function test_o_comando_mostra_e_retoma_a_integracao(): void
    {
        ApiCircuitBreaker::open('omie', 'aplicativo suspenso', 300);

        $this->artisan('welog:integracoes:status')
            ->expectsOutputToContain('pausada')
            ->assertSuccessful();

        $this->artisan('welog:integracoes:status', ['--retomar' => 'omie'])
            ->expectsOutputToContain('retomada')
            ->assertSuccessful();

        $this->assertNull(ApiCircuitBreaker::remaining('omie'));
    }
}
