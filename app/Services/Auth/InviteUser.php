<?php

namespace App\Services\Auth;

use App\Jobs\Auth\SendAccountInvitation;
use App\Mail\AccountInvitationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Envia (ou reenvia) o convite de primeiro acesso: gera um token pelo password
 * broker do Laravel e manda o e-mail com o link para o usuário definir a senha.
 *
 * Reutiliza o mesmo mecanismo do "esqueci minha senha" (tabela
 * password_reset_tokens), então o link expira conforme config/auth.php.
 */
class InviteUser
{
    public function handle(User $user): void
    {
        $token = Password::broker('invites')->createToken($user);

        $user->forceFill(['invited_at' => now()])->save();

        Mail::to($user->email)->queue(new AccountInvitationMail($user, $token));
    }

    /**
     * O mesmo convite, com o token gerado depois, na fila de e-mail.
     *
     * Para o cadastro em lote: o token custa um bcrypt, e gerá-lo aqui, linha
     * a linha, era metade do tempo de uma planilha grande. Ver
     * SendAccountInvitation.
     */
    public function handleLater(User $user): void
    {
        $user->forceFill(['invited_at' => now()])->save();

        SendAccountInvitation::dispatch($user->id);
    }
}
