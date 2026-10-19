<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials + ['active' => true], remember: true)) {
            // Conta existe mas ainda não teve a senha definida: orienta o convite
            $pending = \App\Models\User::where('email', $credentials['email'])
                ->whereNull('password_set_at')
                ->exists();

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => $pending
                    ? 'Você ainda não definiu sua senha. Use o link do convite enviado por e-mail ou clique em "Esqueci minha senha".'
                    : 'Credenciais inválidas.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route($request->user()->homeRouteName()));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
