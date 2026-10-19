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
 * DELETE /projects/{project}/admins/{user}: tirar um Admin do Projeto.
 *
 * A conta do Admin de Projeto existe só para aquele Projeto. Sem histórico,
 * ela é apagada; com histórico, desativada, para o registro do que ele fez
 * continuar de pé. Nos dois casos a pessoa sai da aba Admin e perde o acesso.
 */
class ProjectAdminRemovalTest extends TestCase
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

    private function adminDoProjeto(?Project $projeto = null): User
    {
        $projeto ??= $this->projeto;

        return User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $projeto->company_id,
            'admin_project_id' => $projeto->id,
        ]);
    }

    private function remover(User $admin, ?User $quem = null)
    {
        return $this->actingAs($quem ?? $this->adminWeGift())
            ->delete(route('projects.admins.destroy', [$this->projeto, $admin]));
    }

    public function test_admin_sem_historico_e_apagado(): void
    {
        $admin = $this->adminDoProjeto();

        $this->remover($admin)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertModelMissing($admin);
    }

    /** Com histórico no nome dele, o banco não deixa apagar: a conta é desativada. */
    public function test_admin_com_historico_e_desativado_e_sai_da_aba(): void
    {
        $admin = $this->adminDoProjeto();
        $this->projeto->update(['released_by' => $admin->id]);

        $this->remover($admin)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($admin->fresh()->active);
        $this->assertSame($admin->id, $this->projeto->fresh()->released_by);

        $this->actingAs($this->adminWeGift())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn ($p) => $p->where('project.admins', []));
    }

    /** Cadastrar de novo a mesma pessoa reativa a conta, em vez de recusar o e-mail. */
    public function test_readicionar_reativa_a_conta_desativada(): void
    {
        $admin = $this->adminDoProjeto();
        $admin->forceFill(['password_set_at' => null])->save();
        $this->projeto->update(['released_by' => $admin->id]);
        $this->remover($admin);

        $this->actingAs($this->adminWeGift())
            ->post(route('company.admins.store'), [
                'name' => 'Nome Novo',
                'email' => strtoupper($admin->email),
                'project_id' => $this->projeto->id,
            ])
            ->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertTrue($admin->active);
        $this->assertSame('Nome Novo', $admin->name);
        $this->assertSame(1, User::where('email', $admin->email)->count());
        Mail::assertQueued(AccountInvitationMail::class);
    }

    public function test_email_de_outra_conta_continua_recusado(): void
    {
        $colaborador = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
        ]);

        $this->actingAs($this->adminWeGift())
            ->post(route('company.admins.store'), [
                'name' => 'Alguém',
                'email' => $colaborador->email,
                'project_id' => $this->projeto->id,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(User::ROLE_END_CUSTOMER, $colaborador->fresh()->role);
    }

    /** Admin de OUTRO Projeto não sai por este endereço. */
    public function test_admin_de_outro_projeto_nao_e_removido(): void
    {
        $outro = Project::create(['company_id' => $this->empresa->id, 'name' => 'Projeto A2']);
        $admin = $this->adminDoProjeto($outro);

        $this->remover($admin)->assertSessionHasErrors('remove');

        $this->assertModelExists($admin);
    }

    /** Só Admin WeGift: nem o operador N1, nem o Admin Cliente. */
    public function test_so_admin_wegift_remove(): void
    {
        $admin = $this->adminDoProjeto();

        $operador = User::where('email', 'operador@welog.local')->firstOrFail();
        $this->remover($admin, $operador)->assertForbidden();

        $adminCliente = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresa->id,
        ]);
        $this->remover($admin, $adminCliente)->assertForbidden();

        $this->assertModelExists($admin);
    }
}
