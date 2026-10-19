<?php

namespace Tests\Feature\Admin;

use App\Mail\ProjectReleasedMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    private function entitlement(): ProjectEntitlement
    {
        $company = Company::create(['name' => 'ACME Ltda', 'cnpj' => '12345678000190']);
        $product = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit Onboarding']);
        $project = Project::create(['company_id' => $company->id, 'name' => 'Proj', 'released_at' => now()]);
        $user = User::factory()->create(['name' => 'João Silva', 'role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id]);

        return ProjectEntitlement::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'product_id' => $product->id,
        ]);
    }

    public function test_seed_cria_os_seis_modelos(): void
    {
        // project_released, redemption_confirmed, redemption_shipped,
        // redemption_delivered, shipment_update (eventos de rastreio no meio do
        // caminho) e stage_changed (aviso de etapa para o Admin de Projeto).
        $this->assertDatabaseCount('email_templates', 6);
        $this->assertDatabaseHas('email_templates', ['key' => EmailTemplate::KEY_REDEMPTION_SHIPPED]);
        $this->assertDatabaseHas('email_templates', ['key' => EmailTemplate::KEY_REDEMPTION_DELIVERED]);
        $this->assertDatabaseHas('email_templates', ['key' => EmailTemplate::KEY_PROJECT_RELEASED]);
        $this->assertDatabaseHas('email_templates', ['key' => EmailTemplate::KEY_STAGE_CHANGED]);
    }

    public function test_render_substitui_variaveis(): void
    {
        $rendered = EmailTemplate::render(EmailTemplate::KEY_PROJECT_RELEASED, [
            'userName' => 'Maria', 'productName' => 'Kit X', 'companyName' => 'ACME',
        ]);

        $this->assertStringContainsString('Maria', $rendered['body']);
        $this->assertStringContainsString('Kit X', $rendered['body']);
        $this->assertStringContainsString('ACME', $rendered['body']);
        $this->assertStringNotContainsString('{{', $rendered['body']);
    }

    public function test_email_usa_conteudo_editado_pelo_admin(): void
    {
        // Admin edita o corpo do modelo
        EmailTemplate::where('key', EmailTemplate::KEY_PROJECT_RELEASED)->update([
            'subject' => 'Presente da {{ companyName }} chegou!',
            'body' => 'Ei {{ userName }}, seu {{ productName }} está liberado. FRASE-PERSONALIZADA-123',
        ]);

        $html = (new ProjectReleasedMail($this->entitlement()))->render();

        $this->assertStringContainsString('FRASE-PERSONALIZADA-123', $html);
        $this->assertStringContainsString('João Silva', $html);
        $this->assertStringContainsString('Kit Onboarding', $html);
    }

    public function test_valores_das_variaveis_nao_injetam_html_no_corpo(): void
    {
        // Nome/endereço/descrição vêm de terceiros — sem escape, viram phishing
        // dentro de um e-mail legítimo da marca.
        $rendered = EmailTemplate::render(EmailTemplate::KEY_PROJECT_RELEASED, [
            'userName' => '<a href="https://phishing.example">Clique aqui</a>',
            'productName' => 'Kit X',
            'companyName' => 'ACME',
        ]);

        $this->assertStringNotContainsString('<a href', $rendered['body']);
        $this->assertStringContainsString('&lt;a href', $rendered['body']);
    }

    public function test_html_injetado_nao_sobrevive_a_renderizacao_do_email(): void
    {
        $entitlement = $this->entitlement();
        $entitlement->user->update(['name' => '<script>alert(1)</script>']);

        $html = (new ProjectReleasedMail($entitlement->fresh()))->render();

        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_assunto_permanece_texto_puro(): void
    {
        EmailTemplate::where('key', EmailTemplate::KEY_PROJECT_RELEASED)
            ->update(['subject' => 'Presente da {{ companyName }}']);

        $rendered = EmailTemplate::render(EmailTemplate::KEY_PROJECT_RELEASED, [
            'userName' => 'Maria', 'productName' => 'Kit X', 'companyName' => 'Souza & Filhos',
        ]);

        // Escapar o assunto deixaria "&amp;" visível na caixa de entrada
        $this->assertSame('Presente da Souza & Filhos', $rendered['subject']);
    }

    public function test_rodar_o_seed_de_novo_nao_sobrescreve_personalizacao(): void
    {
        // Deploy roda o seed. Antes era updateOrCreate e cada deploy desfazia
        // o que o Admin WeGift tinha editado na tela de modelos.
        EmailTemplate::where('key', EmailTemplate::KEY_REDEMPTION_CONFIRMED)->update([
            'subject' => 'Assunto personalizado',
            'body' => 'CORPO-PERSONALIZADO com {{ userName }}',
        ]);

        $this->seed();

        $template = EmailTemplate::where('key', EmailTemplate::KEY_REDEMPTION_CONFIRMED)->first();
        $this->assertSame('Assunto personalizado', $template->subject);
        $this->assertSame('CORPO-PERSONALIZADO com {{ userName }}', $template->body);
    }

    public function test_admin_edita_modelo(): void
    {
        $template = EmailTemplate::where('key', EmailTemplate::KEY_REDEMPTION_CONFIRMED)->first();

        $this->actingAs($this->admin())
            ->put(route('admin.email-templates.update', EmailTemplate::KEY_REDEMPTION_CONFIRMED), [
                'subject' => 'Novo assunto',
                'body' => 'Novo corpo com {{ userName }}',
            ])->assertSessionHas('status');

        $this->assertSame('Novo assunto', $template->fresh()->subject);
    }

    public function test_restaurar_padrao(): void
    {
        $template = EmailTemplate::where('key', EmailTemplate::KEY_PROJECT_RELEASED)->first();
        $template->update(['subject' => 'alterado', 'body' => 'alterado']);

        $this->actingAs($this->admin())
            ->delete(route('admin.email-templates.restore', EmailTemplate::KEY_PROJECT_RELEASED))
            ->assertSessionHas('status');

        $default = EmailTemplate::definitions()[EmailTemplate::KEY_PROJECT_RELEASED];
        $this->assertSame($default['subject'], $template->fresh()->subject);
    }

    public function test_pagina_renderiza_com_variaveis(): void
    {
        // 6 modelos, na ordem da jornada: o primeiro é "Presente liberado".
        $this->actingAs($this->admin())->get(route('admin.email-templates.index'))
            ->assertInertia(fn ($p) => $p
                ->component('Admin/EmailTemplates/Index')
                ->has('templates', 6)
                ->where('templates.0.key', EmailTemplate::KEY_PROJECT_RELEASED));
    }

    public function test_tela_lista_a_variavel_contato_para_o_editor(): void
    {
        // {{ contato }} é global (injetada no render), mas o editor precisa
        // anunciá-la na lista de variáveis de cada modelo para ser descoberta.
        $this->actingAs($this->admin())->get(route('admin.email-templates.index'))
            ->assertInertia(fn ($p) => $p
                ->component('Admin/EmailTemplates/Index')
                ->where('templates.0.variables', fn ($variables) => collect($variables)
                    ->contains(fn ($v) => $v['name'] === 'contato')));
    }

    public function test_nao_admin_nao_acessa(): void
    {
        $n1 = User::where('email', 'operador@welog.local')->first();
        $this->actingAs($n1)->get(route('admin.email-templates.index'))->assertForbidden();
    }
}
