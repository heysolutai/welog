<?php

namespace App\Support\Observability;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Contexto anexado a cada evento do Sentry.
 *
 * O objetivo é responder "quem, de qual empresa, com qual perfil" sem mandar
 * dado pessoal junto: ID e papel bastam para investigar e, se preciso, cruzar
 * com o banco. Nome e e-mail seriam PII num serviço de terceiros — o
 * SentryScrubber os remove mesmo que o SDK tente incluí-los.
 */
class SentryContext
{
    public static function register(): void
    {
        // Sem DSN o SDK não está ativo (dev e testes) — nada a fazer.
        if (empty(config('sentry.dsn'))) {
            return;
        }

        configureScope(function (Scope $scope): void {
            $scope->setTag('driver.omie', (string) config('integrations.omie.driver'));
            $scope->setTag('driver.trello', (string) config('integrations.trello.driver'));
            $scope->setTag('driver.correios', (string) config('integrations.correios.driver'));
            $scope->setTag('driver.flash', (string) config('integrations.flash.driver'));

            $user = Auth::user();

            if (! $user instanceof User) {
                return;
            }

            // Só o ID vai como identidade; o resto é tag, que é filtrável na
            // interface e não identifica ninguém sozinho.
            $scope->setUser(['id' => $user->id]);
            $scope->setTag('user.role', (string) $user->role);
            $scope->setTag('user.company_id', (string) ($user->company_id ?? 'sem-empresa'));
        });
    }
}
