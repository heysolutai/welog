<?php

namespace Tests\Feature\Mail;

use App\Mail\AccountInvitationMail;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menção à empresa no convite de primeiro acesso (feedback do UAT): o Usuário
 * precisa saber QUEM o cadastrou, senão o e-mail parece golpe. O nome mostrado
 * é o `display_name` definido pela WeGift (Company::label), com a razão social
 * espelhada da Omie de reserva.
 */
class AccountInvitationCompanyMentionTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $override = []): Company
    {
        return Company::create($override + [
            'name' => 'ACME Comercio de Brindes LTDA',
            'cnpj' => '12345678000190',
        ]);
    }

    private function render(User $user): string
    {
        return (new AccountInvitationMail($user, 'token-de-teste'))->render();
    }

    public function test_convite_menciona_o_nome_de_exibicao_quando_definido(): void
    {
        $company = $this->company(['display_name' => 'Loja do Sorriso']);
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id]);

        $html = $this->render($user);

        $this->assertStringContainsString('cadastrado(a) pela Loja do Sorriso', $html);
        // A razão social (carimbo do CNPJ) não deve aparecer quando há nome de exibição
        $this->assertStringNotContainsString('ACME Comercio de Brindes LTDA', $html);
    }

    public function test_sem_nome_de_exibicao_o_convite_usa_a_razao_social(): void
    {
        $company = $this->company();
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id]);

        $this->assertStringContainsString(
            'cadastrado(a) pela ACME Comercio de Brindes LTDA',
            $this->render($user),
        );
    }

    public function test_usuario_sem_empresa_recebe_o_texto_neutro(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => null]);

        $html = $this->render($user);

        $this->assertStringContainsString('É com ela que você entra na plataforma', $html);
        $this->assertStringNotContainsString('cadastrado(a) pela', $html);
    }

    public function test_nome_de_empresa_nao_injeta_html_no_convite(): void
    {
        // O nome vem de terceiros (Omie ou digitação): sem escape, viraria um
        // link de phishing dentro de um e-mail legítimo da marca.
        $company = $this->company([
            'display_name' => '<a href="https://phishing.example">Clique aqui</a>',
        ]);
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id]);

        $html = $this->render($user);

        $this->assertStringNotContainsString('<a href="https://phishing.example">', $html);
        $this->assertStringContainsString('&lt;a href', $html);
    }

    public function test_admin_cliente_tambem_ve_o_nome_de_exibicao(): void
    {
        $company = $this->company(['display_name' => 'Loja do Sorriso']);
        $user = User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $company->id]);

        $this->assertStringContainsString('a área da Loja do Sorriso', $this->render($user));
    }

    public function test_paragrafo_do_admin_de_projeto_permanece(): void
    {
        // Texto específico do papel project_admin (onda anterior): a menção à
        // empresa não pode ter atropelado o parágrafo dele.
        $company = $this->company();
        $project = Project::create(['company_id' => $company->id, 'name' => 'Kit Onboarding 2026']);
        $user = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $company->id,
            'admin_project_id' => $project->id,
        ]);

        $html = $this->render($user);

        $this->assertStringContainsString('o Projeto Kit Onboarding 2026', $html);
        $this->assertStringContainsString('cadastrar quem vai receber os presentes', $html);
    }

    public function test_convite_sai_na_fila_mail(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER]);

        $mail = new AccountInvitationMail($user, 'token-de-teste');

        $this->assertSame('mail', $mail->queue);
    }
}
