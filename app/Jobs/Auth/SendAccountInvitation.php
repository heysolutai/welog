<?php

namespace App\Jobs\Auth;

use App\Mail\AccountInvitationMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * O convite de primeiro acesso, com o token gerado na fila de e-mail.
 *
 * O token passa por um bcrypt, lento de propósito (0,2 a 0,4s na VPS). Gerado
 * dentro da importação, ele custava isso em cada linha da planilha, e nove mil
 * linhas levavam quase uma hora. Aqui ele roda no worker de e-mail, que de
 * qualquer forma espera o ritmo do provedor, e a planilha não espera por ele.
 *
 * De quebra, o prazo do link começa a contar quando o e-mail sai, e não
 * quando a pessoa foi cadastrada.
 */
class SendAccountInvitation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $userId)
    {
        $this->onQueue(config('mail.queue'));
    }

    /** O mesmo espaçamento dos e-mails (ver QueuedDelivery). */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        // Criou a senha enquanto o convite esperava na fila (pelo link de
        // primeiro acesso, por exemplo): o convite não se aplica mais.
        if ($user === null || $user->hasSetPassword()) {
            return;
        }

        $token = Password::broker('invites')->createToken($user);

        Mail::to($user->email)->queue(new AccountInvitationMail($user, $token));
    }
}
