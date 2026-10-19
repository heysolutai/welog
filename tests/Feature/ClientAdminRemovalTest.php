<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DELETE /admin/companies/{company}/admins/{user}: tirar um Admin Cliente.
 *
 * O Admin Cliente vê todos os Projetos da empresa, então sai de todos. Sem
 * histórico a conta é apagada; com histórico, desativada. A reatribuição é
 * cadastrar a mesma pessoa como Admin de um Projeto: a conta é reaproveitada.
 */
class ClientAdminRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Mail::fake();

        $this->empresa = Company::create(['name' => 'Empresa A', 'cnpj' => '11111111000191']);
        $this->projeto = Project::create(['company_id' => $this->empresa->id, 'name' => 'Projeto A1']);
    }

    private function adminWeGift(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    private function adminCliente(?Company $empresa = null): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => ($empresa ?? $this->empresa)->id,
        ]);
    }

    private function remover(User $admin, ?User $quem = null, ?Company $empresa = null)
    {
        return $this->actingAs($quem ?? $this->adminWeGift())
            ->delete(route('admin.companies.admins.destroy', [$empresa ?? $this->empresa, $admin]));
    }

    public function test_sem_historico_e_apagado(): void
    {
        $admin = $this->adminCliente();

        $this->remover($admin)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertModelMissing($admin);
    }

    public function test_com_historico_e_desativado_e_sai_das_listas(): void
    {
        $fica = $this->adminCliente();
        $admin = $this->adminCliente();
        $this->projeto->update(['released_by' => $admin->id]);

        $this->remover($admin)->assertSessionHasNoErrors();

        $this->assertFalse($admin->fresh()->active);

        // Sai quem foi removido, e SÓ ele: quem continua ativo segue listado.
        $this->actingAs($this->adminWeGift())
            ->get(route('admin.companies.show', $this->empresa))
            ->assertInertia(fn ($p) => $p
                ->has('company.admins', 1)
                ->where('company.admins.0.id', $fica->id));

        $this->actingAs($this->adminWeGift())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn ($p) => $p
                ->has('project.company.admins', 1)
                ->where('project.company.admins.0.id', $fica->id));
    }

    /** A aba Admin do Projeto mostra também os Admin Cliente da empresa. */
    public function test_aba_admin_do_projeto_lista_o_admin_cliente(): void
    {
        $admin = $this->adminCliente();

        $this->actingAs($this->adminWeGift())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn ($p) => $p
                ->where('project.company.admins.0.id', $admin->id)
                ->where('project.company.id', $this->empresa->id));
    }

    /** Reatribuição: o Admin Cliente desativado volta como Admin de UM Projeto. */
    public function test_vira_admin_de_um_projeto_com_a_mesma_conta(): void
    {
        $admin = $this->adminCliente();
        $this->projeto->update(['released_by' => $admin->id]);
        $this->remover($admin);

        $this->actingAs($this->adminWeGift())
            ->post(route('company.admins.store'), [
                'name' => $admin->name,
                'email' => $admin->email,
                'project_id' => $this->projeto->id,
            ])
            ->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertTrue($admin->active);
        $this->assertSame(User::ROLE_PROJECT_ADMIN, $admin->role);
        $this->assertSame($this->projeto->id, $admin->admin_project_id);
    }

    /** E o caminho de volta: Admin de Projeto desativado vira Admin Cliente. */
    public function test_admin_de_projeto_desativado_volta_como_admin_cliente(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->projeto->id,
            'active' => false,
        ]);

        $this->actingAs($this->adminWeGift())
            ->post(route('admin.companies.admins.store', $this->empresa), [
                'name' => $admin->name,
                'email' => $admin->email,
            ])
            ->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertTrue($admin->active);
        $this->assertSame(User::ROLE_ADMIN_CLIENT, $admin->role);
        $this->assertNull($admin->admin_project_id);
    }

    /** Conta desativada de OUTRA empresa não é reaproveitada. */
    public function test_admin_desativado_de_outra_empresa_continua_recusado(): void
    {
        $outra = Company::create(['name' => 'Empresa B', 'cnpj' => '22222222000192']);
        $admin = $this->adminCliente($outra);
        $admin->forceFill(['active' => false])->save();

        $this->actingAs($this->adminWeGift())
            ->post(route('admin.companies.admins.store', $this->empresa), [
                'name' => 'Alguém',
                'email' => $admin->email,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame($outra->id, $admin->fresh()->company_id);
    }

    public function test_admin_de_outra_empresa_nao_sai_por_este_endereco(): void
    {
        $outra = Company::create(['name' => 'Empresa B', 'cnpj' => '22222222000192']);
        $admin = $this->adminCliente($outra);

        $this->remover($admin)->assertSessionHasErrors('remove');

        $this->assertModelExists($admin);
    }

    public function test_so_admin_wegift_remove(): void
    {
        $admin = $this->adminCliente();

        $this->remover($admin, User::where('email', 'operador@welog.local')->firstOrFail())->assertForbidden();
        $this->remover($admin, $this->adminCliente())->assertForbidden();

        $this->assertModelExists($admin);
    }
}
