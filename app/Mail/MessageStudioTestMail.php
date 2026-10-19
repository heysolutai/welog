<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * O "Enviar teste para mim" do Estúdio de mensagens.
 *
 * Mesma moldura do e-mail de verdade (`mail.templated`), com o texto que está
 * na tela e os dados de exemplo. O link do botão leva ao portal, como no envio
 * real — o teste é para ver o e-mail, não para resgatar nada.
 */
class MessageStudioTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $assunto,
        public string $corpo,
        public string $botao,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->assunto);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.templated',
            with: [
                ...EmailTemplate::partesDoCorpo($this->corpo),
                'actionUrl' => route('portal.redemptions'),
                'actionLabel' => $this->botao,
            ],
        );
    }
}
