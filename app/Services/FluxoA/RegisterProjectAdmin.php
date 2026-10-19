<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\InviteUser;
use App\Services\Auth\ReuseInactiveAdmin;
use Illuminate\Support\Str;

/**
 * Nascimento do Admin de um Projeto, venha da tela ou da planilha.
 *
 * A regra estava inteira dentro do controller, e a importação em lote passou
 * a precisar dela: com a coluna "Função", uma linha da planilha cadastra
 * Admin do Projeto em vez de Colaborador. Duas cópias da mesma regra é como
 * um dos caminhos deixa de checar empresa enquanto o outro checa.
 *
 * O alcance é sempre UM Projeto: a pessoa enxerga só ele, e a empresa vem do
 * próprio Projeto — ninguém precisa dizer qual é.
 */
class RegisterProjectAdmin
{
    public function __construct(
        private readonly InviteUser $inviteUser,
        private readonly ReuseInactiveAdmin $reuseInactive,
    ) {}

    /**
     * @param  array{name: string, email: string}  $data
     */
    public function handle(Project $project, array $data, User $actor): User
    {
        $this->authorize($project, $actor);

        $email = strtolower(trim($data['email']));

        $existente = User::whereRaw('LOWER(email) = ?', [$email])->first();

        // Admin desta empresa que foi tirado e ficou desativado: volta como
        // Admin deste Projeto na mesma conta (é a reatribuição).
        $reaproveitado = $this->reuseInactive->handle(
            $existente, (int) $project->company_id, User::ROLE_PROJECT_ADMIN, $project->id, $data['name'],
        );

        if ($reaproveitado !== null) {
            return $reaproveitado;
        }

        // O e-mail é o login: uma conta tem um papel só, e reaproveitar o de
        // um Colaborador transformaria em admin quem estava só resgatando.
        if ($existente !== null) {
            throw new DomainException(
                'O e-mail '.$email.' já pertence a outra conta. Use outro e-mail para este Admin.',
            );
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $email,
            // Senha aleatória placeholder — a pessoa define a dela via convite
            'password' => Str::password(32),
            'password_set_at' => null,
            'role' => User::ROLE_PROJECT_ADMIN,
            // A empresa é a do Projeto: ele já sabe de quem é.
            'company_id' => $project->company_id,
            'admin_project_id' => $project->id,
            'active' => true,
        ]);

        $this->inviteUser->handle($user);

        return $user;
    }

    /**
     * Ninguém cadastra o próprio nível para cima: a WeGift age em qualquer
     * Projeto, o Admin Cliente nos da empresa dele e o Admin de Projeto
     * apenas no dele.
     */
    private function authorize(Project $project, User $actor): void
    {
        if ($actor->canPerformCriticalActions() || $actor->managesProject($project)) {
            return;
        }

        throw new DomainException('Sem permissão para cadastrar Admins neste Projeto.');
    }
}
