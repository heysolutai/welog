<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedDelivery;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Esqueci minha senha".
 *
 * Substitui a notificação padrão do Laravel, que saía em inglês e com a cara
 * genérica do framework — justo no momento em que a pessoa está sem acesso e
 * desconfiada de qualquer e-mail que peça para clicar num link. Mesma moldura,
 * fonte e assinatura do convite, para ela reconhecer de onde vem.
 *
 * Sai sempre, inclusive com os e-mails de comunicação desligados no Cliente:
 * foi a própria pessoa quem pediu.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, QueuedDelivery, SerializesModels;

    public function __construct(public User $user, public string $token)
    {
        $this->queueForDelivery();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Redefina sua senha da WeLog');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset',
            with: [
                'userName' => $this->user->name,
                'resetUrl' => route('password.reset', [
                    'token' => $this->token,
                    'email' => $this->user->email,
                ]),
                'validoPor' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }
}
