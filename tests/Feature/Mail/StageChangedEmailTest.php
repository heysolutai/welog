<?php

namespace Tests\Feature\Mail;

use App\Mail\ProjectStageChangedMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Aviso de mudança de etapa (feedback do UAT): quando o pipeline_status muda,
 * SÓ os Admins de Projeto daquele Projeto recebem o e-mail `stage_changed`.
 * O Admin Geral pediu para receber apenas o convite; o Usuário do portal já
 * tem os avisos de envio/entrega.
 *
 * O disparo vive no ProjectObserver (evento `updated` do model), cobrindo as
 * três origens da mudança: webhook do Trello, ajuste manual e sinal de
 * estoque da Omie — todas passam por `$project->update()`.
 */
class StageChangedEmailTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    private User $projectAdmin;

    private User $generalAdmin;

    private User $endCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Mail::fake();

        $this->empresa = Company::create(['name' => 'Empresa A', 'cnpj' => '11111111000191']);
        $this->projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => 'Projeto Etapas',
            'omie_order_number' => 'PV-1234',
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);

        $this->projectAdmin = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->projeto->id,
        ]);
        $this->generalAdmin = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresa->id,
        ]);
        $this->endCustomer = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
        ]);
    }

    public function test_mudanca_manual_de_etapa_notifica_o_admin_do_projeto_na_fila_mail(): void
    {
        $admin = User::where('email', 'admin@welog.local')->first();

        $this->actingAs($admin)
            ->patch(route('projects.stage', $this->projeto), ['pipeline_status' => Project::STATUS_PRODUCTION])
            ->assertSessionHasNoErrors();

        Mail::assertQueued(
            ProjectStageChangedMail::class,
            fn (ProjectStageChangedMail $mail) => $mail->hasTo($this->projectAdmin->email)
                && $mail->stageLabel === 'Produção'
                && $mail->queue === 'mail',
        );
    }

    public function test_admin_geral_e_cliente_final_nao_recebem(): void
    {
        $this->projeto->update(['pipeline_status' => Project::STATUS_PRODUCTION]);

        Mail::assertQueued(ProjectStageChangedMail::class, 1);
        Mail::assertNotQueued(
            ProjectStageChangedMail::class,
            fn (ProjectStageChangedMail $mail) => $mail->hasTo($this->generalAdmin->email)
                || $mail->hasTo($this->endCustomer->email),
        );
    }

    /**
     * Webhook do Trello e sinal de estoque da Omie mudam a etapa por
     * `$project->update()` — o mesmo caminho deste teste. É o evento do model
     * que dispara, então qualquer origem fica coberta.
     */
    public function test_update_direto_no_model_tambem_notifica(): void
    {
        $this->projeto->update(['pipeline_status' => Project::STATUS_IN_STOCK]);

        Mail::assertQueued(
            ProjectStageChangedMail::class,
            fn (ProjectStageChangedMail $mail) => $mail->stageLabel === 'Estoque: pronto para resgate/envio',
        );
    }

    public function test_update_sem_mudanca_de_etapa_nao_dispara(): void
    {
        // Outra coluna muda, a etapa não: nada de e-mail (é o caso do sinal de
        // estoque regravando o mesmo estágio junto do stock_available_at).
        $this->projeto->update(['notes' => 'ajuste interno', 'stock_available_at' => now()]);

        Mail::assertNotQueued(ProjectStageChangedMail::class);
    }

    public function test_admin_de_projeto_inativo_nao_recebe(): void
    {
        $this->projectAdmin->update(['active' => false]);

        $this->projeto->update(['pipeline_status' => Project::STATUS_PRODUCTION]);

        Mail::assertNotQueued(ProjectStageChangedMail::class);
    }

    public function test_admin_de_outro_projeto_nao_recebe(): void
    {
        $outroProjeto = Project::create(['company_id' => $this->empresa->id, 'name' => 'Outro Projeto']);
        $adminDoOutro = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $outroProjeto->id,
        ]);

        $this->projeto->update(['pipeline_status' => Project::STATUS_PRODUCTION]);

        Mail::assertNotQueued(
            ProjectStageChangedMail::class,
            fn (ProjectStageChangedMail $mail) => $mail->hasTo($adminDoOutro->email),
        );
    }

    public function test_template_stage_changed_esta_semeado_e_substitui_variaveis(): void
    {
        $this->assertDatabaseHas('email_templates', ['key' => EmailTemplate::KEY_STAGE_CHANGED]);

        $rendered = EmailTemplate::render(EmailTemplate::KEY_STAGE_CHANGED, [
            'projeto' => 'Projeto Etapas',
            'etapa' => 'Produção',
            'empresa' => 'Empresa A',
            'pedido' => 'PV-1234',
        ]);

        $this->assertStringContainsString('Produção', $rendered['subject']);
        $this->assertStringContainsString('Projeto Etapas', $rendered['body']);
        $this->assertStringContainsString('Empresa A', $rendered['body']);
        $this->assertStringContainsString('PV-1234', $rendered['body']);
    }
}
