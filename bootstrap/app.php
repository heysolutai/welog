<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Webhooks (Omie, Trello) entram pelo grupo "api": stateless e sem CSRF
        api: __DIR__.'/../routes/webhooks.php',
        apiPrefix: 'webhooks',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
        $middleware->redirectGuestsTo('/login');

        /*
         * Para onde vai quem JÁ está autenticado e abre uma rota de visitante.
         *
         * Sem isto o padrão do Laravel é '/dashboard', que exige perfil de
         * operador — então o Admin Cliente que reabrisse o link do convite (ou
         * digitasse só o domínio, já que '/' redireciona para '/login') caía
         * num 403 cujo único botão devolvia ao mesmo 403. Sem saída, e no
         * minuto seguinte ao primeiro acesso.
         *
         * `homeRouteName()` é o mesmo destino que o LoginController já usa.
         */
        $middleware->redirectUsersTo(
            fn (Request $request) => route($request->user()->homeRouteName()),
        );
        // Inertia: injeta o compartilhamento de props no grupo web
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        // Único ingresso é o nginx (a app não é exposta ao host): confiar no
        // proxy para obter o IP real do cliente (rate limiting/auditoria).
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry (só reporta se SENTRY_LARAVEL_DSN estiver definido — ou seja,
        // produção). O que é filtrado e por quê: config/sentry.php.
        Integration::handles($exceptions);

        // `cep/*` e o Estúdio de mensagens são consumidos por fetch a partir
        // das telas: sem isto, sessão expirada devolveria o HTML do login com
        // status 200, e um erro de validação viraria redirect em vez do 422
        // que a tela usa para mostrar o erro no campo.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('cep/*')
                || $request->is('mensagens-do-resgate/*'),
        );

        /*
         * 403 dentro do app (navegação Inertia) vira página Inertia.
         *
         * Sem isto o Blade de erro chega embrulhado no modal/iframe do
         * Inertia e os botões "não funcionam": o clique navega o iframe,
         * não a janela. Acesso direto por URL continua caindo no Blade
         * (resources/views/errors/403.blade.php), que funciona.
         */
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if ($response->getStatusCode() === 403 && $request->header('X-Inertia') && $request->user()) {
                return Inertia::render('Errors/Forbidden', [
                    'home' => route($request->user()->homeRouteName()),
                ])->toResponse($request)->setStatusCode(403);
            }

            // Primeiro acesso: o limite de pedidos por e-mail vira frase no
            // campo. Sem isto, o "Enviar de novo" insistente abria a página
            // de erro crua num modal, na tela de quem nem tem senha ainda.
            if ($response->getStatusCode() === 429 && $request->routeIs('primeiro-acesso.send')) {
                return back()->withErrors([
                    'email' => 'Muitos pedidos para este e-mail. Espere alguns minutos e tente de novo.',
                ]);
            }

            return $response;
        });
    })->create();
