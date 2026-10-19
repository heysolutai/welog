<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Company;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `welog:omie:pedido` responde se um pedido é do projeto Venda. */
class CheckOmieOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.omie.driver' => 'api',
            'integrations.omie.project_codes' => '7407717220',
        ]);
    }

    private function pedido(string $numero, $codProj): array
    {
        return [
            'cabecalho' => ['numero_pedido' => $numero, 'codigo_pedido' => 999],
            'informacoes_adicionais' => ['codProj' => $codProj],
        ];
    }

    public function test_pedido_do_projeto_venda_e_confirmado(): void
    {
        $this->mock(OmieGateway::class)->shouldReceive('getOrder')->once()
            ->with('999')->andReturn($this->pedido('67518', 7407717220));

        $this->artisan('welog:omie:pedido', ['pedido' => '999'])
            ->expectsOutputToContain('É VENDA')
            ->assertSuccessful();
    }

    public function test_pedido_de_outro_projeto_e_negado_e_sem_classificacao_tambem(): void
    {
        $gw = $this->mock(OmieGateway::class);
        $gw->shouldReceive('getOrder')->once()->with('1')->andReturn($this->pedido('11', 555));
        $gw->shouldReceive('getOrder')->once()->with('2')->andReturn($this->pedido('22', 0));

        $this->artisan('welog:omie:pedido', ['pedido' => '1'])
            ->expectsOutputToContain('NÃO é Venda')->assertSuccessful();
        $this->artisan('welog:omie:pedido', ['pedido' => '2'])
            ->expectsOutputToContain('sem projeto classificado')->assertSuccessful();
    }

    public function test_numero_do_pedido_resolve_o_id_pelo_projeto_welog(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        Project::create([
            'company_id' => $company->id, 'name' => 'X', 'quantity' => 1,
            'omie_order_number' => '67518', 'omie_order_id' => '424242',
        ]);

        $this->mock(OmieGateway::class)->shouldReceive('getOrder')->once()
            ->with('424242')->andReturn($this->pedido('67518', 7407717220));

        $this->artisan('welog:omie:pedido', ['pedido' => '67518'])
            ->expectsOutputToContain('É VENDA')
            ->expectsOutputToContain('No WeLog: Projeto #')
            ->assertSuccessful();
    }
}
