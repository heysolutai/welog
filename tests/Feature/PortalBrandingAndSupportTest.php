<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O portal do Colaborador com a cara do Cliente — tudo pelas Customizações,
 * nada amarrado a um Cliente no código: a logo da campanha no topo, o e-mail
 * de atendimento dele, e os textos que não prometem o que ele desligou.
 */
class PortalBrandingAndSupportTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);
        $kit = Product::create(['omie_code' => 'KIT', 'name' => 'Kit', 'stock_qty' => 10, 'stock_synced_at' => now()]);

        $this->projeto = Project::create([
            'company_id' => $this->empresa->id, 'name' => '85 anos', 'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK, 'released_at' => now(),
            'logo_path' => 'logos/85anos.png',
        ]);
        $this->projeto->products()->attach($kit->id, ['quantity' => 10]);

        $this->colaborador = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresa->id, 'password_set_at' => now(),
        ]);

        ProjectEntitlement::create(['project_id' => $this->projeto->id, 'user_id' => $this->colaborador->id, 'product_id' => $kit->id]);
    }

    private function customizar(array $chaves): void
    {
        $this->empresa->update(['customizations' => [...($this->empresa->customizations ?? []), ...$chaves]]);
    }

    // ── Atendimento ─────────────────────────────────────────────────────────

    public function test_email_de_atendimento_do_cliente_substitui_o_padrao_no_portal(): void
    {
        $this->customizar(['portal_support' => true, 'support_email' => 'atendimento.sotreq@wegift.com.br']);

        $this->actingAs($this->colaborador)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('support.email', 'atendimento.sotreq@wegift.com.br')
                ->where('support.banner', true));
    }

    /** Sem a chave, vale o contato padrão — e a faixa não aparece. */
    public function test_atendimento_desligado_nao_mostra_a_faixa(): void
    {
        $this->customizar(['portal_support' => false, 'support_email' => 'atendimento.sotreq@wegift.com.br']);

        $this->actingAs($this->colaborador)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('support.banner', false)
                ->whereNot('support.email', 'atendimento.sotreq@wegift.com.br'));
    }

    public function test_admin_wegift_salva_o_email_de_atendimento(): void
    {
        $admin = User::where('email', 'admin@welog.local')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.companies.support-email.update', $this->empresa), ['email' => 'Atendimento.Sotreq@WeGift.com.br'])
            ->assertSessionHasNoErrors();

        $this->assertSame('atendimento.sotreq@wegift.com.br', $this->empresa->refresh()->customizations['support_email']);

        $this->actingAs($admin)
            ->put(route('admin.companies.support-email.update', $this->empresa), ['email' => 'isto não é e-mail'])
            ->assertSessionHasErrors('email');
    }

    // ── Logo no portal ──────────────────────────────────────────────────────

    public function test_logo_do_projeto_no_topo_do_portal_com_o_nivel_colaborador(): void
    {
        $this->customizar(['project_logo' => true, 'logo_niveis' => ['projeto', 'colaborador']]);

        $this->actingAs($this->colaborador)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.user.portal_logo_url', $this->projeto->logo_url));
    }

    /** O padrão continua só no Projeto: o portal não ganha a marca sem pedir. */
    public function test_sem_o_nivel_colaborador_o_portal_fica_sem_logo(): void
    {
        $this->customizar(['project_logo' => true]);

        $this->actingAs($this->colaborador)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.user.portal_logo_url', null));
    }

    // ── Textos que acompanham as Customizações ──────────────────────────────

    /** O convite do presente não cita o CPF de quem o Cliente não coleta. */
    public function test_catalogo_diz_se_o_cliente_pede_cpf(): void
    {
        $this->actingAs($this->colaborador)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p->where('gifts.0.ask_cpf', true));

        $this->customizar(['import_fields' => true, 'import_columns' => ['nome', 'email', 'telefone']]);

        $this->actingAs($this->colaborador)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p->where('gifts.0.ask_cpf', false));
    }

    /** A lista de Colaboradores sabe se a WeLog avisa alguém, e quando cada um entrou. */
    public function test_tela_do_projeto_traz_o_estado_dos_emails_e_a_data_do_cadastro(): void
    {
        $this->customizar(['welog_sends_emails' => false]);
        $admin = User::where('email', 'admin@welog.local')->firstOrFail();

        $this->actingAs($admin)->get(route('projects.show', $this->projeto))
            ->assertInertia(fn (Assert $p) => $p
                ->where('project.company.sends_emails', false)
                ->has('colaboradores.data.0.created_at'));
    }
}
