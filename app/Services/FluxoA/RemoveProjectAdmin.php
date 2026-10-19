<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\Auth\RemoveAdminAccount;

/**
 * Tirar um Admin do Projeto.
 *
 * A conta do Admin de Projeto existe só para aquele Projeto, então tirá-lo
 * do Projeto é tirar a conta: apagada sem histórico, desativada com ele (ver
 * RemoveAdminAccount).
 */
class RemoveProjectAdmin
{
    public function __construct(private readonly RemoveAdminAccount $removeAccount) {}

    /** @return bool true quando a conta foi apagada; false quando foi desativada */
    public function handle(Project $project, User $admin): bool
    {
        if ($admin->role !== User::ROLE_PROJECT_ADMIN || (int) $admin->admin_project_id !== (int) $project->id) {
            throw new DomainException('Esta pessoa não é Admin deste Projeto.');
        }

        // O resgate dele neste Projeto sai junto. Resgate já feito continua
        // no histórico (ele não depende da autorização).
        ProjectEntitlement::where('project_id', $project->id)->where('user_id', $admin->id)->delete();

        return $this->removeAccount->handle($admin);
    }
}
