<?php

namespace Tests\Feature\Mail;

use App\Mail\RedemptionConfirmedMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Variável {{ contato }} nos modelos de e-mail (feedback do UAT): o Usuário
 * resgatou com um dado errado e não tinha para quem pedir correção. O valor
 * vem de config/welog.php (WELOG_SUPPORT_EMAIL / WELOG_SUPPORT_WHATSAPP) e é
 * injetado globalmente em EmailTemplate::render — nenhum Mailable precisa
 * repassá-lo. O mesmo dado sai como prop Inertia `support` para o portal.
 */
class SupportContactVariableTest extends TestCase
{
    use RefreshDatabase;

    private function withSupport(?string $email, ?string $whatsapp): void
    {
        config(['welog.support.email' => $email, 'welog.support.whatsapp' => $whatsapp]);
    }

    public function test_corpo_padrao_da_confirmacao_de_resgate_cita_o_contato(): void
    {
        $this->withSupport('suporte@wegift.com.br', '11999990000');

        $rendered = EmailTemplate::render(EmailTemplate::KEY_REDEMPTION_CONFIRMED, [
            'userName' => 'Maria', 'productName' => 'Kit X', 'address' => 'Rua A, 1',
        ]);

        $this->assertStringContainsString('Precisa corrigir alguma informação?', $rendered['body']);
        // WhatsApp com a máscara de apresentação do projeto
        $this->assertStringContainsString('suporte@wegift.com.br ou WhatsApp (11) 99999-0000', $rendered['body']);
        $this->assertStringNotContainsString('{{', $rendered['body']);
    }

    public function test_corpo_padrao_da_atualizacao_de_status_cita_o_contato(): void
    {
        $this->withSupport('suporte@wegift.com.br', null);

        $rendered = EmailTemplate::render(EmailTemplate::KEY_SHIPMENT_UPDATE, [
            'userName' => 'Maria', 'eventTitle' => 'Expedido', 'eventDescription' => 'Saiu.',
            'trackingCode' => 'BR1', 'carrier' => 'CORREIOS',
        ]);

        $this->assertStringContainsString('Fale com a gente: suporte@wegift.com.br', $rendered['body']);
    }

    public function test_sem_config_o_texto_degrada_sem_null(): void
    {
        $this->withSupport(null, null);

        $rendered = EmailTemplate::render(EmailTemplate::KEY_REDEMPTION_CONFIRMED, [
            'userName' => 'Maria', 'productName' => 'Kit X', 'address' => 'Rua A, 1',
        ]);

        $this->assertStringContainsString('Fale com a gente: responda este e-mail', $rendered['body']);
        $this->assertStringNotContainsString('null', $rendered['body']);
        $this->assertStringNotContainsString('{{', $rendered['body']);
    }

    public function test_somente_whatsapp_configurado_com_ddi_e_pontuacao(): void
    {
        $this->withSupport(null, '+55 (41) 3333-4444');

        $rendered = EmailTemplate::render(EmailTemplate::KEY_SHIPMENT_UPDATE, [
            'userName' => 'Maria', 'eventTitle' => 'Expedido', 'eventDescription' => 'Saiu.',
            'trackingCode' => 'BR1', 'carrier' => 'CORREIOS',
        ]);

        // O DDI some da exibição (padrão de máscara do projeto), o fixo ganha 4+4
        $this->assertStringContainsString('Fale com a gente: WhatsApp (41) 3333-4444', $rendered['body']);
    }

    public function test_email_de_resgate_renderizado_de_ponta_a_ponta_traz_o_contato(): void
    {
        $this->withSupport('suporte@wegift.com.br', null);

        $company = Company::create(['name' => 'ACME Ltda', 'cnpj' => '12345678000190']);
        $product = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit Onboarding']);
        $project = Project::create(['company_id' => $company->id, 'name' => 'Proj', 'released_at' => now()]);
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id]);

        $redemption = Redemption::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'product_id' => $product->id,
            'recipient_name' => $user->name, 'zip' => '80010000', 'street' => 'Rua XV',
            'number' => '100', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);

        $html = (new RedemptionConfirmedMail($redemption))->render();

        $this->assertStringContainsString('suporte@wegift.com.br', $html);
    }

    // ------------------------------------------------------------------
    // Prop Inertia compartilhada (rodapé do portal, onda 3)
    // ------------------------------------------------------------------

    public function test_prop_support_e_compartilhada_com_todas_as_paginas(): void
    {
        $this->withSupport('suporte@wegift.com.br', '11999990000');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN_WEGIFT]);

        $this->actingAs($admin)
            ->get(route('admin.companies.index'))
            ->assertInertia(fn ($p) => $p
                ->where('support.email', 'suporte@wegift.com.br')
                ->where('support.whatsapp', '(11) 99999-0000')
                ->where('support.whatsapp_link', 'https://wa.me/5511999990000'));
    }

    public function test_prop_support_degrada_para_nulos_sem_config(): void
    {
        $this->withSupport(null, null);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN_WEGIFT]);

        $this->actingAs($admin)
            ->get(route('admin.companies.index'))
            ->assertInertia(fn ($p) => $p
                ->where('support.email', null)
                ->where('support.whatsapp', null)
                ->where('support.whatsapp_link', null));
    }
}
