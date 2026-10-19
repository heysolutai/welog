<?php

namespace Tests\Feature\Admin;

use App\Mail\AccountInvitationMail;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminAreasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    // ---- Usuários WeGift -----------------------------------------------------

    public function test_admin_cria_usuario_wegift_e_envia_convite(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Novo Operador',
                'email' => 'novo@welog.local',
                'role' => User::ROLE_WEGIFT_N1,
            ])->assertSessionHas('status');

        $user = User::where('email', 'novo@welog.local')->first();
        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_WEGIFT_N1, $user->role);
        $this->assertNull($user->password_set_at);
        Mail::assertQueued(AccountInvitationMail::class);
    }

    public function test_nao_admin_nao_acessa_gestao_de_usuarios(): void
    {
        $n1 = User::where('email', 'operador@welog.local')->first();
        $this->actingAs($n1)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($n1)->post(route('admin.users.store'), [])->assertForbidden();
    }

    public function test_admin_nao_pode_rebaixar_a_si_mesmo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), ['name' => $admin->name, 'role' => User::ROLE_WEGIFT_N2])
            ->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_ADMIN_WEGIFT, $admin->fresh()->role);
    }

    public function test_admin_nao_pode_desativar_a_si_mesmo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.users.toggle', $admin))->assertSessionHasErrors('active');
        $this->assertTrue($admin->fresh()->active);
    }

    public function test_admin_ativa_e_desativa_outro_usuario(): void
    {
        $n2 = User::where('email', 'consulta@welog.local')->first();

        $this->actingAs($this->admin())->post(route('admin.users.toggle', $n2));
        $this->assertFalse($n2->fresh()->active);

        $this->actingAs($this->admin())->post(route('admin.users.toggle', $n2));
        $this->assertTrue($n2->fresh()->active);
    }

    // ---- Empresas ------------------------------------------------------------

    public function test_admin_cadastra_empresa_e_admin_cliente(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.companies.store'), ['name' => 'ACME', 'cnpj' => '12.345.678/0001-90'])
            ->assertSessionHas('status');

        $company = Company::where('cnpj', '12345678000190')->first();
        $this->assertNotNull($company);

        $this->actingAs($this->admin())
            ->post(route('admin.companies.admins.store', $company), [
                'name' => 'Admin ACME', 'email' => 'admin@acme.com',
            ])->assertSessionHas('status');

        $adminCliente = User::where('email', 'admin@acme.com')->first();
        $this->assertSame(User::ROLE_ADMIN_CLIENT, $adminCliente->role);
        $this->assertSame($company->id, $adminCliente->company_id);
        $this->assertNull($adminCliente->password_set_at);
        Mail::assertQueued(AccountInvitationMail::class);
    }

    public function test_admin_reenvia_convite_de_admin_cliente_pendente(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $pendente = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $company->id, 'password_set_at' => null,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.companies.admins.resend', [$company, $pendente]))
            ->assertSessionHas('status');

        Mail::assertQueued(AccountInvitationMail::class, fn ($mail) => $mail->hasTo($pendente->email));
    }

    public function test_reenvio_de_convite_e_recusado_quando_senha_ja_definida_ou_empresa_errada(): void
    {
        $companyA = Company::create(['name' => 'A', 'cnpj' => '11111111000191']);
        $companyB = Company::create(['name' => 'B', 'cnpj' => '22222222000192']);

        $ativo = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $companyA->id, 'password_set_at' => now(),
        ]);
        $this->actingAs($this->admin())
            ->post(route('admin.companies.admins.resend', [$companyA, $ativo]))
            ->assertSessionHasErrors('invite');

        $pendente = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $companyA->id, 'password_set_at' => null,
        ]);
        $this->actingAs($this->admin())
            ->post(route('admin.companies.admins.resend', [$companyB, $pendente]))
            ->assertNotFound();

        Mail::assertNothingQueued();
    }

    public function test_cnpj_duplicado_e_rejeitado(): void
    {
        Company::create(['name' => 'X', 'cnpj' => '12345678000190']);

        $this->actingAs($this->admin())
            ->post(route('admin.companies.store'), ['name' => 'Y', 'cnpj' => '12345678000190'])
            ->assertSessionHasErrors('cnpj');
    }

    // ---- Escopo do Admin Cliente --------------------------------------------

    public function test_admin_cliente_ve_apenas_projetos_da_propria_empresa(): void
    {
        $companyA = Company::create(['name' => 'A', 'cnpj' => '11111111000191']);
        $companyB = Company::create(['name' => 'B', 'cnpj' => '22222222000192']);
        $projA = Project::create(['company_id' => $companyA->id, 'name' => 'Proj A']);
        $projB = Project::create(['company_id' => $companyB->id, 'name' => 'Proj B']);

        $clientAdmin = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $companyA->id,
        ]);

        // Lista: só a própria empresa
        $this->actingAs($clientAdmin)->get(route('projects.index'))
            ->assertInertia(fn ($p) => $p->component('Projects/Index')->has('projects.data', 1));

        // Detalhe: acessa o próprio, bloqueia o de outra empresa
        $this->actingAs($clientAdmin)->get(route('projects.show', $projA))->assertOk();
        $this->actingAs($clientAdmin)->get(route('projects.show', $projB))->assertForbidden();
    }

    public function test_admin_cliente_nao_acessa_areas_wegift(): void
    {
        $clientAdmin = User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT]);

        $this->actingAs($clientAdmin)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($clientAdmin)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($clientAdmin)->get(route('imports.index'))->assertForbidden();
    }
}
