<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controle de acesso por perfil (módulo 10). Grupos aceitos:
 *  role:operator     → admin_wegift, wegift_n1, wegift_n2 (área operacional)
 *  role:critical     → admin_wegift, wegift_n1 (ações críticas)
 *  role:admin        → admin_wegift
 *  role:portal       → end_customer, admin_client, project_admin (portal do cliente)
 *  role:admin_client → admin_client, project_admin (área do cliente)
 *  ou papéis explícitos separados por vírgula.
 */
class EnsureRole
{
    private const GROUPS = [
        'operator' => User::OPERATOR_ROLES,
        'critical' => User::CRITICAL_ACTION_ROLES,
        'admin' => [User::ROLE_ADMIN_WEGIFT],
        'portal' => [User::ROLE_END_CUSTOMER, User::ROLE_ADMIN_CLIENT, User::ROLE_PROJECT_ADMIN],
        // `admin_client` virou grupo: o Admin Projeto (project_admin, UAT)
        // passa pelas MESMAS rotas do Admin Cliente — o escopo "só o
        // Projeto dele" é aplicado nos controllers via managesProject().
        'admin_client' => [User::ROLE_ADMIN_CLIENT, User::ROLE_PROJECT_ADMIN],
    ];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->active) {
            abort(403);
        }

        $allowed = [];
        foreach ($roles as $role) {
            $allowed = array_merge($allowed, self::GROUPS[$role] ?? [$role]);
        }

        if (! in_array($user->role, $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
