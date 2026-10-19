<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Services\FluxoA\RedeemKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cancelamento de resgate com devolução ao saldo do PROJETO.
 *
 * A baixa acontece no resgate e é da WeLog: a Omie não recebe mais a saída,
 * porque o saldo de lá é do produto inteiro, somado entre todos os projetos
 * que usam o mesmo kit. Cancelou, o saldo volta para o Projeto — e a pessoa
 * volta a poder escolher, já que cancelado não conta como resgatado nem
 * consome o grupo de escolha.
 */
class CancelRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = [
        'recipient_name' => 'João Colaborador',
        'cpf' => '529.982.247-25',
        'zip' => '80010-000',
        'street' => 'Rua XV de Novembro',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'Curitiba',
        'state' => 'PR',
    ];

    private Product $kit;

    private Project $project;

    private ProjectEntitlement $entitlement;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->kit = Product::create([
            'omie_code' => 'KIT-DEV-01', 'name' => 'Kit Devolução',
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);
        $this->project = Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto Devolução',
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);
        // O saldo do Projeto já carregado: é o estado de um Projeto que
        // recebeu o kit e está aberto para resgate.
        $this->project->products()->attach($this->kit->id, [
            'quantity' => 10,
            'stock_qty' => 10,
            'stock_loaded_at' => now(),
        ]);

        $this->user = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $company->id,
            'password_set_at' => now(),
        ]);
        $this->entitlement = ProjectEntitlement::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'product_id' => $this->kit->id,
        ]);
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function resgatar(): Redemption
    {
        return app(RedeemKit::class)->handle($this->entitlement, self::ADDRESS, $this->user);
    }

    /** Saldo do Projeto para o kit, lido do pivô. */
    private function saldoDoProjeto(): float
    {
        return (float) $this->project->fresh()->products()->first()->pivot->stock_qty;
    }

    public function test_resgate_debita_o_saldo_do_projeto_e_nao_o_da_omie(): void
    {
        $this->resgatar();

        $this->assertSame(9.0, $this->saldoDoProjeto());

        // O número da Omie é referência e não se mexe: ele é do produto
        // inteiro, não deste Projeto.
        $this->assertSame(100.0, (float) $this->kit->fresh()->stock_qty);
    }

    public function test_cancelar_devolve_o_saldo_ao_projeto(): void
    {
        $redemption = $this->resgatar();
        $this->assertSame(9.0, $this->saldoDoProjeto());

        $this->actingAs($this->operador())
            ->post(route('redemptions.cancel', $redemption))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(Redemption::STATUS_CANCELED, $redemption->fresh()->status);
        $this->assertSame(10.0, $this->saldoDoProjeto());

        // A pessoa pode resgatar de novo: cancelado não conta como resgatado.
        $this->assertFalse($this->entitlement->fresh()->isRedeemed());
    }

    /** Sem saldo no Projeto, o resgate para — mesmo com a Omie cheia. */
    public function test_projeto_sem_saldo_recusa_o_resgate(): void
    {
        $this->project->products()->updateExistingPivot($this->kit->id, ['stock_qty' => 0]);

        $this->expectExceptionMessage('Sem saldo neste Projeto');

        $this->resgatar();
    }

    public function test_resgate_expedido_nao_pode_ser_cancelado(): void
    {
        $redemption = $this->resgatar();
        $redemption->update(['status' => Redemption::STATUS_SHIPPED]);

        $this->actingAs($this->operador())
            ->post(route('redemptions.cancel', $redemption))
            ->assertSessionHasErrors('cancel');

        $this->assertSame(Redemption::STATUS_SHIPPED, $redemption->fresh()->status);
        // Expedido não devolve: o kit saiu da prateleira.
        $this->assertSame(9.0, $this->saldoDoProjeto());
    }
}
