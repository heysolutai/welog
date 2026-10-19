<?php

namespace Tests\Feature\Admin;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `welog:projects:purge-non-venda` — apaga o que NÃO é do projeto Omie
 * configurado, classificando pela LISTAGEM (uma varredura), sem um
 * `ConsultarPedido` por Projeto.
 *
 * O que estes testes protegem é o que FICA: Venda, criado à mão, não
 * classificado (fora da janela) e, por padrão, quem já tem resgate.
 */
class PurgeNonVendaProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const VENDA = '7407717220';

    private const SUVINIL = '7386090746';

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['integrations.omie.project_codes' => self::VENDA]);
        $this->produto = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit', 'is_kit' => true]);
    }

    private function projeto(string $nome, ?string $omieId): Project
    {
        $empresa = Company::create(['name' => $nome.' Ltda', 'cnpj' => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT)]);

        $projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => $nome,
            'quantity' => 1,
            'omie_order_id' => $omieId,
            'omie_order_number' => $omieId,
        ]);
        $projeto->products()->attach($this->produto->id, ['quantity' => 1]);

        return $projeto;
    }

    private function listado(string $id, string $codProj): array
    {
        return [
            'cabecalho' => ['codigo_pedido' => (int) $id, 'numero_pedido' => $id],
            'informacoes_adicionais' => ['codProj' => (int) $codProj],
        ];
    }

    private function omieLista(array $pedidos): void
    {
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('listOrders')->once()->andReturn(['items' => $pedidos, 'total_pages' => 1]);
    }

    private function comResgate(Project $projeto): void
    {
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $projeto->company_id]);
        ProjectEntitlement::create(['project_id' => $projeto->id, 'user_id' => $user->id, 'product_id' => $this->produto->id]);
        Redemption::create([
            'project_id' => $projeto->id, 'user_id' => $user->id, 'product_id' => $this->produto->id,
            'status' => 'confirmed', 'recipient_name' => $user->name, 'zip' => '80010000',
            'street' => 'Rua', 'number' => '1', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);
    }

    public function test_apaga_so_o_que_nao_e_venda_e_preserva_o_resto(): void
    {
        $venda = $this->projeto('Venda', '1');
        $suvinil = $this->projeto('Suvinil', '2');
        $semProjeto = $this->projeto('Sem projeto', '3');
        $manual = $this->projeto('Manual', null);
        $foraDaJanela = $this->projeto('Fora da janela', '4'); // não aparece na listagem

        $this->omieLista([
            $this->listado('1', self::VENDA),
            $this->listado('2', self::SUVINIL),
            $this->listado('3', '0'),
        ]);

        $this->artisan('welog:projects:purge-non-venda', ['--execute' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('projects', ['id' => $venda->id]);
        $this->assertDatabaseHas('projects', ['id' => $manual->id]);
        $this->assertDatabaseHas('projects', ['id' => $foraDaJanela->id]);
        $this->assertDatabaseMissing('projects', ['id' => $suvinil->id]);
        $this->assertDatabaseMissing('projects', ['id' => $semProjeto->id]);
    }

    public function test_simulacao_nao_apaga_nada(): void
    {
        $this->projeto('Venda', '1');
        $this->projeto('Suvinil', '2');

        $this->omieLista([$this->listado('1', self::VENDA), $this->listado('2', self::SUVINIL)]);

        $this->artisan('welog:projects:purge-non-venda')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();

        $this->assertSame(2, Project::count());
    }

    public function test_projeto_com_resgate_fica_a_menos_que_incluir_usados(): void
    {
        $usado = $this->projeto('Suvinil usado', '2');
        $this->comResgate($usado);

        $this->omieLista([$this->listado('2', self::SUVINIL)]);
        $this->artisan('welog:projects:purge-non-venda', ['--execute' => true, '--force' => true])->assertSuccessful();
        $this->assertDatabaseHas('projects', ['id' => $usado->id]);

        $this->omieLista([$this->listado('2', self::SUVINIL)]);
        $this->artisan('welog:projects:purge-non-venda', ['--execute' => true, '--force' => true, '--incluir-usados' => true])->assertSuccessful();
        $this->assertDatabaseMissing('projects', ['id' => $usado->id]);
        $this->assertSame(0, Redemption::count());
    }

    public function test_recusa_sem_codigo_de_projeto_configurado(): void
    {
        config(['integrations.omie.project_codes' => '']);

        $this->artisan('welog:projects:purge-non-venda')
            ->expectsOutputToContain('OMIE_PROJECT_CODES vazio')
            ->assertFailed();
    }

    public function test_falha_na_omie_nao_apaga_nada(): void
    {
        $this->projeto('Suvinil', '2');

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('listOrders')->andThrow(new \RuntimeException('Aguarde 48 segundos'));

        $this->artisan('welog:projects:purge-non-venda', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('Nada foi apagado')
            ->assertFailed();

        $this->assertSame(1, Project::count());
    }
}
