<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\Project;

/**
 * Aviso de mudança de etapa do Projeto, enviado aos Admins de Projeto
 * (project_admin) quando o pipeline_status muda (feedback do UAT). Conteúdo
 * parametrizável pelo Admin WeGift (Módulo 11, modelo `stage_changed`).
 *
 * O rótulo da etapa chega pronto pelo construtor: o envio roda na fila, e o
 * Projeto pode já ter avançado de novo quando o worker processar — o e-mail
 * relata a mudança que o disparou, não a etapa do momento do envio.
 */
class ProjectStageChangedMail extends TemplatedMailable
{
    public function __construct(public Project $project, public string $stageLabel)
    {
        $this->queueForDelivery();
    }

    protected function templateCompanyId(): ?int
    {
        return $this->project->company_id;
    }

    protected function templateProjectId(): ?int
    {
        return $this->project->id;
    }

    protected function templateKey(): string
    {
        return EmailTemplate::KEY_STAGE_CHANGED;
    }

    protected function templateData(): array
    {
        return [
            'projeto' => $this->project->name,
            'etapa' => $this->stageLabel,
            // Nome de exibição (display_name da Empresa, senão a razão social).
            'empresa' => $this->project->company->label,
            'pedido' => $this->project->omie_order_number ?: 'sem número Omie',
        ];
    }

    protected function actionUrl(): ?string
    {
        return route('projects.show', $this->project);
    }

    protected function actionLabel(): string
    {
        return 'Ver o Projeto';
    }
}
