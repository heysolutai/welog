<?php

namespace Tests\Feature;

use App\Mail\ProjectReleasedMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\FluxoA\RegisterEndCustomer;
use App\Services\FluxoA\ReleaseProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Liberação do Projeto: quem pode (só WeGift crítico — o Admin Cliente da
 * empresa não) e o que o e-mail carrega (link de criar senha para quem ainda não
 * tem, portal para quem já tem).
 */
class ReleaseProjectTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Project $project;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();

        $this->company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        // Saldo espelhado igual ao pedido (10): a liberação exige saldo real, e
        // estes testes são sobre permissão e e-mail — não sobre estoque.
        $this->product = Product::create([
            'omie_code' => 'KIT-1', 'name' => 'Kit', 'is_kit' => true,
            'stock_qty' => 10, 'stock_synced_at' => now(),
        ]);
        $this->project = Project::create([
            'company_id' => $this->company->id, 'name' => 'Natal', 'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK, 'stock_available_at' => now(),
        ]);
        $this->project->products()->attach($this->product->id, ['quantity' => 10]);
    }

    private function entitle(array $userAttrs): ProjectEntitlement
    {
        $user = User::factory()->create(array_merge([
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->company->id,
        ], $userAttrs));

        return ProjectEntitlement::create([
            'project_id' => $this->project->id, 'user_id' => $user->id, 'product_id' => $this->product->id,
        ]);
    }

    public function test_admin_wegift_libera_e_admin_cliente_da_empresa_nao(): void
    {
        $this->entitle(['password_set_at' => now()]);
        $rh = User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->company->id]);

        // RH cadastra e acompanha, mas a abertura do resgate é da WeGift.
        $this->actingAs($rh)->post(route('projects.release', $this->project))->assertForbidden();
        $this->assertFalse($this->project->fresh()->isReleased());

        $admin = User::where('email', 'admin@welog.local')->first();
        $this->actingAs($admin)
            ->post(route('projects.release', $this->project))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->project->fresh()->isReleased());
        $this->assertSame($admin->id, $this->project->fresh()->released_by);
        Mail::assertQueued(ProjectReleasedMail::class, 1);
    }

    public function test_admin_cliente_de_outra_empresa_e_n2_nao_liberam(): void
    {
        $this->entitle(['password_set_at' => now()]);
        $outra = Company::create(['name' => 'Outra', 'cnpj' => '98765432000110']);

        $intruso = User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $outra->id]);
        $this->actingAs($intruso)->post(route('projects.release', $this->project))->assertForbidden();

        $n2 = User::factory()->create(['role' => User::ROLE_WEGIFT_N2]);
        $this->actingAs($n2)->post(route('projects.release', $this->project))->assertForbidden();

        $this->assertFalse($this->project->fresh()->isReleased());
        Mail::assertNothingQueued();
    }

    public function test_email_de_liberacao_leva_link_de_criar_senha_para_quem_ainda_nao_tem(): void
    {
        $pendente = $this->entitle(['password_set_at' => null]);
        $comSenha = $this->entitle(['password_set_at' => now()]);
        $admin = User::where('email', 'admin@welog.local')->first();

        $this->actingAs($admin)->post(route('projects.release', $this->project))->assertSessionHasNoErrors();

        Mail::assertQueued(ProjectReleasedMail::class, function (ProjectReleasedMail $mail) use ($pendente) {
            return $mail->entitlement->id === $pendente->id
                && $mail->setupToken !== null
                && Password::broker('invites')->tokenExists($pendente->user, $mail->setupToken);
        });

        Mail::assertQueued(ProjectReleasedMail::class, function (ProjectReleasedMail $mail) use ($comSenha) {
            return $mail->entitlement->id === $comSenha->id && $mail->setupToken === null;
        });

        $this->assertNotNull($pendente->user->fresh()->invited_at);
    }

    /**
     * O Projeto pode ser liberado com a lista ainda vazia.
     *
     * A liberação é a decisão de que o kit pode sair; exigir gente cadastrada
     * invertia a ordem do trabalho e segurava o Projeto por um dado que chega
     * depois — contratação nova, alguém que faltou na planilha.
     */
    public function test_libera_sem_nenhum_colaborador_cadastrado(): void
    {
        $admin = User::where('email', 'admin@welog.local')->first();

        app(ReleaseProject::class)->handle($this->project, $admin);

        $this->assertTrue($this->project->fresh()->isReleased());
        Mail::assertNothingQueued();
    }

    /** E quem entra DEPOIS recebe o aviso na hora do cadastro. */
    public function test_colaborador_cadastrado_depois_da_liberacao_recebe_o_aviso(): void
    {
        $admin = User::where('email', 'admin@welog.local')->first();
        app(ReleaseProject::class)->handle($this->project, $admin);
        Mail::fake();

        $entitlement = app(RegisterEndCustomer::class)->handle($this->project->fresh(), [
            'name' => 'Quem Chegou Depois',
            'email' => 'depois@acme.com.br',
            'cpf' => '39053344705',
            'product_ids' => [$this->product->id],
        ], $admin);

        Mail::assertQueued(ProjectReleasedMail::class, fn (ProjectReleasedMail $mail) => $mail->entitlement->id === $entitlement->id);

        // Notificado no ato: sem isto a tela mostraria "aguardando aviso" para
        // alguém que já recebeu.
        $this->assertNotNull($entitlement->fresh()->notified_at);
    }

    public function test_link_do_convite_continua_valido_depois_de_uma_hora(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->company->id, 'password_set_at' => null]);
        $token = Password::broker('invites')->createToken($user);

        $this->travel(3)->hours();
        $this->assertTrue(Password::broker('invites')->tokenExists($user, $token));

        $this->travel(8)->days();
        $this->assertFalse(Password::broker('invites')->tokenExists($user, $token));
    }
}
