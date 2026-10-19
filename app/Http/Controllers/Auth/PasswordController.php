<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fluxos de senha (Fase 1): primeiro acesso (convite), esqueci minha senha e
 * redefinição. Todos apoiados no password broker do Laravel.
 */
class PasswordController extends Controller
{
    // ---- Esqueci minha senha -------------------------------------------------

    public function requestForm(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Resposta neutra: não revela se o e-mail existe (evita enumeração)
        return back()->with('status', 'Se o e-mail estiver cadastrado, enviamos um link para redefinir a senha.');
    }

    // ---- Redefinir (via link de reset) --------------------------------------

    public function resetForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        return $this->completePassword($request, 'Senha redefinida com sucesso. Faça login.', broker: 'users');
    }

    // ---- Primeiro acesso (via link de convite) ------------------------------

    public function setForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/SetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function set(Request $request): RedirectResponse
    {
        // Convite tem prazo próprio (config auth.passwords.invites), mais longo
        // que o de redefinição: a pessoa abre o e-mail dias depois de cadastrada.
        return $this->completePassword($request, 'Senha definida! Bem-vindo à WeLog.', login: true, broker: 'invites');
    }

    /**
     * Núcleo compartilhado: valida token+e-mail, grava a nova senha e marca
     * password_set_at. Opcionalmente já autentica (primeiro acesso).
     */
    private function completePassword(Request $request, string $success, bool $login = false, string $broker = 'users'): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $authenticated = null;

        $status = Password::broker($broker)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$authenticated) {
                $user->forceFill([
                    'password' => $password,
                    'password_set_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
                $authenticated = $user;
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        if ($login && $authenticated !== null && $authenticated->active) {
            Auth::login($authenticated);
            $request->session()->regenerate();

            return redirect()->intended(route($authenticated->homeRouteName()));
        }

        return redirect()->route('login')->with('status', $success);
    }
}
