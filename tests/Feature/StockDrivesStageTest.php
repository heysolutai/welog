<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Services\FluxoA\HandleOmieStockSignal;
use App\Services\FluxoA\ShipRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A etapa acompanha o ESTOQUE, não o clique.
 *
 * O quadro do Trello governa só Design, Produção e Montagem. Daí em diante
 * quem manda é o fato: o saldo da Omie põe e tira o Projeto de "Em Estoque"
 * (nos dois sentidos) e a expedição o move para "Envio". Sem isso, metade da
 * esteira dependeria de alguém lembrar de ajustar a etapa à mão.
 */
class StockDrivesStageTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Product $kit;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->seed();

        $this->empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->kit = Product::create(['omie_code' => 'KIT-VAR-01', 'name' => 'Kit Variação']);

        $this->project = Project::create([
            'company_id' => $this->empresa->id,
            'name' => '66906 - ACME',
            'quantity' => 100,
            'pipeline_status' => Project::STATUS_ASSEMBLY,
        ]);
        $this->project->products()->attach($this->kit->id, ['quantity' => 100]);
    }

    /** Dispara o sinal com o saldo que a Omie responderia. */
    private function sinalDeEstoque(float $saldo): void
    {
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getStockPositions')->andReturn(['KIT-VAR-01' => $saldo]);

        app(HandleOmieStockSignal::class)->handle();
    }

    public function test_saldo_suficiente_poe_o_projeto_em_estoque(): void
    {
        $this->sinalDeEstoque(100);

        $project = $this->project->fresh();
        $this->assertSame(Project::STATUS_IN_STOCK, $project->pipeline_status);
        $this->assertNotNull($project->stock_available_at);
    }

    /**
     * O kit sumiu do ERP por fora da plataforma (transferido, faturado,
     * quebrado): o Projeto sai do estoque e o portal fecha sozinho.
     */
    public function test_saldo_que_some_na_omie_tira_o_projeto_do_estoque(): void
    {
        $this->sinalDeEstoque(100);
        $this->project->update(['released_at' => now()]);

        $this->sinalDeEstoque(12);

        $project = $this->project->fresh();
        $this->assertSame(Project::STATUS_ASSEMBLY, $project->pipeline_status);
        $this->assertNull($project->stock_available_at);
        $this->assertFalse($project->isInStock(), 'Portal tem de fechar junto');

        // A liberação é história: ao repor o saldo, a campanha retoma sem
        // disparar o e-mail de resgate outra vez.
        $this->assertTrue($project->isReleased());

        $this->sinalDeEstoque(100);
        $this->assertSame(Project::STATUS_IN_STOCK, $this->project->fresh()->pipeline_status);
    }

    /**
     * Queda de saldo POR CONSUMO da campanha não é sumiço: se o que falta
     * entregar ainda cabe no estoque, o Projeto fica onde está.
     */
    public function test_baixa_dos_proprios_resgates_nao_derruba_o_projeto(): void
    {
        $this->sinalDeEstoque(100);
        $this->project->update(['released_at' => now()]);

        // 60 kits já saíram (baixa refletida na Omie): restam 40 a entregar
        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
            'password_set_at' => now(),
        ]);
        Redemption::create([
            'project_id' => $this->project->id,
            'user_id' => $pessoa->id,
            'product_id' => $this->kit->id,
            'status' => Redemption::STATUS_CONFIRMED,
            'quantity' => 60,
            'stock_pushed_at' => now(),
            'recipient_name' => 'Recepção', 'cpf' => '52998224725',
            'zip' => '80010000', 'street' => 'Rua XV', 'number' => '100',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);

        $this->sinalDeEstoque(40);

        $this->assertSame(Project::STATUS_IN_STOCK, $this->project->fresh()->pipeline_status);
        $this->assertNotNull($this->project->fresh()->stock_available_at);
    }

    /** Projeto concluído é fim de linha: saldo baixo não o reabre. */
    public function test_projeto_concluido_nao_regride(): void
    {
        $this->project->update([
            'pipeline_status' => Project::STATUS_DONE,
            'stock_available_at' => now(),
        ]);

        $this->sinalDeEstoque(0);

        $this->assertSame(Project::STATUS_DONE, $this->project->fresh()->pipeline_status);
    }

    /** Expedir é o kit saindo: o primeiro resgate na rua move para "Envio". */
    public function test_expedicao_move_o_projeto_para_envio(): void
    {
        $this->sinalDeEstoque(100);
        $this->project->update(['released_at' => now()]);

        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
            'password_set_at' => now(),
        ]);
        ProjectEntitlement::create([
            'project_id' => $this->project->id,
            'user_id' => $pessoa->id,
            'product_id' => $this->kit->id,
        ]);
        $redemption = Redemption::create([
            'project_id' => $this->project->id,
            'user_id' => $pessoa->id,
            'product_id' => $this->kit->id,
            'status' => Redemption::STATUS_CONFIRMED,
            'recipient_name' => 'Bruno', 'cpf' => '52998224725',
            'zip' => '80010000', 'street' => 'Rua XV', 'number' => '10',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);

        app(ShipRedemption::class)->handle(
            $redemption,
            'mrb',
            User::where('email', 'operador@welog.local')->firstOrFail(),
            'MRB123456789BR',
        );

        $this->assertSame(Project::STATUS_SHIPPING, $this->project->fresh()->pipeline_status);
    }
}
