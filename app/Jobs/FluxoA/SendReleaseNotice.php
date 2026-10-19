<?php

namespace App\Jobs\FluxoA;

use App\Mail\NotificaColaborador;
use App\Mail\ProjectReleasedMail;
use App\Models\ProjectEntitlement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

/**
 * O e-mail de "presente liberado" de UMA pessoa, com o link de criar senha
 * gerado aqui, na fila de e-mail.
 *
 * O link custa um bcrypt (0,2 a 0,4s na VPS). Gerado no laço da liberação ou
 * da importação, ele somava isso por pessoa; aqui roda no worker de e-mail,
 * que de qualquer forma espera o ritmo do provedor.
 *
 * Só é despachado quando a WeLog faz o primeiro contato: cliente que entra
 * pelo link de primeiro acesso não gera token nem e-mail
 * (Company::entradaPeloPrimeiroAcesso).
 */
class SendReleaseNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $entitlementId)
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
        $entitlement = ProjectEntitlement::with('user', 'product')->find($this->entitlementId);
        $user = $entitlement?->user;

        if ($user === null) {
            return;
        }

        /*
         * Quem ainda não criou a senha recebe, NESTE e-mail, um link novo para
         * criar. O convite do cadastro pode ter expirado ou se perdido, e
         * mandar essa pessoa para o portal com login era mandá-la para uma
         * porta fechada.
         */
        $setupToken = null;

        if (! $user->hasSetPassword()) {
            $setupToken = Password::broker('invites')->createToken($user);
            $user->forceFill(['invited_at' => now()])->save();
        }

        NotificaColaborador::enfileirar($user, new ProjectReleasedMail($entitlement, $setupToken));
    }
}
