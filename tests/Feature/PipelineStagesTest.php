<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Exceptions\DomainException;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\TrelloListMapping;
use App\Models\User;
use App\Services\FluxoA\HandleOmieStockSignal;
use App\Services\FluxoA\RedeemKit;
use App\Services\FluxoA\ReleaseProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A esteira de seis estágios e a trava que ela impõe.
 *
 * "Em Estoque" é a trava que abre o resgate (e Envio, depois dela, o mantém aberto); a
 * verificação vale também DEPOIS da liberação: item danificado faz o Projeto
 * regredir, e a partir daí não há kit para entregar.
 */
class PipelineStagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A baixa de estoque do resgate fala com o gateway fake da Omie, que
        // persiste estado em storage — o fake isola a suíte do disco real.
        Storage::fake('local');
        $this->seed();
    }

    private function operator(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    /** @return array{0: Project, 1: ProjectEntitlement} */
    private function projeto(string $status): array
    {
        $company = Company::firstOrCreate(['cnpj' => '99888777000166'], ['name' => 'ACME']);
        // Saldo espelhado farto: estes testes são sobre a trava de ESTÁGIO, e a
        // liberação passou a exigir também saldo no espelho — sem ele, todo
        // cenário liberável falharia pelo motivo errado.
        $product = Product::firstOrCreate(
            ['omie_code' => 'KIT-ONBOARD-01'],
            ['name' => 'Kit Onboarding', 'stock_qty' => 1000, 'stock_synced_at' => now()],
        );

        $project = Project::create([
            'company_id' => $company->id,
            'name' => 'PV-1 ACME',
            'quantity' => 1,
            'pipeline_status' => $status,
            'stock_available_at' => now(),
        ]);
        $project->products()->attach($product->id, ['quantity' => 1]);

        $person = User::create([
            'name' => 'Bruno', 'email' => 'bruno@acme.com.br', 'password' => 'x',
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id, 'active' => true,
            'password_set_at' => now(),
        ]);

        $entitlement = ProjectEntitlement::create([
            'project_id' => $project->id,
            'user_id' => $person->id,
            'product_id' => $product->id,
        ]);

        return [$project, $entitlement];
    }

    /** "Em Estoque" vem ANTES de "Envio": o kit precisa existir para sair. */
    public function test_a_esteira_tem_seis_estagios_na_ordem_da_operacao(): void
    {
        $this->assertSame([
            'design', 'production', 'assembly', 'in_stock', 'shipping', 'done',
        ], Project::PIPELINE_STATUSES);
    }

    /** Saldo na Omie não basta: o kit precisa ter chegado ao estoque. */
    public function test_projeto_antes_de_em_estoque_nao_pode_ser_liberado(): void
    {
        [$project] = $this->projeto(Project::STATUS_ASSEMBLY);

        $this->assertFalse($project->canBeReleased());

        $this->expectException(DomainException::class);
        app(ReleaseProject::class)->handle($project, $this->operator());
    }

    public function test_projeto_em_estoque_pode_ser_liberado(): void
    {
        [$project] = $this->projeto(Project::STATUS_IN_STOCK);

        $this->assertTrue($project->canBeReleased());

        app(ReleaseProject::class)->handle($project, $this->operator());

        $this->assertNotNull($project->fresh()->released_at);
    }

    /**
     * Item danificado: o card volta no Trello, o Projeto sai de "Em Estoque" e
     * o resgate fecha sozinho — sem tela de aviso, sem intervenção.
     */
    public function test_regressao_de_estagio_suspende_o_resgate_ja_liberado(): void
    {
        [$project, $entitlement] = $this->projeto(Project::STATUS_IN_STOCK);

        app(ReleaseProject::class)->handle($project, $this->operator());
        $project->update(['pipeline_status' => Project::STATUS_ASSEMBLY]);

        $this->expectExceptionMessage('saiu do estoque');

        app(RedeemKit::class)->handle($entitlement->fresh(), [
            'recipient_name' => 'Bruno', 'cpf' => '52998224725',
            'zip' => '80010-000', 'street' => 'Rua XV', 'number' => '10',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ], $entitlement->user);
    }

    /**
     * Envio é etapa PÓS-estoque: os kits estão saindo, e quem ainda não
     * resgatou continua podendo. Mover para Envio não pode fechar o portal.
     */
    public function test_resgate_continua_aberto_em_envio(): void
    {
        [$project, $entitlement] = $this->projeto(Project::STATUS_IN_STOCK);

        app(ReleaseProject::class)->handle($project, $this->operator());
        $project->update(['pipeline_status' => Project::STATUS_SHIPPING]);

        $this->assertTrue($project->fresh()->isInStock());

        $redemption = app(RedeemKit::class)->handle($entitlement->fresh(), [
            'recipient_name' => 'Bruno', 'cpf' => '52998224725',
            'zip' => '80010-000', 'street' => 'Rua XV', 'number' => '10',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ], $entitlement->user);

        $this->assertNotNull($redemption);
    }

    /** Concluído fecha o resgate. */
    public function test_projeto_concluido_nao_esta_mais_em_estoque(): void
    {
        [$project] = $this->projeto(Project::STATUS_DONE);

        $this->assertFalse($project->isInStock());
        $this->assertFalse($project->canBeReleased());
    }

    /**
     * O sinal de estoque da Omie promove para "Em Estoque" só quem está antes
     * dele. Projeto em Envio não volta — seria regressão por efeito colateral.
     */
    public function test_sinal_de_estoque_nao_puxa_projeto_em_envio_de_volta(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '99888777000166']);
        $product = Product::firstOrCreate(['omie_code' => 'KIT-ONBOARD-01'], ['name' => 'Kit Onboarding']);
        $semEstoque = function (string $status) use ($company, $product): Project {
            $p = Project::create(['company_id' => $company->id, 'name' => $status, 'quantity' => 1, 'pipeline_status' => $status]);
            $p->products()->attach($product->id, ['quantity' => 1]);

            return $p;
        };
        $antes = $semEstoque(Project::STATUS_ASSEMBLY);
        $enviando = $semEstoque(Project::STATUS_SHIPPING);
        $feito = $semEstoque(Project::STATUS_DONE);

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getStockPositions')->andReturn(['KIT-ONBOARD-01' => 999]);

        app(HandleOmieStockSignal::class)->handle();

        $this->assertSame(Project::STATUS_IN_STOCK, $antes->fresh()->pipeline_status);
        $this->assertSame(Project::STATUS_SHIPPING, $enviando->fresh()->pipeline_status);
        $this->assertSame(Project::STATUS_DONE, $feito->fresh()->pipeline_status);
    }

    /**
     * O quadro governa TRÊS movimentações: Design, Produção e Montagem.
     * Estoque (sinal da Omie), Envio (expedição) e Concluído são da WeLog —
     * card arrastado para essas listas não move o Projeto.
     */
    public function test_trello_mapeia_somente_as_tres_etapas_de_producao(): void
    {
        $this->assertSame(
            ['design', 'production', 'assembly'],
            Project::TRELLO_STATUSES,
        );

        $this->assertSame(
            [],
            TrelloListMapping::whereNotIn('pipeline_status', Project::TRELLO_STATUSES)
                ->pluck('pipeline_status')
                ->all(),
        );
    }
}
