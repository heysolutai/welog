<?php

namespace App\Providers;

use App\Mail\Transport\MicrosoftGraphTransport;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Observability\SentryContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();

        SentryContext::register();
        $this->registerMicrosoftGraphMailer();
        $this->logSentMail();
        $this->auditAuthentication();
    }

    /**
     * MAIL_MAILER=microsoft-graph: envio pela conta Microsoft 365 sem SMTP.
     * Ver docs/EMAIL.md.
     */
    private function registerMicrosoftGraphMailer(): void
    {
        Mail::extend('microsoft-graph', fn () => new MicrosoftGraphTransport(
            $this->app->make(HttpFactory::class),
            (string) config('services.microsoft_graph.tenant_id'),
            (string) config('services.microsoft_graph.client_id'),
            (string) config('services.microsoft_graph.client_secret'),
            config('services.microsoft_graph.sender'),
        ));
    }

    /**
     * Entradas, saídas e tentativas falhas vão para a trilha de auditoria.
     *
     * Pelos eventos do guard, e não dentro do LoginController: login também
     * acontece na criação de senha (PasswordController faz `Auth::login`), e
     * ouvir o evento pega todos os caminhos de uma vez.
     */
    private function auditAuthentication(): void
    {
        Event::listen(function (Login $event) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

            Auditoria::registrar(AuditLog::LOGIN, 'Entrou na plataforma.', ator: $event->user);
        });

        Event::listen(function (Logout $event) {
            if ($event->user) {
                Auditoria::registrar(AuditLog::LOGOUT, 'Saiu da plataforma.', ator: $event->user);
            }
        });

        /*
         * Tentativa falha: grava o e-mail tentado, NUNCA a senha. Quando a
         * conta existe, o registro fica ligado a ela — é o que mostra alguém
         * tentando a senha de uma conta específica.
         */
        Event::listen(function (Failed $event) {
            $email = mb_strtolower(trim((string) ($event->credentials['email'] ?? '')));

            Auditoria::registrar(
                AuditLog::LOGIN_FAILED,
                "Tentativa de login falhou ({$email}).",
                dados: ['email_tentado' => $email],
                ator: $event->user instanceof User ? $event->user : null,
            );
        });

        Event::listen(function (PasswordReset $event) {
            Auditoria::registrar(AuditLog::PASSWORD_SET, 'Definiu ou redefiniu a senha.', ator: $event->user);
        });
    }

    /**
     * Registra cada e-mail que o provedor ACEITOU.
     *
     * O envio roda na fila, longe de quem clicou: `invited_at` diz que o
     * e-mail foi enfileirado, não que saiu. Sem esta linha no log, "não
     * chegou" não distingue worker parado, recusa do provedor e spam — que
     * exigem três ações completamente diferentes.
     */
    private function logSentMail(): void
    {
        Event::listen(function (MessageSent $event) {
            $para = array_keys($event->message->getTo() ?: []);

            Log::channel('mail')->info('E-mail enviado.', [
                'para' => implode(', ', $para),
                'assunto' => $event->message->getSubject(),
                'mailer' => $event->data['__laravel_mailable'] ?? config('mail.default'),
            ]);
        });
    }

    /** Limitadores de taxa das rotas sensíveis (Fase 6). */
    private function configureRateLimiters(): void
    {
        /*
         * Login e senha: a trava é por CONTA, não por origem.
         *
         * O teto por IP era de 20 por minuto, e isso quebra uma campanha
         * inteira. Nove mil colaboradores de um mesmo cliente saem todos pela
         * mesma saída de internet da empresa: para a plataforma, são nove mil
         * pessoas num punhado de IPs. Com o disparo do e-mail, vinte criam a
         * senha e o resto leva 429 — na primeira vez que a pessoa toca no
         * sistema, e no dia em que o cliente está olhando.
         *
         * Força bruta é uma senha atrás da outra na MESMA conta, e é isso que o
         * primeiro limite conta: cinco por minuto por e-mail e origem. Esse
         * fica firme — e é por pessoa, então mil colegas atrás do mesmo IP não
         * consomem a cota uns dos outros.
         *
         * O teto por IP continua existindo, mas alto: ele agora pega a varredura
         * que tenta mil e-mails diferentes, não o escritório do cliente.
         * `PORTAL_AUTH_IP_LIMIT` sobe no dia do disparo sem deploy.
         */
        $porIp = (int) config('welog.auth_ip_limit');

        RateLimiter::for('auth', fn (Request $request) => [
            // Normalizado: "Joao@x.com" e "joao@x.com " são a mesma conta, e
            // sem isto cada variação de digitação ganhava cota própria.
            Limit::perMinute(5)->by(mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip()),
            Limit::perMinute($porIp)->by($request->ip()),
        ]);

        /*
         * Primeiro acesso pela intranet do Cliente.
         *
         * Três tetos, porque são três abusos diferentes e nenhum sozinho pega
         * os outros:
         *
         *  - por e-mail: impede bombardear UMA pessoa com convites. Sem ele,
         *    quem não gosta do colega enche a caixa dele o dia inteiro.
         *  - por link: limita o estrago de um link vazado a um Cliente, e o
         *    número acomoda nove mil pessoas entrando na mesma manhã.
         *  - por IP: pega a varredura que testa nome.sobrenome@ em série. É
         *    alto de propósito, porque empresa grande sai toda pelo mesmo NAT
         *    e um teto baixo trancaria o escritório inteiro.
         */
        RateLimiter::for('primeiro-acesso', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('pa-mail:'.mb_strtolower(trim((string) $request->input('email')))),
            Limit::perMinute(120)->by('pa-link:'.$request->route('token')),
            Limit::perMinute((int) config('welog.auth_ip_limit'))->by('pa-ip:'.$request->ip()),
        ]);

        // Webhooks: teto alto por IP (picos legítimos de Omie/Trello)
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
