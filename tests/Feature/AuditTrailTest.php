<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use Tests\TestCase;

/**
 * Trilha de auditoria — a evidência do "quem fez o quê" que os questionários
 * de segurança dos clientes pedem.
 *
 * Os testes guardam três promessas: o que é sensível fica registrado, o
 * registro não pode ser mexido, e só o Admin WeGift o enxerga.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->admin = User::where('email', 'admin@welog.local')->firstOrFail();
        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Acesso
    // ------------------------------------------------------------------

    public function test_login_fica_registrado_com_ip_e_atualiza_ultimo_acesso(): void
    {
        $this->post('/login', ['email' => 'operador@welog.local', 'password' => 'password']);

        $log = AuditLog::where('action', AuditLog::LOGIN)->firstOrFail();
        $this->assertSame($this->operador->id, $log->user_id);
        $this->assertSame('operador@welog.local', $log->user_email);
        $this->assertNotNull($log->ip);
        $this->assertNotNull($this->operador->fresh()->last_login_at);
    }

    /** Tentativa falha registra o e-mail tentado — nunca a senha. */
    public function test_login_falho_registra_o_email_e_nao_a_senha(): void
    {
        $this->post('/login', ['email' => 'operador@welog.local', 'password' => 'senha-errada-123']);

        $log = AuditLog::where('action', AuditLog::LOGIN_FAILED)->firstOrFail();
        $this->assertSame('operador@welog.local', $log->properties['email_tentado']);
        $this->assertSame($this->operador->id, $log->user_id);
        $this->assertStringNotContainsString('senha-errada-123', json_encode($log->toArray()));
    }

    public function test_logout_fica_registrado(): void
    {
        $this->actingAs($this->operador)->post('/logout');

        $this->assertTrue(AuditLog::where('action', AuditLog::LOGOUT)->where('user_id', $this->operador->id)->exists());
    }

    // ------------------------------------------------------------------
    // Permissões e dados pessoais
    // ------------------------------------------------------------------

    /** Mudança de perfil é a alteração de permissão: o "de → para" fica registrado. */
    public function test_mudanca_de_perfil_registra_antes_e_depois(): void
    {
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->operador), [
            'name' => $this->operador->name,
            'role' => User::ROLE_WEGIFT_N2,
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', AuditLog::USER_UPDATED)->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(User::ROLE_WEGIFT_N1, $log->properties['antes']['role']);
        $this->assertSame(User::ROLE_WEGIFT_N2, $log->properties['depois']['role']);
        $this->assertSame((string) $this->operador->id, (string) $log->subject_id);
    }

    public function test_desativar_usuario_fica_registrado(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.toggle', $this->operador));

        $this->assertTrue(AuditLog::where('action', AuditLog::USER_DEACTIVATED)->exists());
    }

    /** Planilha com nome, e-mail e CPF saindo da plataforma. */
    public function test_exportar_relatorio_de_resgates_fica_registrado(): void
    {
        $this->actingAs($this->operador)->get(route('reports.redemptions'))->assertOk();

        $log = AuditLog::where('action', AuditLog::EXPORT_DOWNLOADED)->firstOrFail();
        $this->assertSame('resgates', $log->properties['relatorio']);
        $this->assertSame($this->operador->id, $log->user_id);
    }

    // ------------------------------------------------------------------
    // Integridade
    // ------------------------------------------------------------------

    public function test_registro_nao_pode_ser_alterado_nem_apagado(): void
    {
        Auditoria::registrar(AuditLog::LOGIN, 'Entrou.', ator: $this->operador);
        $log = AuditLog::firstOrFail();

        try {
            $log->update(['description' => 'adulterado']);
            $this->fail('A alteração deveria ter sido recusada.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('A exclusão deveria ter sido recusada.');
        } catch (LogicException) {
        }

        $this->assertSame('Entrou.', $log->fresh()->description);
    }

    /** O registro sobrevive à exclusão da conta e continua dizendo quem foi. */
    public function test_registro_guarda_quem_foi_mesmo_depois_de_a_conta_sumir(): void
    {
        Auditoria::registrar(AuditLog::LOGIN, 'Entrou.', ator: $this->operador);

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->operador));

        $log = AuditLog::where('action', AuditLog::LOGIN)->firstOrFail();
        $this->assertNull($log->user_id);
        $this->assertSame('operador@welog.local', $log->user_email);
        $this->assertTrue(AuditLog::where('action', AuditLog::USER_DELETED)->exists());
    }

    public function test_retencao_apaga_so_o_que_passou_da_janela(): void
    {
        config(['welog.audit.retention_days' => 730]);
        Auditoria::registrar(AuditLog::LOGIN, 'Antigo.', ator: $this->operador);
        Auditoria::registrar(AuditLog::LOGIN, 'Recente.', ator: $this->operador);
        AuditLog::where('description', 'Antigo.')->toBase()->update(['created_at' => now()->subDays(731)]);

        Artisan::call('model:prune', ['--model' => [AuditLog::class]]);

        $this->assertSame(['Recente.'], AuditLog::pluck('description')->all());
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

    public function test_so_o_admin_wegift_ve_a_trilha(): void
    {
        $this->actingAs($this->operador)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($this->operador)->get(route('admin.audit.export'))->assertForbidden();

        Auditoria::registrar(AuditLog::LOGIN, 'Entrou na plataforma.', ator: $this->operador);

        $this->actingAs($this->admin)->get(route('admin.audit.index', ['group' => 'auth']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/Audit/Index')
                ->where('logs.total', 1)
                ->where('counts.auth', 1));
    }

    /** A exportação é a evidência para o auditor — e ela própria fica na trilha. */
    public function test_exportar_a_trilha_gera_csv_e_se_registra(): void
    {
        Auditoria::registrar(AuditLog::LOGIN, 'Entrou na plataforma.', ator: $this->operador);

        $csv = $this->actingAs($this->admin)->get(route('admin.audit.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('operador@welog.local', $csv);
        $this->assertTrue(AuditLog::where('action', AuditLog::EXPORT_DOWNLOADED)
            ->where('user_id', $this->admin->id)->exists());
    }

    // ------------------------------------------------------------------
    // Revisão de acessos
    // ------------------------------------------------------------------

    public function test_revisao_aponta_conta_parada_e_fica_registrada(): void
    {
        config(['welog.audit.access_review_idle_days' => 90]);
        $this->operador->forceFill(['last_login_at' => now()->subDays(120)])->save();
        $this->admin->forceFill(['last_login_at' => now()->subDay()])->save();

        $this->artisan('welog:acessos:revisao', ['--responsavel' => 'Fulana'])
            ->expectsOutputToContain('sem uso há mais de 90 dias')
            ->assertSuccessful();

        $log = AuditLog::where('action', AuditLog::ACCESS_REVIEW)->firstOrFail();
        $this->assertStringContainsString('Fulana', $log->description);
        $this->assertGreaterThanOrEqual(1, $log->properties['a_revisar']);
    }
}
