<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\Redemption;

/**
 * Confirmação de resgate ao Usuário, após efetivação na WeLog.
 * Conteúdo parametrizável pelo Admin WeGift (Módulo 11).
 */
class RedemptionConfirmedMail extends TemplatedMailable
{
    public function __construct(public Redemption $redemption)
    {
        $this->queueForDelivery();
    }

    protected function templateCompanyId(): ?int
    {
        return $this->redemption->project?->company_id;
    }

    protected function templateProjectId(): ?int
    {
        return $this->redemption->project_id;
    }

    protected function templateKey(): string
    {
        return EmailTemplate::KEY_REDEMPTION_CONFIRMED;
    }

    protected function templateData(): array
    {
        $dados = $this->redemption->dadosDoEmail();

        // O vocabulário antigo, para os modelos gravados com {{ userName }}.
        return $dados + [
            'userName' => $dados['nome'],
            'productName' => $dados['produto'],
            'companyName' => $dados['empresa'],
            'address' => $dados['endereco'],
        ];
    }

    protected function actionUrl(): ?string
    {
        return route('portal.redemptions');
    }

    protected function actionLabel(): string
    {
        return 'Acompanhar meu pedido';
    }
}
