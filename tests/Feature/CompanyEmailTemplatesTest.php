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
use Tests\TestCase;

/**
 * A empresa escreve as mensagens que os Colaboradores dela recebem.
 *
 * O modelo da empresa não substitui o padrão da WeGift: convive com ele e
 * vence no envio. É o que permite "restaurar" ser um DELETE — a empresa volta
 * a seguir o padrão vivo, em vez de ficar com uma cópia congelada no dia em
 * que restaurou.
 */
class CompanyEmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Company $outra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->empresa = Company::create(['name' => 'Webox', 'cnpj' => '51785932000128']);
        $this->outra = Company::create(['name' => 'Santa', 'cnpj' => '12345678000190']);
    }

    private function adminDa(Company $company): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $company->id,
        ]);
    }

    public function test_admin_da_empresa_personaliza_e_o_envio_usa_o_texto_dela(): void
    {
        $this->actingAs($this->adminDa($this->empresa))
            ->put(route('company.templates.update', EmailTemplate::KEY_PROJECT_RELEASED), [
                'subject' => 'Seu mimo da Webox chegou!',
                'body' => '# Oi, {{ userName }}! Aqui é a Webox.',
            ])
            ->assertRedirect();

        $renderizado = EmailTemplate::render(
            EmailTemplate::KEY_PROJECT_RELEASED,
            ['userName' => 'Ana'],
            $this->empresa->id,
        );

        $this->assertSame('Seu mimo da Webox chegou!', $renderizado['subject']);
        $this->assertStringContainsString('Aqui é a Webox', $renderizado['body']);
    }

    /** Empresa sem personalização continua no texto da WeGift. */
    public function test_outra_empresa_continua_no_padrao(): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_PROJECT_RELEASED,
            'company_id' => $this->empresa->id,
            'name' => 'Personalizado',
            'subject' => 'Texto da Webox',
            'body' => 'corpo da Webox',
        ]);

        $padrao = EmailTemplate::whereNull('company_id')
            ->where('key', EmailTemplate::KEY_PROJECT_RELEASED)
            ->first();

        $renderizado = EmailTemplate::render(
            EmailTemplate::KEY_PROJECT_RELEASED,
            ['userName' => 'Ana'],
            $this->outra->id,
        );

        $this->assertSame($padrao->subject, $renderizado['subject']);
    }

    /** O e-mail real sabe de qual empresa é, sem ninguém dizer. */
    public function test_o_email_de_liberacao_usa_o_texto_da_empresa_do_projeto(): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_PROJECT_RELEASED,
            'company_id' => $this->empresa->id,
            'name' => 'Personalizado',
            'subject' => 'Presente da Webox',
            'body' => 'corpo',
        ]);

        $produto = Product::create(['omie_code' => 'PRD1', 'name' => 'Kit']);
        $projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => 'Natal',
            'quantity' => 1,
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $pessoa = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresa->id]);
        $entitlement = ProjectEntitlement::create([
            'project_id' => $projeto->id,
            'user_id' => $pessoa->id,
            'product_id' => $produto->id,
        ]);

        $envelope = (new ProjectReleasedMail($entitlement))->envelope();

        $this->assertSame('Presente da Webox', $envelope->subject);
    }

    public function test_restaurar_apaga_a_personalizacao_e_volta_ao_padrao(): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_PROJECT_RELEASED,
            'company_id' => $this->empresa->id,
            'name' => 'Personalizado',
            'subject' => 'Texto da Webox',
            'body' => 'corpo',
        ]);

        $this->actingAs($this->adminDa($this->empresa))
            ->delete(route('company.templates.restore', EmailTemplate::KEY_PROJECT_RELEASED))
            ->assertRedirect();

        $this->assertDatabaseMissing('email_templates', [
            'key' => EmailTemplate::KEY_PROJECT_RELEASED,
            'company_id' => $this->empresa->id,
        ]);

        // O padrão da WeGift continua de pé para todo mundo.
        $this->assertDatabaseHas('email_templates', [
            'key' => EmailTemplate::KEY_PROJECT_RELEASED,
            'company_id' => null,
        ]);
    }

    /**
     * A empresa vem de quem está logado, nunca da URL: do contrário um admin
     * editaria a comunicação de outro cliente trocando um número.
     */
    public function test_admin_de_uma_empresa_nao_alcanca_a_comunicacao_de_outra(): void
    {
        $this->actingAs($this->adminDa($this->outra))
            ->put(route('company.templates.update', EmailTemplate::KEY_PROJECT_RELEASED), [
                'subject' => 'Invadindo',
                'body' => 'corpo',
            ])
            ->assertRedirect();

        // Gravou na empresa DELE, não na outra.
        $this->assertDatabaseHas('email_templates', [
            'subject' => 'Invadindo',
            'company_id' => $this->outra->id,
        ]);
        $this->assertDatabaseMissing('email_templates', [
            'subject' => 'Invadindo',
            'company_id' => $this->empresa->id,
        ]);
    }

    /** Chave que não existe não vira modelo novo. */
    public function test_chave_desconhecida_e_recusada(): void
    {
        $this->actingAs($this->adminDa($this->empresa))
            ->put(route('company.templates.update', 'chave_inventada'), [
                'subject' => 'x',
                'body' => 'y',
            ])
            ->assertNotFound();
    }

    public function test_colaborador_nao_acessa_a_tela(): void
    {
        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
        ]);

        $this->actingAs($pessoa)->get(route('company.templates.index'))->assertForbidden();
    }
}
