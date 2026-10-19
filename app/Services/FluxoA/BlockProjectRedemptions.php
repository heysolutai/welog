<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Freio de mão do resgate: bloquear e desbloquear, só do lado da WeGift.
 *
 *   Bloquear    → WeGift crítica (Admin WeGift e N1).
 *   Desbloquear → só o Admin WeGift.
 *
 * O Admin Cliente e o Admin Projeto já puderam bloquear (quem vê o problema
 * primeiro costuma ser o cliente). A operação decidiu tirar deles: parar a
 * campanha passa pela WeGift, que responde pela operação e pelo estoque. O
 * cliente que vir um problema avisa a WeGift.
 *
 * Soltar continua mais restrito que travar: enquanto o freio está puxado, o
 * saldo segue reservado para aquele Projeto.
 *
 * Bloqueio NÃO é o contrário de liberar: `released_at` continua onde está, e
 * por isso desbloquear não dispara e-mail nenhum — a campanha volta de onde
 * parou, sem avisar duas vezes as mesmas pessoas.
 */
class BlockProjectRedemptions
{
    /** Bloquear é da WeGift crítica (Admin WeGift e N1), nunca do lado do Cliente. */
    public static function canBlock(User $user, Project $project): bool
    {
        return $user->canPerformCriticalActions();
    }

    /**
     * Soltar é só do Admin WeGift. Nem o N1 solta: quem travou pode ter visto
     * algo que ainda não foi resolvido, e a conferência é da WeGift.
     */
    public static function canUnblock(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN_WEGIFT;
    }

    public function block(Project $project, User $actor, ?string $reason = null): Project
    {
        if (! self::canBlock($actor, $project)) {
            throw new DomainException('Este perfil não tem permissão para bloquear os resgates deste Projeto.');
        }

        if ($project->isRedemptionBlocked()) {
            throw new DomainException('Os resgates deste Projeto já estão bloqueados.');
        }

        $project->update([
            'redemptions_blocked_at' => now(),
            'redemptions_blocked_by' => $actor->id,
            'redemptions_block_reason' => $reason,
        ]);

        Log::info('Resgates do Projeto bloqueados.', [
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'role' => $actor->role,
            'motivo' => $reason,
        ]);

        return $project->refresh();
    }

    public function unblock(Project $project, User $actor): Project
    {
        if (! self::canUnblock($actor)) {
            throw new DomainException(
                'Só o Admin WeGift desbloqueia os resgates. Fale com a WeGift para liberar de novo.',
            );
        }

        if (! $project->isRedemptionBlocked()) {
            throw new DomainException('Os resgates deste Projeto não estão bloqueados.');
        }

        $project->update([
            'redemptions_blocked_at' => null,
            'redemptions_blocked_by' => null,
            'redemptions_block_reason' => null,
        ]);

        Log::info('Resgates do Projeto desbloqueados.', [
            'project_id' => $project->id,
            'user_id' => $actor->id,
        ]);

        return $project->refresh();
    }
}
