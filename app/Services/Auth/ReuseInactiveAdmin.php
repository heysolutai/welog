<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Cadastrar de novo um admin que foi tirado e ficou desativado.
 *
 * É a reatribuição: o Admin Cliente que passa a cuidar de um Projeto só, ou
 * o Admin de Projeto que volta, ou que sobe para a empresa inteira. A conta
 * desativada guarda o histórico dele e ocupa o e-mail; sem isto, o cadastro
 * diria "o e-mail já pertence a outra conta" para a mesma pessoa.
 *
 * Só conta DESATIVADA, de admin, da MESMA empresa. Colaborador, equipe WeGift
 * ou admin de outro Cliente continuam recusados como antes.
 */
class ReuseInactiveAdmin
{
    public function __construct(private readonly InviteUser $inviteUser) {}

    /**
     * @return User|null a conta reativada no papel novo; null quando não há o
     *                   que reaproveitar
     */
    public function handle(?User $existente, int $companyId, string $role, ?int $projectId, string $name): ?User
    {
        if ($existente === null
            || $existente->active
            || (int) $existente->company_id !== $companyId
            || ! in_array($existente->role, [User::ROLE_ADMIN_CLIENT, User::ROLE_PROJECT_ADMIN], true)) {
            return null;
        }

        $existente->forceFill([
            'name' => $name,
            'role' => $role,
            'admin_project_id' => $role === User::ROLE_PROJECT_ADMIN ? $projectId : null,
            'active' => true,
        ])->save();

        // Quem já tinha senha volta a entrar com ela; quem não tinha recebe o
        // convite de novo.
        if (! $existente->hasSetPassword()) {
            $this->inviteUser->handle($existente);
        }

        return $existente;
    }
}
