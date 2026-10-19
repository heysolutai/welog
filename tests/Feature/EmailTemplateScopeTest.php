<?php

namespace Tests\Feature;

use App\Mail\ProjectReleasedMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A mesma mensagem em três alcances.
 *
 * O padrão da WeGift é o piso de todo envio; o texto do cliente sobrepõe onde
 * existir; o da campanha sobrepõe o do cliente. A empresa sozinha era recorte
 * grosso demais para quem faz várias campanhas por ano — o Natal não fala como
 * o kit de boas-vindas, e um texto que sirva para os dois não serve para
 * nenhum.
 *
 * Os níveis convivem em vez de se substituírem: apagar o de baixo devolve o de
 * cima intacto, e é isso que permite "restaurar" ser um DELETE.
 */
class EmailTemplateScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $natal;

    private Project $onboarding;

    private ProjectEntitlement $doNatal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();

        $this->empresa = Company::create(['name' => 'Webox', 'cnpj' => '51785932000128']);
        $kit = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit A']);

        $this->natal = Project::create(['company_id' => $this->empresa->id, 'name' => 'Natal 2026']);
        $this->onboarding = Project::create(['company_id' => $this->empresa->id, 'name' => 'Boas-vindas']);

        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
        ]);

        $this->doNatal = ProjectEntitlement::create([
            'project_id' => $this->natal->id,
            'user_id' => $pessoa->id,
            'product_id' => $kit->id,
        ]);
    }

    private function adminCliente(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresa->id,
        ]);
    }

    private function wegift(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    /** Sem personalização nenhuma, o envio usa o padrão da WeGift. */
    public function test_sem_personalizacao_vale_o_padrao(): void
    {
        $padrao = EmailTemplate::where('key', EmailTemplate::KEY_PROJECT_RELEASED)
            ->whereNull('company_id')->whereNull('project_id')->firstOrFail();

        $resolvido = EmailTemplate::resolve(
            EmailTemplate::KEY_PROJECT_RELEASED,
            $this->empresa->id,
            $this->natal->id,
        );

        $this->assertSame($padrao->id, $resolvido->id);
    }

    /** O texto do cliente sobrepõe o padrão em todos os Projetos dele. */
    public function test_texto_do_cliente_vence_o_padrao(): void
    {
        $this->actingAs($this->adminCliente())
            ->put(route('company.templates.update', EmailTemplate::KEY_PROJECT_RELEASED), [
                'subject' => 'A Webox tem um presente',
                'body' => 'Olá, {{ userName }}!',
            ])
            ->assertSessionHasNoErrors();

        foreach ([$this->natal, $this->onboarding] as $projeto) {
            $this->assertSame(
                'A Webox tem um presente',
                EmailTemplate::resolve(
                    EmailTemplate::KEY_PROJECT_RELEASED,
                    $this->empresa->id,
                    $projeto->id,
                )->subject,
            );
        }
    }

    /** E o da campanha sobrepõe o do cliente, só nela. */
    public function test_texto_da_campanha_vence_o_do_cliente(): void
    {
        $admin = $this->adminCliente();

        $this->actingAs($admin)
            ->put(route('company.templates.update', EmailTemplate::KEY_PROJECT_RELEASED), [
                'subject' => 'A Webox tem um presente',
                'body' => 'Olá, {{ userName }}!',
            ]);

        $this->actingAs($admin)
            ->put(route('company.templates.update', [
                'key' => EmailTemplate::KEY_PROJECT_RELEASED,
                'projeto' => $this->natal->id,
            ]), [
                'subject' => 'Boas festas, seu presente chegou',
                'body' => 'Feliz Natal, {{ userName }}!',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Boas festas, seu presente chegou',
            EmailTemplate::resolve(EmailTemplate::KEY_PROJECT_RELEASED, $this->empresa->id, $this->natal->id)->subject,
        );

        // A outra campanha da mesma empresa continua no texto do cliente.
        $this->assertSame(
            'A Webox tem um presente',
            EmailTemplate::resolve(EmailTemplate::KEY_PROJECT_RELEASED, $this->empresa->id, $this->onboarding->id)->subject,
        );
    }

    /** O e-mail real do Projeto chega com o texto da campanha. */
    public function test_o_envio_usa_o_texto_da_campanha(): void
    {
        $this->actingAs($this->adminCliente())
            ->put(route('company.templates.update', [
                'key' => EmailTemplate::KEY_PROJECT_RELEASED,
                'projeto' => $this->natal->id,
            ]), [
                'subject' => 'Boas festas!',
                'body' => 'Feliz Natal, {{ userName }}!',
            ]);

        $mail = new ProjectReleasedMail($this->doNatal->fresh());

        $this->assertSame('Boas festas!', $mail->envelope()->subject);
    }

    /** Apagar o da campanha devolve o do cliente, intacto. */
    public function test_remover_a_campanha_devolve_o_texto_do_cliente(): void
    {
        $admin = $this->adminCliente();

        $this->actingAs($admin)
            ->put(route('company.templates.update', EmailTemplate::KEY_PROJECT_RELEASED), [
                'subject' => 'A Webox tem um presente',
                'body' => 'Olá, {{ userName }}!',
            ]);

        $this->actingAs($admin)
            ->put(route('company.templates.update', [
                'key' => EmailTemplate::KEY_PROJECT_RELEASED,
                'projeto' => $this->natal->id,
            ]), ['subject' => 'Boas festas!', 'body' => 'Feliz Natal!']);

        $this->actingAs($admin)
            ->delete(route('company.templates.restore', [
                'key' => EmailTemplate::KEY_PROJECT_RELEASED,
                'projeto' => $this->natal->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'A Webox tem um presente',
            EmailTemplate::resolve(EmailTemplate::KEY_PROJECT_RELEASED, $this->empresa->id, $this->natal->id)->subject,
        );
    }

    /** Campanha de outra empresa é porta dos fundos para fora de casa. */
    public function test_admin_cliente_nao_personaliza_projeto_de_outra_empresa(): void
    {
        $outra = Company::create(['name' => 'Santa', 'cnpj' => '12345678000190']);
        $deles = Project::create(['company_id' => $outra->id, 'name' => 'Campanha alheia']);

        $this->actingAs($this->adminCliente())
            ->put(route('company.templates.update', [
                'key' => EmailTemplate::KEY_PROJECT_RELEASED,
                'projeto' => $deles->id,
            ]), ['subject' => 'Invasão', 'body' => 'x'])
            ->assertNotFound();

        $this->assertDatabaseMissing('email_templates', ['project_id' => $deles->id]);
    }

    /** O Admin de Projeto só personaliza a campanha dele. */
    public function test_admin_de_projeto_nao_alcanca_outra_campanha_da_mesma_empresa(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->natal->id,
        ]);

        $this->actingAs($admin)
            ->put(route('company.templates.update', [
                'key' => EmailTemplate::KEY_PROJECT_RELEASED,
                'projeto' => $this->onboarding->id,
            ]), ['subject' => 'Vizinha', 'body' => 'x'])
            ->assertForbidden();

        // E o seletor da tela dele lista só o Projeto que ele administra.
        $this->actingAs($admin)
            ->get(route('company.templates.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->has('projetos', 1)
                ->where('projetos.0.nome', 'Natal 2026'));
    }

    // ------------------------------------------------------------------
    // A visão da WeGift alcança os três níveis
    // ------------------------------------------------------------------

    public function test_wegift_personaliza_para_um_cliente(): void
    {
        $this->actingAs($this->wegift())
            ->put(route('admin.email-templates.update', [
                'key' => EmailTemplate::KEY_PROJECT_RELEASED,
                'empresa' => $this->empresa->id,
            ]), ['subject' => 'Escrito pela WeGift', 'body' => 'Olá!'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Escrito pela WeGift',
            EmailTemplate::resolve(EmailTemplate::KEY_PROJECT_RELEASED, $this->empresa->id)->subject,
        );

        // O padrão geral não foi tocado: ele é o piso dos outros clientes.
        $this->assertNotSame(
            'Escrito pela WeGift',
            EmailTemplate::resolve(EmailTemplate::KEY_PROJECT_RELEASED)->subject,
        );
    }

    /** A tela da WeGift diz o alcance aberto e de onde o texto vem. */
    public function test_a_tela_da_wegift_mostra_o_alcance_e_a_heranca(): void
    {
        $this->actingAs($this->wegift())
            ->get(route('admin.email-templates.index', ['projeto' => $this->natal->id]))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Admin/EmailTemplates/Index')
                ->where('escopo.nivel', 'projeto')
                ->where('escopo.rotulo', 'Natal 2026')
                ->where('templates.0.customized', false)
                ->where('templates.0.inherited_from', 'padrão geral')
                // O público de cada mensagem chega à tela para não misturar
                // as duas vozes na mesma lista.
                ->where('templates.0.audience', EmailTemplate::AUDIENCE_COLLABORATOR));
    }

    public function test_o_aviso_de_etapa_e_dirigido_a_quem_administra(): void
    {
        $this->assertSame(
            EmailTemplate::AUDIENCE_CLIENT_ADMIN,
            EmailTemplate::definitions()[EmailTemplate::KEY_STAGE_CHANGED]['audience'],
        );
    }
}
