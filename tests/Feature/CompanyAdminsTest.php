<?php

namespace Tests\Feature;

use App\Mail\AccountInvitationMail;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * POST /company/admins: cadastro do Admin DO PROJETO, e só dele.
 *
 * O endpoint já criava também o Admin Cliente, com um campo "tipo". A regra
 * que o desfez é simples: ninguém cadastra o próprio nível. Admin Cliente
 * enxerga a empresa inteira, então quem o cria é a WeGift, na ficha da
 * Empresa (outra tela, outro endpoint); o Admin de Projeto cadastra Admin do
 * Projeto dele.
 *
 * Aqui fica a matriz de quem pode criar onde.
 */
class CompanyAdminsTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresaA;

    private Company $empresaB;

    private Project $projetoA;

    private Project $projetoB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Mail::fake();

        $this->empresaA = Company::create(['name' => 'Empresa A', 'cnpj' => '11111111000191']);
        $this->empresaB = Company::create(['name' => 'Empresa B', 'cnpj' => '22222222000192']);
        $this->projetoA = Project::create(['company_id' => $this->empresaA->id, 'name' => 'Projeto A1']);
        $this->projetoB = Project::create(['company_id' => $this->empresaB->id, 'name' => 'Projeto B1']);
    }

    private function adminClienteDaA(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresaA->id,
        ]);
    }

    private function adminDoProjetoA(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresaA->id,
            'admin_project_id' => $this->projetoA->id,
        ]);
    }

    public function test_admin_cliente_cria_admin_de_projeto_da_propria_empresa(): void
    {
        $this->actingAs($this->adminClienteDaA())
            ->post(route('company.admins.store'), [
                'name' => 'Admin do Projeto',
                'email' => 'projeto@empresa-a.com.br',
                'project_id' => $this->projetoA->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'projeto@empresa-a.com.br',
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresaA->id,
            'admin_project_id' => $this->projetoA->id,
        ]);

        Mail::assertQueued(AccountInvitationMail::class);
    }

    /**
     * O Admin de Projeto divide o trabalho: cadastra outro admin DO SEU
     * Projeto, com o mesmo alcance que ele tem.
     */
    public function test_admin_de_projeto_cria_outro_admin_do_proprio_projeto(): void
    {
        $this->actingAs($this->adminDoProjetoA())
            ->post(route('company.admins.store'), [
                'name' => 'Colega do Projeto',
                'email' => 'colega@empresa-a.com.br',
                'project_id' => $this->projetoA->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'colega@empresa-a.com.br',
            'role' => User::ROLE_PROJECT_ADMIN,
            'admin_project_id' => $this->projetoA->id,
        ]);
    }

    /** Nem para outro Projeto da mesma empresa: ele só administra o dele. */
    public function test_admin_de_projeto_nao_cria_admin_de_outro_projeto(): void
    {
        $outro = Project::create(['company_id' => $this->empresaA->id, 'name' => 'Projeto A2']);

        $this->actingAs($this->adminDoProjetoA())
            ->post(route('company.admins.store'), [
                'name' => 'Vizinho',
                'email' => 'vizinho@empresa-a.com.br',
                'project_id' => $outro->id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseMissing('users', ['email' => 'vizinho@empresa-a.com.br']);
    }

    /** Projeto de outra empresa é porta dos fundos para fora de casa. */
    public function test_admin_cliente_nao_alcanca_projeto_de_outra_empresa(): void
    {
        $this->actingAs($this->adminClienteDaA())
            ->post(route('company.admins.store'), [
                'name' => 'Admin Intruso',
                'email' => 'intruso@empresa-a.com.br',
                'project_id' => $this->projetoB->id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseMissing('users', ['email' => 'intruso@empresa-a.com.br']);
    }

    public function test_wegift_critica_cria_admin_de_projeto_em_qualquer_empresa(): void
    {
        $admin = User::where('email', 'admin@welog.local')->first();

        $this->actingAs($admin)
            ->post(route('company.admins.store'), [
                'name' => 'Admin da B',
                'email' => 'admin.b@empresa-b.com.br',
                'project_id' => $this->projetoB->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'admin.b@empresa-b.com.br',
            'role' => User::ROLE_PROJECT_ADMIN,
            // A empresa vem do Projeto: ninguém precisa dizer qual é.
            'company_id' => $this->empresaB->id,
            'admin_project_id' => $this->projetoB->id,
        ]);
    }

    /** Sem Projeto não há o que cadastrar: é ele que define tudo o mais. */
    public function test_projeto_e_obrigatorio(): void
    {
        $this->actingAs($this->adminClienteDaA())
            ->post(route('company.admins.store'), [
                'name' => 'Sem Projeto',
                'email' => 'sem.projeto@empresa-a.com.br',
            ])
            ->assertSessionHasErrors('project_id');
    }

    public function test_email_ja_usado_por_outra_conta_e_recusado(): void
    {
        $existente = User::factory()->create(['role' => User::ROLE_END_CUSTOMER]);

        $this->actingAs($this->adminClienteDaA())
            ->post(route('company.admins.store'), [
                'name' => 'Repetido',
                'email' => $existente->email,
                'project_id' => $this->projetoA->id,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_n2_e_cliente_final_nao_criam_admins(): void
    {
        $n2 = User::where('email', 'consulta@welog.local')->first();
        $cliente = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresaA->id,
        ]);

        foreach ([$n2, $cliente] as $usuario) {
            $this->actingAs($usuario)
                ->post(route('company.admins.store'), [
                    'name' => 'Tentativa',
                    'email' => 'tentativa'.$usuario->id.'@empresa-a.com.br',
                    'project_id' => $this->projetoA->id,
                ])
                ->assertForbidden();
        }
    }
}
