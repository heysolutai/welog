<?php

namespace Tests\Feature\Auth;

use App\Mail\AccountInvitationMail;
use App\Mail\PasswordResetMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\Auth\InviteUser;
use App\Services\FluxoA\RegisterEndCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Fase 1 — autenticação do cliente final: convite, primeiro acesso, reset e
 * bloqueio de login enquanto a senha não é definida.
 */
class EndCustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function projectWithKit(): array
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $product = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit A']);
        $project = Project::create(['company_id' => $company->id, 'name' => 'Proj', 'stock_available_at' => now()]);
        $project->products()->attach($product->id, ['quantity' => 1]);

        return [$project, $product];
    }

    public function test_cadastro_de_cliente_final_cria_conta_sem_senha_e_envia_convite(): void
    {
        Mail::fake();
        [$project, $product] = $this->projectWithKit();
        $admin = User::where('email', 'admin@welog.local')->first();

        app(RegisterEndCustomer::class)->handle($project, [
            'name' => 'Cliente Final',
            'email' => 'cliente@acme.com',
            'product_id' => $product->id,
        ], $admin);

        $user = User::where('email', 'cliente@acme.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->password_set_at);
        $this->assertFalse($user->hasSetPassword());
        $this->assertNotNull($user->invited_at);

        Mail::assertQueued(AccountInvitationMail::class, fn ($m) => $m->hasTo('cliente@acme.com'));
    }

    public function test_login_bloqueado_antes_de_definir_senha_com_mensagem_orientativa(): void
    {
        Mail::fake();
        [$project, $product] = $this->projectWithKit();
        $admin = User::where('email', 'admin@welog.local')->first();

        app(RegisterEndCustomer::class)->handle($project, [
            'name' => 'Cliente Final', 'email' => 'cliente@acme.com', 'product_id' => $product->id,
        ], $admin);

        $this->post('/login', ['email' => 'cliente@acme.com', 'password' => 'qualquer-coisa'])
            ->assertSessionHasErrors('email');

        $errors = session('errors')->get('email');
        $this->assertStringContainsString('não definiu sua senha', $errors[0]);
        $this->assertGuest();
    }

    public function test_primeiro_acesso_define_senha_e_autentica(): void
    {
        $user = User::factory()->create([
            'email' => 'novo@acme.com',
            'role' => User::ROLE_END_CUSTOMER,
            'password_set_at' => null,
        ]);
        $token = Password::broker()->createToken($user);

        // Formulário de primeiro acesso renderiza
        $this->get(route('password.set.form', ['token' => $token, 'email' => $user->email]))
            ->assertInertia(fn ($p) => $p->component('Auth/SetPassword')->where('email', $user->email));

        $this->post(route('password.set'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
        ])->assertRedirect(route('portal.catalog'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertTrue($user->fresh()->hasSetPassword());
    }

    public function test_login_funciona_apos_definir_senha(): void
    {
        $user = User::factory()->create([
            'email' => 'ok@acme.com', 'role' => User::ROLE_END_CUSTOMER, 'password_set_at' => null,
        ]);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.set'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'senha-forte-123', 'password_confirmation' => 'senha-forte-123',
        ]);
        $this->post('/logout');

        $this->post('/login', ['email' => 'ok@acme.com', 'password' => 'senha-forte-123'])
            ->assertRedirect(route('portal.catalog'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_esqueci_senha_envia_link_com_resposta_neutra(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'existe@acme.com', 'role' => User::ROLE_END_CUSTOMER]);

        $this->post(route('password.email'), ['email' => 'existe@acme.com'])
            ->assertSessionHas('status');

        // Em português, na moldura da WeLog — não a notificação padrão, em inglês.
        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && str_contains($html, 'Redefinir minha senha')
                && ! str_contains($html, 'Reset Password');
        });

        // E-mail inexistente: mesma resposta neutra, sem erro
        $this->post(route('password.email'), ['email' => 'naoexiste@acme.com'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();
    }

    public function test_reset_de_senha_via_link(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@acme.com', 'role' => User::ROLE_END_CUSTOMER, 'password_set_at' => now(),
        ]);
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertInertia(fn ($p) => $p->component('Auth/ResetPassword'));

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'nova-senha-456', 'password_confirmation' => 'nova-senha-456',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Password::broker()->tokenExists($user->fresh(), $token) === false);
    }

    public function test_token_invalido_e_rejeitado(): void
    {
        $user = User::factory()->create(['email' => 'x@acme.com', 'password_set_at' => null]);

        $this->post(route('password.set'), [
            'token' => 'token-falso', 'email' => $user->email,
            'password' => 'senha-forte-123', 'password_confirmation' => 'senha-forte-123',
        ])->assertSessionHasErrors('email');

        $this->assertNull($user->fresh()->password_set_at);
    }

    public function test_reenvio_de_convite_pela_tela_do_projeto(): void
    {
        Mail::fake();
        [$project, $product] = $this->projectWithKit();
        $admin = User::where('email', 'admin@welog.local')->first();

        $entitlement = app(RegisterEndCustomer::class)->handle($project, [
            'name' => 'Pendente', 'email' => 'pendente@acme.com', 'product_id' => $product->id,
        ], $admin);

        Mail::fake(); // zera o convite do cadastro

        $this->actingAs($admin)
            ->post(route('projects.entitlements.resend', [$project->id, $entitlement->id]))
            ->assertSessionHas('status');

        Mail::assertQueued(AccountInvitationMail::class, 1);
    }

    public function test_reenvio_bloqueado_para_quem_ja_tem_senha(): void
    {
        Mail::fake();
        [$project, $product] = $this->projectWithKit();
        $admin = User::where('email', 'admin@welog.local')->first();
        $entitlement = app(RegisterEndCustomer::class)->handle($project, [
            'name' => 'Ativo', 'email' => 'ativo@acme.com', 'product_id' => $product->id,
        ], $admin);
        $entitlement->user->update(['password_set_at' => now()]);

        $this->actingAs($admin)
            ->post(route('projects.entitlements.resend', [$project->id, $entitlement->id]))
            ->assertSessionHasErrors('invite');
    }
}
