<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Models\User;
use App\Services\Auth\RemoveAdminAccount;

/**
 * Tirar um Admin Cliente da empresa.
 *
 * O Admin Cliente enxerga TODOS os Projetos da empresa: não existe tirá-lo de
 * um Projeto só. Tirar é tirar a conta (apagada sem histórico, desativada com
 * ele; ver RemoveAdminAccount). Para ele seguir cuidando de alguns Projetos,
 * cadastre a mesma pessoa como Admin de cada um: a conta é reaproveitada.
 */
class RemoveClientAdmin
{
    public function __construct(private readonly RemoveAdminAccount $removeAccount) {}

    /** @return bool true quando a conta foi apagada; false quando foi desativada */
    public function handle(Company $company, User $admin): bool
    {
        if ($admin->role !== User::ROLE_ADMIN_CLIENT || (int) $admin->company_id !== (int) $company->id) {
            throw new DomainException('Esta pessoa não é Admin Cliente desta empresa.');
        }

        return $this->removeAccount->handle($admin);
    }
}
