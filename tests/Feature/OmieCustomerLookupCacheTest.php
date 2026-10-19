<?php

namespace Tests\Feature;

use App\Integrations\Omie\OmieApiGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A consulta de cliente por CPF não se repete em seguida.
 *
 * A Omie recusa a mesma chamada repetida em menos de ~60s ("Consumo
 * redundante detectado. Aguarde 59 segundos"). Dois resgates da mesma pessoa
 * no mesmo lote faziam exatamente isso, e o segundo ficava sem pedido.
 */
class OmieCustomerLookupCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['integrations.omie.min_interval_ms' => 0]);
    }

    private function gateway(): OmieApiGateway
    {
        return new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's');
    }

    public function test_mesmo_cpf_em_seguida_nao_repete_o_listar_clientes(): void
    {
        Http::fake(['*' => Http::response(['clientes_cadastro' => [[
            'codigo_cliente_omie' => 7788,
            'cnpj_cpf' => '529.982.247-25',
            'razao_social' => 'Ana Parceira',
        ]]])]);

        $this->gateway()->findCustomerByDocument('52998224725');
        $cliente = $this->gateway()->findCustomerByDocument('529.982.247-25');

        $this->assertSame('7788', $cliente['omie_customer_code']);
        Http::assertSentCount(1);
    }

    /** O cliente recém-cadastrado é achado sem perguntar à Omie de novo. */
    public function test_cliente_recem_cadastrado_e_encontrado_sem_consultar(): void
    {
        Http::fake(['*' => Http::response(['codigo_cliente_omie' => 9911, 'codigo_status' => '0'])]);

        $this->gateway()->upsertCustomer([
            'cnpj_cpf' => '52998224725',
            'razao_social' => 'Ana Parceira',
            'cidade' => 'Curitiba',
        ]);
        $cliente = $this->gateway()->findCustomerByDocument('52998224725');

        $this->assertSame('9911', $cliente['omie_customer_code']);
        $this->assertSame('Curitiba', $cliente['city']);
        Http::assertSentCount(1);
    }

    /** "Não existe" não fica guardado: deixa de valer quando o cliente é cadastrado. */
    public function test_cliente_nao_encontrado_nao_fica_guardado(): void
    {
        Http::fake(['*' => Http::response(['clientes_cadastro' => []])]);

        $this->assertNull($this->gateway()->findCustomerByDocument('52998224725'));
        $this->assertNull($this->gateway()->findCustomerByDocument('52998224725'));

        Http::assertSentCount(2);
    }
}
