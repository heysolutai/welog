<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Tirar a conta de um admin do Cliente (Admin Cliente ou Admin de Projeto).
 *
 * A conta é APAGADA quando não deixou rastro, que é o caso comum: alguém
 * cadastrado por engano, ou que nunca chegou a usar.
 *
 * Quando deixou rastro (pediu kits, que ficam no nome dele; subiu planilha;
 * liberou Projeto), apagar levaria junto o histórico, e o banco recusa. Aí a
 * conta é DESATIVADA: o acesso cai na hora (login e sessão aberta checam
 * `active`) e os registros ficam. Cadastrar a mesma pessoa de novo, como
 * Admin Cliente ou Admin de Projeto da mesma empresa, reaproveita essa conta
 * (ver ReuseInactiveAdmin).
 */
class RemoveAdminAccount
{
    /** @return bool true quando a conta foi apagada; false quando foi desativada */
    public function handle(User $admin): bool
    {
        try {
            DB::transaction(fn () => $admin->delete());

            return true;
        } catch (QueryException $e) {
            // Só a recusa por histórico vira desativação; qualquer outro erro
            // de banco segue adiante. 23503 é o Postgres; 23000, o SQLite.
            if (! in_array((string) $e->getCode(), ['23503', '23000'], true)) {
                throw $e;
            }
        }

        $admin->forceFill(['active' => false])->save();

        return false;
    }
}
