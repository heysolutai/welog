<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Company;
use App\Models\Project;
use App\Services\FluxoA\SyncOmieOrdersIntoProjects;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Custo da varredura de reconciliação.
 *
 * A demora que a operação sentia não era a criação do Projeto: era a ESPERA.
 * O webhook da Omie não chegava, a varredura era horária, e um pedido fechado
 * às 10h05 só virava card às 11h. Para ela poder rodar de cinco em cinco
 * minutos, o filtro precisa ser feito na ORIGEM — dois dias da conta inteira
 * são 745 pedidos em 15 páginas, e quase nenhum é WeLog.
 *
 * Este teste trava esse contrato: a listagem vai à Omie JÁ filtrada por
 * projeto, uma passada por código configurado.
 */
class ReconcileSweepCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_varredura_filtra_por_projeto_na_origem(): void
    {
        config(['integrations.omie.project_codes' => '7828126392,7828126432']);

        $pedidos = [];
        $gateway = Mockery::mock(OmieGateway::class);
        $gateway->shouldReceive('listOrders')
            ->twice()
            ->andReturnUsing(function (?CarbonInterface $since, int $page, ?string $projectCode) use (&$pedidos) {
                $pedidos[] = $projectCode;

                return ['items' => [], 'total_pages' => 1];
            });

        $stats = app(SyncOmieOrdersIntoProjects::class, ['omie' => $gateway])
            ->handle(now()->subDays(2));

        // Uma passada por projeto, cada uma pedindo só o seu recorte
        $this->assertSame(['7828126392', '7828126432'], $pedidos);
        $this->assertSame(2, $stats['paginas']);
    }

    /** Sem código configurado, volta a ser uma varredura só, sem filtro. */
    public function test_sem_filtro_configurado_faz_uma_varredura_unica(): void
    {
        config(['integrations.omie.project_codes' => '', 'integrations.omie.project_templates' => '']);

        $gateway = Mockery::mock(OmieGateway::class);
        $gateway->shouldReceive('listOrders')
            ->once()
            ->with(Mockery::any(), 1, null)
            ->andReturn(['items' => [], 'total_pages' => 1]);

        app(SyncOmieOrdersIntoProjects::class, ['omie' => $gateway])->handle(now()->subDays(2));
    }

    /** O filtro da origem não substitui a trava: o codProj é reconferido aqui. */
    public function test_pedido_de_outro_projeto_ainda_e_recusado(): void
    {
        config(['integrations.omie.project_codes' => '7828126392']);

        Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);

        $gateway = Mockery::mock(OmieGateway::class);
        $gateway->shouldReceive('listOrders')->once()->andReturn([
            // A Omie devolveu um pedido de OUTRO projeto (filtro furado do lado
            // dela, ou parâmetro ignorado numa versão futura da API).
            'items' => [[
                'cabecalho' => ['codigo_pedido' => 99001, 'numero_pedido' => '99001'],
                'informacoes_adicionais' => ['codProj' => 7386090746],
            ]],
            'total_pages' => 1,
        ]);
        $gateway->shouldNotReceive('getOrder');

        $stats = app(SyncOmieOrdersIntoProjects::class, ['omie' => $gateway])
            ->handle(now()->subDays(2));

        $this->assertSame(1, $stats['fora_do_filtro']);
        $this->assertSame(0, $stats['criados']);
        $this->assertSame(0, Project::count());
    }
}
