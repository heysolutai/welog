<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Company;
use App\Models\Project;
use App\Services\FluxoA\SyncOmieOrdersIntoProjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carga inicial de Projetos a partir da Omie (`welog:omie:sync-projects`).
 *
 * O contrato importante é o de CUSTO: o filtro de projeto roda em cima do
 * `ListarPedidos`, e o `ConsultarPedido` só acontece para quem passou no
 * filtro e ainda não é Projeto. Consultar tudo foi o que fez a Omie bloquear a
 * conta por consumo indevido.
 */
class SyncOmieProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const VENDA = '7407717220';

    private const SUVINIL = '7386090746';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['integrations.omie.project_codes' => self::VENDA]);
    }

    /** Linha do `ListarPedidos` — já traz o código do projeto. */
    private function listado(string $id, string $numero, string $codProj): array
    {
        return [
            'cabecalho' => ['codigo_pedido' => (int) $id, 'numero_pedido' => $numero, 'codigo_cliente' => 'CLI-1'],
            'informacoes_adicionais' => ['codProj' => (int) $codProj],
        ];
    }

    /** Retorno do `ConsultarPedido` — o que tem os itens. */
    private function completo(string $id, string $numero, string $codProj): array
    {
        return $this->listado($id, $numero, $codProj) + [
            'observacoes' => ['obs_venda' => ''],
            'det' => [['produto' => ['codigo' => 'KIT-ONBOARD-01', 'quantidade' => 10]]],
        ];
    }

    private function gateway(array $listagem, array $consultas): OmieGateway
    {
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('listOrders')->once()->andReturn(['items' => $listagem, 'total_pages' => 1]);
        $omie->shouldReceive('findProductByCode')->andReturn([
            'omie_code' => 'KIT-ONBOARD-01', 'name' => 'Kit', 'components' => [],
        ]);
        $omie->shouldReceive('getCustomer')->andReturn(null);

        $esperado = $omie->shouldReceive('getOrder')->times(count($consultas));
        foreach ($consultas as $id => $pedido) {
            $esperado->with((string) $id)->andReturn($pedido);
        }

        return $omie;
    }

    public function test_consulta_pedido_so_dos_que_passam_no_filtro_e_ainda_nao_existem(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        Project::create(['company_id' => $company->id, 'name' => 'Já existe', 'quantity' => 1, 'omie_order_id' => '3']);

        $this->gateway(
            listagem: [
                $this->listado('1', '100', self::VENDA),   // vira Projeto
                $this->listado('2', '200', self::SUVINIL), // fora do filtro → nem consulta
                $this->listado('3', '300', self::VENDA),   // já existe → nem consulta
                $this->listado('4', '400', '0'),           // sem projeto → nem consulta
            ],
            consultas: ['1' => $this->completo('1', '100', self::VENDA)],
        );

        $stats = app(SyncOmieOrdersIntoProjects::class)->handle(now()->subDays(90));

        $this->assertSame(4, $stats['listados']);
        $this->assertSame(2, $stats['fora_do_filtro']);
        $this->assertSame(1, $stats['ja_existiam']);
        $this->assertSame(1, $stats['candidatos']);
        $this->assertSame(1, $stats['criados']);
        $this->assertSame(0, $stats['falhas']);

        $this->assertDatabaseHas('projects', ['omie_order_id' => '1', 'omie_order_number' => '100']);
        $this->assertDatabaseMissing('projects', ['omie_order_id' => '2']);
        $this->assertDatabaseMissing('projects', ['omie_order_id' => '4']);
    }

    public function test_dry_run_conta_mas_nao_consulta_nem_grava(): void
    {
        $this->gateway(
            listagem: [$this->listado('1', '100', self::VENDA), $this->listado('2', '200', self::SUVINIL)],
            consultas: [],
        );

        $stats = app(SyncOmieOrdersIntoProjects::class)->handle(now()->subDays(90), dryRun: true);

        $this->assertSame(1, $stats['candidatos']);
        $this->assertSame(0, $stats['criados']);
        $this->assertSame(0, Project::count());
    }

    public function test_falha_em_um_pedido_nao_derruba_os_outros(): void
    {
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('listOrders')->once()->andReturn([
            'items' => [$this->listado('1', '100', self::VENDA), $this->listado('2', '200', self::VENDA)],
            'total_pages' => 1,
        ]);
        $omie->shouldReceive('getOrder')->with('1')->andThrow(new \RuntimeException('Omie fora do ar'));
        $omie->shouldReceive('getOrder')->with('2')->andReturn($this->completo('2', '200', self::VENDA));
        $omie->shouldReceive('findProductByCode')->andReturn(['omie_code' => 'KIT-ONBOARD-01', 'name' => 'Kit', 'components' => []]);
        $omie->shouldReceive('getCustomer')->andReturn(null);

        $stats = app(SyncOmieOrdersIntoProjects::class)->handle(now()->subDays(90));

        $this->assertSame(1, $stats['falhas']);
        $this->assertSame(1, $stats['criados']);
        $this->assertDatabaseHas('projects', ['omie_order_id' => '2']);
    }

    public function test_comando_recusa_rodar_sem_filtro_configurado(): void
    {
        config(['integrations.omie.project_codes' => '', 'integrations.omie.project_marker' => '']);

        $this->artisan('welog:omie:sync-projects', ['--dry-run' => true])
            ->expectsOutputToContain('Nenhum filtro de projeto configurado')
            ->assertFailed();
    }

    public function test_comando_dry_run_relata_o_que_seria_criado(): void
    {
        $this->gateway(listagem: [$this->listado('1', '100', self::VENDA)], consultas: []);

        $this->artisan('welog:omie:sync-projects', ['--dry-run' => true, '--dias' => 30])
            ->expectsOutputToContain('pedido 100: seria criado')
            ->assertSuccessful();

        $this->assertSame(0, Project::count());
    }
}
