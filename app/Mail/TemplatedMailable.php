<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedDelivery;
use App\Models\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Base das comunicações automatizadas (Módulo 11). Assunto e corpo vêm do
 * modelo editável (EmailTemplate); o corpo Markdown é renderizado dentro da
 * moldura de marca (mail.templated). O botão de ação vem da subclasse e sai
 * onde o marcador {botao} estiver no texto.
 */
abstract class TemplatedMailable extends Mailable
{
    use Queueable, QueuedDelivery, SerializesModels;

    private ?array $renderedTemplate = null;

    abstract protected function templateKey(): string;

    /** A chave do modelo, para quem decide se esta etapa da jornada sai (NotificaColaborador). */
    public function chaveDoModelo(): string
    {
        return $this->templateKey();
    }

    /** @return array<string, string> valores das variáveis {{ nome }} */
    abstract protected function templateData(): array;

    /**
     * Empresa dona desta comunicação, quando há uma.
     *
     * É ela que decide se o texto é o personalizado ou o padrão da WeGift. A
     * subclasse que sabe de qual empresa se trata sobrescreve; as que não têm
     * empresa (avisos internos) caem no padrão sem precisar declarar nada.
     */
    protected function templateCompanyId(): ?int
    {
        return null;
    }

    /**
     * Campanha dona desta comunicação, quando há uma.
     *
     * É o alcance mais específico: o texto do Projeto vence o da empresa, que
     * vence o padrão. A subclasse que sabe de qual Projeto se trata
     * sobrescreve; as demais caem no nível de cima sem declarar nada.
     */
    protected function templateProjectId(): ?int
    {
        return null;
    }

    protected function actionUrl(): ?string
    {
        return null;
    }

    protected function actionLabel(): string
    {
        return 'Acessar a WeLog';
    }

    private function rendered(): array
    {
        return $this->renderedTemplate ??= EmailTemplate::render(
            $this->templateKey(),
            $this->templateData(),
            $this->templateCompanyId(),
            $this->templateProjectId(),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered()['subject']);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.templated',
            with: [
                // O botão sai onde o marcador {botao} está na mensagem.
                ...EmailTemplate::partesDoCorpo($this->rendered()['body']),
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => $this->actionLabel(),
            ],
        );
    }
}
