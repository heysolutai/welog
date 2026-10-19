<?php

namespace Tests\Feature;

use App\Models\AppnozImport;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O "começar do zero" antes de entrar gente de verdade.
 *
 * O risco deste comando não é apagar de menos: é apagar o que a plataforma
 * precisa para continuar de pé — a conta de quem entra, o catálogo, os
 * templates. Por isso cada teste aqui olha para o que SOBROU, não só para o
 * que sumiu.
 */
class ResetOperationalDataTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $equipe = User::factory()->create(['role' => User::ROLE_ADMIN_WEGIFT, 'company_id' => null]);

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);

        $cliente = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $empresa->id,
        ]);

        $project = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Projeto de teste',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);

        // `product_id` é obrigatório: a autorização diz QUAL presente a pessoa
        // pode resgatar, não só que ela pode.
        $produto = Product::create(['omie_code' => 'PRD1', 'name' => 'Kit de teste']);

        ProjectEntitlement::create([
            'project_id' => $project->id,
            'user_id' => $cliente->id,
            'product_id' => $produto->id,
        ]);

        WebhookEvent::create([
            'source' => 'omie',
            'external_event_id' => 'ev-1',
            'topic' => 'VendaProduto.Incluida',
            'payload' => [],
            'status' => 'processed',
        ]);

        return ['equipe' => $equipe, 'empresa' => $empresa, 'cliente' => $cliente, 'project' => $project];
    }

    /** Sem `--execute` é relatório: a base tem de continuar intacta. */
    public function test_simulacao_nao_apaga_nada(): void
    {
        $this->cenario();

        $this->artisan('welog:base:reset')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();

        $this->assertSame(1, Company::count());
        $this->assertSame(1, Project::count());
    }

    public function test_reset_zera_a_operacao_e_preserva_a_equipe(): void
    {
        $dados = $this->cenario();

        $this->artisan('welog:base:reset', ['--execute' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, Company::count());
        $this->assertSame(0, Project::count());
        $this->assertSame(0, ProjectEntitlement::count());
        $this->assertSame(0, Redemption::count());
        $this->assertSame(0, AppnozImport::count());
        $this->assertSame(0, WebhookEvent::count());

        // O que mantém a plataforma de pé
        $this->assertDatabaseHas('users', ['id' => $dados['equipe']->id]);
        $this->assertSame(1, User::count(), 'Só a conta da WeGift deveria ter sobrado');
        $this->assertSame(1, Product::count(), 'O catálogo espelhado da Omie sobrevive ao reset');
    }

    /**
     * A conta do cliente é APAGADA, não desvinculada. Como `company_id` é
     * `nullOnDelete`, apagar a empresa primeiro deixaria a pessoa na base sem
     * empresa — logando e caindo num 403 sem saída.
     */
    public function test_usuario_de_cliente_some_junto_com_a_empresa(): void
    {
        $dados = $this->cenario();

        $this->artisan('welog:base:reset', ['--execute' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $dados['cliente']->id]);
    }

    /** Auditoria em curso: o log de webhooks pode ser poupado. */
    public function test_manter_webhooks_preserva_o_log(): void
    {
        $this->cenario();

        $this->artisan('welog:base:reset', ['--execute' => true, '--force' => true, '--manter-webhooks' => true])
            ->assertSuccessful();

        $this->assertSame(0, Company::count());
        $this->assertSame(1, WebhookEvent::count());
    }

    /**
     * Trava final: sem conta da WeGift, o reset deixaria a plataforma sem
     * ninguém capaz de entrar — e sem forma de criar o primeiro acesso pela
     * própria tela.
     */
    public function test_recusa_quando_nao_ha_conta_da_wegift(): void
    {
        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $empresa->id]);

        $this->artisan('welog:base:reset', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('sem ninguém que entra')
            ->assertFailed();

        $this->assertSame(1, Company::count(), 'Nada pode ter sido apagado');
    }
}
