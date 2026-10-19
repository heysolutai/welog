<?php

namespace Tests\Feature;

use App\Mail\AccountInvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** `welog:mail:diagnostico` — por que o e-mail não chegou a alguém. */
class MailDiagnoseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_avisa_quando_a_conta_nem_existe(): void
    {
        User::factory()->create(['email' => 'fulano.outro@wegift.com.br']);

        $this->artisan('welog:mail:diagnostico', ['email' => 'fulano@wegift.com.br'])
            ->expectsOutputToContain('Nenhum usuário com este e-mail')
            // Sugere o parecido, que costuma ser o erro de digitação.
            ->expectsOutputToContain('fulano.outro@wegift.com.br')
            ->assertFailed();
    }

    public function test_mostra_estado_do_convite_de_quem_existe(): void
    {
        User::factory()->create([
            'email' => 'fulano@wegift.com.br',
            'role' => User::ROLE_ADMIN_WEGIFT,
            'password_set_at' => null,
            'invited_at' => null,
        ]);

        $this->artisan('welog:mail:diagnostico', ['email' => 'fulano@wegift.com.br'])
            ->expectsOutputToContain('NUNCA')
            ->assertSuccessful();
    }

    public function test_reenvia_agora_e_marca_o_convite(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'fulano@wegift.com.br',
            'password_set_at' => null,
            'invited_at' => null,
        ]);

        $this->artisan('welog:mail:diagnostico', ['email' => 'fulano@wegift.com.br', '--reenviar' => true])
            ->assertSuccessful();

        Mail::assertSent(AccountInvitationMail::class, fn ($m) => $m->hasTo('fulano@wegift.com.br'));
        $this->assertNotNull($user->fresh()->invited_at);
    }

    public function test_nao_reenvia_para_quem_ja_tem_senha(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'fulano@wegift.com.br', 'password_set_at' => now()]);

        $this->artisan('welog:mail:diagnostico', ['email' => 'fulano@wegift.com.br', '--reenviar' => true])
            ->expectsOutputToContain('já definiu a senha')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }
}
