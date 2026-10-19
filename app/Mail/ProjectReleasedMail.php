<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\ProjectEntitlement;

/**
 * Notificação de resgate liberado (Fluxo A, etapa 9): enviada a cada cliente
 * final autorizado quando o Projeto é liberado. Conteúdo parametrizável pelo
 * Admin WeGift (Módulo 11).
 *
 * Quando a pessoa ainda não criou a senha, `$setupToken` vem preenchido e o
 * botão do e-mail leva direto para "criar senha" (e dali para o portal), em
 * vez de para o login — sem isso quem perdeu o convite não entra.
 */
class ProjectReleasedMail extends TemplatedMailable
{
    public function __construct(public ProjectEntitlement $entitlement, public ?string $setupToken = null)
    {
        $this->queueForDelivery();
    }

    /** A empresa é a do Projeto desta autorização. */
    protected function templateCompanyId(): ?int
    {
        return $this->entitlement->project?->company_id;
    }

    protected function templateProjectId(): ?int
    {
        return $this->entitlement->project_id;
    }

    protected function templateKey(): string
    {
        return EmailTemplate::KEY_PROJECT_RELEASED;
    }

    protected function templateData(): array
    {
        return [
            'userName' => $this->entitlement->user->name,
            'productName' => $this->entitlement->product->name,
            // Nome de exibição (display_name da Empresa, senão a razão social):
            // e-mail para o Usuário fala o nome do dia a dia, não o do CNPJ.
            'companyName' => $this->entitlement->project->company->label,
        ];
    }

    protected function actionUrl(): ?string
    {
        if ($this->setupToken !== null) {
            return route('password.set.form', [
                'token' => $this->setupToken,
                'email' => $this->entitlement->user->email,
            ]);
        }

        return route('portal.catalog');
    }

    protected function actionLabel(): string
    {
        return $this->setupToken !== null ? 'Criar minha senha e resgatar' : 'Resgatar meu presente';
    }
}
