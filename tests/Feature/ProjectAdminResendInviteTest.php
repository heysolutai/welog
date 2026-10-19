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
 * POST /projects/{project}/admins/{user}/resend-invite: o convite de novo
 * para o Admin do Projeto que ainda não criou a senha. O primeiro pode ter
 * ficado na quarentena do cliente, e a aba Admin não tinha como reenviar.
 */
class ProjectAdminResendInviteTest extends TestCase
{
    use RefreshDatabase;

    private Project $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Mail::fake();

        $empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '11111111000191']);
        $this->projeto = Project::create(['company_id' => $empresa->id, 'name' => '85 anos']);
    }

    private function adminDoProjeto(Project $projeto, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $projeto->company_id,
            'admin_project_id' => $projeto->id,
            'password_set_at' => null,
        ]);
    }

    private function reenviar(User $admin, ?User $quem = null, ?Project $projeto = null)
    {
        return $this->actingAs($quem ?? User::where('email', 'admin@welog.local')->firstOrFail())
            ->post(route('projects.admins.resend', [$projeto ?? $this->projeto, $admin]));
    }

    public function test_convite_pendente_sai_de_novo(): void
    {
        $admin = $this->adminDoProjeto($this->projeto);

        $this->reenviar($admin)->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');

        Mail::assertQueued(AccountInvitationMail::class, fn ($m) => $m->hasTo($admin->email));
        $this->assertNotNull($admin->fresh()->invited_at);
    }

    public function test_quem_ja_criou_a_senha_nao_recebe(): void
    {
        $admin = $this->adminDoProjeto($this->projeto, ['password_set_at' => now()]);

        $this->reenviar($admin)->assertSessionHasErrors('invite');

        Mail::assertNothingQueued();
    }

    /** O Admin de outro Projeto não é reenviado por este. */
    public function test_admin_de_outro_projeto_e_404(): void
    {
        $outro = Project::create(['company_id' => $this->projeto->company_id, 'name' => 'Outro']);
        $admin = $this->adminDoProjeto($outro);

        $this->reenviar($admin)->assertNotFound();

        Mail::assertNothingQueued();
    }

    /** Mesmo nível de quem tira o Admin: o cliente não reenvia por aqui. */
    public function test_admin_cliente_nao_reenvia(): void
    {
        $admin = $this->adminDoProjeto($this->projeto);
        $cliente = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->projeto->company_id,
        ]);

        $this->reenviar($admin, $cliente)->assertForbidden();

        Mail::assertNothingQueued();
    }
}
