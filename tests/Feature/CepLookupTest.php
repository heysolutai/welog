<?php

namespace Tests\Feature;

use App\Contracts\Integrations\CepGateway;
use App\Integrations\Cep\ViaCepGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CepLookupTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN_WEGIFT]);
    }

    public function test_devolve_endereco_do_cep(): void
    {
        $this->actingAs($this->operador())
            ->getJson(route('cep.lookup', '80010000'))
            ->assertOk()
            ->assertJson([
                'zip' => '80010000',
                'city' => 'Curitiba',
                'state' => 'PR',
            ]);
    }

    public function test_cep_inexistente_responde_404(): void
    {
        // O fake trata CEP terminado em 000 como inexistente
        $this->actingAs($this->operador())
            ->getJson(route('cep.lookup', '99999000'))
            ->assertNotFound();
    }

    public function test_exige_autenticacao(): void
    {
        $this->getJson(route('cep.lookup', '80010000'))->assertUnauthorized();
    }

    /**
     * A tela precisa distinguir "CEP não existe" de "provedor fora do ar" —
     * senão o operador acha que digitou errado e fica tentando.
     */
    public function test_provedor_indisponivel_responde_503_e_nao_404(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        app()->instance(CepGateway::class, new ViaCepGateway('https://viacep.com.br'));

        $this->actingAs($this->operador())
            ->getJson(route('cep.lookup', '80010001'))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Serviço de CEP indisponível. Preencha o endereço manualmente.');
    }

    // ------------------------------------------------------------------
    // Driver real (ViaCEP), com a resposta no formato de verdade
    // ------------------------------------------------------------------

    public function test_normaliza_a_resposta_do_viacep(): void
    {
        Http::fake(['*' => Http::response([
            'cep' => '01310-100',
            'logradouro' => 'Avenida Paulista',
            'complemento' => 'de 1867 ao fim',
            'bairro' => 'Bela Vista',
            'localidade' => 'São Paulo',
            'uf' => 'SP',
        ])]);

        $endereco = (new ViaCepGateway('https://viacep.com.br'))->lookup('01310-100');

        $this->assertSame([
            'zip' => '01310100',
            'street' => 'Avenida Paulista',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $endereco);
    }

    public function test_viacep_responde_200_com_erro_quando_o_cep_nao_existe(): void
    {
        // Particularidade do provedor: 200 + {"erro": true}, não 404
        Http::fake(['*' => Http::response(['erro' => true])]);

        $this->assertNull((new ViaCepGateway('https://viacep.com.br'))->lookup('99999999'));
    }

    public function test_cep_com_tamanho_invalido_nao_gera_chamada_externa(): void
    {
        Http::fake();

        $this->assertNull((new ViaCepGateway('https://viacep.com.br'))->lookup('123'));

        Http::assertNothingSent();
    }

    public function test_segunda_consulta_ao_mesmo_cep_vem_do_cache(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response([
            'logradouro' => 'Rua XV de Novembro',
            'bairro' => 'Centro',
            'localidade' => 'Curitiba',
            'uf' => 'PR',
        ])]);

        $gateway = new ViaCepGateway('https://viacep.com.br');
        $gateway->lookup('80010000');
        $gateway->lookup('80010000');

        // CEP praticamente não muda: chamar o provedor duas vezes é desperdício
        Http::assertSentCount(1);
    }
}
