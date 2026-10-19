<?php

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Vetores de ataque explícitos:
 *   - IDOR (Insecure Direct Object Reference): acesso a objeto de outro dono.
 *   - Race condition: operações concorrentes que poderiam duplicar efeito.
 *
 * As proteções vivem no domínio (checagem de posse + `lockForUpdate`); aqui
 * garantimos que elas de fato barram o ataque.
 */
class IdorAndRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = [
        'recipient_name' => 'João Colaborador',
        // CPF obrigatório no resgate (inclusão UAT)
        'cpf' => '529.982.247-25',
        'zip' => '80010-000',
        'street' => 'Rua XV de Novembro',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'Curitiba',
        'state' => 'PR',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // O resgate confirmado dispara a baixa de estoque na Omie (gateway
        // fake persiste estado em disco): sem o fake de Storage o estado
        // vazaria entre execuções da suíte.
        Storage::fake('local');
    }

    /** @return array{0: ProjectEntitlement, 1: User} entitlement liberado + seu dono */
    private function releasedProjectWithEntitlement(): array
    {
        $company = Company::create(['name' => 'Acme Tecnologia', 'cnpj' => '11111111000191']);
        $product = Product::create(['omie_code' => 'KIT-ONBOARD-01', 'name' => 'Kit Onboarding']);
        $project = Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto Onboarding',
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);

        // O kit precisa ser item DO PEDIDO: é a linha do pivô que guarda o
        // saldo do Projeto, e sem ela o resgate é recusado antes de chegar à
        // trava que este teste existe para exercitar.
        $project->products()->attach($product->id, ['quantity' => 10]);

        $owner = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $company->id,
            'password_set_at' => now(),
        ]);

        $entitlement = ProjectEntitlement::create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'product_id' => $product->id,
        ]);

        return [$entitlement, $owner];
    }

    private function otherEndCustomer(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'password_set_at' => now(),
        ]);
    }

    // ---- IDOR ---------------------------------------------------------------

    public function test_idor_cliente_final_nao_ve_a_tela_de_resgate_alheia(): void
    {
        [$entitlement] = $this->releasedProjectWithEntitlement();

        $this->actingAs($this->otherEndCustomer())
            ->get(route('portal.redeem.form', $entitlement))
            ->assertForbidden();
    }

    public function test_idor_cliente_final_nao_efetiva_resgate_alheio(): void
    {
        [$entitlement] = $this->releasedProjectWithEntitlement();

        $this->actingAs($this->otherEndCustomer())
            ->post(route('portal.redeem', $entitlement), self::ADDRESS)
            ->assertSessionHasErrors('redeem');

        // O intruso não gerou resgate nenhum
        $this->assertDatabaseCount('redemptions', 0);
    }

    public function test_idor_admin_cliente_nao_acessa_projeto_de_outra_empresa(): void
    {
        $empresaA = Company::create(['name' => 'Empresa A', 'cnpj' => '11111111000191']);
        $empresaB = Company::create(['name' => 'Empresa B', 'cnpj' => '22222222000192']);
        $projetoB = Project::create(['company_id' => $empresaB->id, 'name' => 'Projeto da Empresa B']);

        $adminDaA = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $empresaA->id,
        ]);

        $this->actingAs($adminDaA)
            ->get(route('projects.show', $projetoB))
            ->assertForbidden();
    }

    // ---- Race condition -----------------------------------------------------

    public function test_race_resgate_duplo_do_mesmo_kit_gera_um_unico_resgate(): void
    {
        [$entitlement, $owner] = $this->releasedProjectWithEntitlement();

        // Primeiro resgate: confirmado
        $this->actingAs($owner)
            ->post(route('portal.redeem', $entitlement), self::ADDRESS)
            ->assertRedirect(route('portal.redemptions'));

        // Segundo (duplo-clique / request paralelo): a trava pessimista barra
        $this->actingAs($owner)
            ->post(route('portal.redeem', $entitlement), self::ADDRESS)
            ->assertSessionHasErrors('redeem');

        $this->assertDatabaseCount('redemptions', 1);
    }
}
