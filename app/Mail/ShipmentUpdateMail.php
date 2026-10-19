<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\Redemption;

/**
 * Atualização de status em eventos-chave do pedido (expedição, entrega).
 * Conteúdo parametrizável pelo Admin WeGift (Módulo 11); o evento específico
 * (título/descrição) entra como variável.
 */
class ShipmentUpdateMail extends TemplatedMailable
{
    /**
     * `$chave`: expedição e entrega têm modelo próprio (Estúdio de mensagens);
     * o genérico fica para os eventos de rastreio no meio do caminho.
     */
    public function __construct(
        public Redemption $redemption,
        public string $eventTitle,
        public string $eventDescription,
        public string $chave = EmailTemplate::KEY_SHIPMENT_UPDATE,
    ) {
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
        return $this->chave;
    }

    protected function templateData(): array
    {
        $codigo = $this->redemption->tracking_code;
        $link = $this->redemption->trackingLink();

        $dados = $this->redemption->dadosDoEmail();

        // Sem o produto, a empresa e o endereço, "Seu presente {produto}" do
        // modelo de expedição e de entrega saía com a variável crua.
        return $dados + [
            'userName' => $dados['nome'],
            'productName' => $dados['produto'],
            'companyName' => $dados['empresa'],
            'address' => $dados['endereco'],
            'eventTitle' => $this->eventTitle,
            'eventDescription' => $this->eventDescription,
            'trackingCode' => $codigo ?? 'não informado',
            'trackingUrl' => $link ?? 'não informado',
            // O nome cadastrado, não o slug em caixa alta: "DDM Log" era
            // "DDM" para quem recebia o e-mail.
            'carrier' => $this->redemption->carrierRecord?->name
                ?? ($this->redemption->carrier ? strtoupper($this->redemption->carrier) : 'não informada'),
            /*
             * Uma linha só, pronta, porque o que existe depende da
             * transportadora: umas dão código, outras só o link. O modelo
             * padrão imprimia "Código de rastreio: não informado" para quem
             * tinha o link na mão.
             */
            'rastreio' => match (true) {
                $codigo !== null && $link !== null => $codigo.' · '.$link,
                $codigo !== null => $codigo,
                $link !== null => $link,
                default => 'ainda não informado',
            },
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
