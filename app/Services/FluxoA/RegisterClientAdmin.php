<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Models\User;
use App\Services\Auth\InviteUser;
use App\Services\Auth\ReuseInactiveAdmin;
use Illuminate\Support\Str;

/**
 * Nascimento do Admin Cliente: quem enxerga a empresa inteira.
 *
 * A regra estava dentro da ficha da Empresa, e o cadastro manual de Projeto
 * passou a precisar dela — é lá que a empresa nova nasce, e empresa sem
 * responsável é um cliente que ninguém consegue operar. Duas cópias da mesma
 * regra é como um dos caminhos deixa de enviar o convite.
 *
 * Quem cria é sempre a WeGift: ninguém cadastra o próprio nível.
 */
class RegisterClientAdmin
{
    public function __construct(
        private readonly InviteUser $inviteUser,
        private readonly ReuseInactiveAdmin $reuseInactive,
    ) {}

    /**
     * @param  array{name: string, email: string}  $data
     */
    public function handle(Company $company, array $data, User $actor): User
    {
        if (! $actor->canPerformCriticalActions()) {
            throw new DomainException('Sem permissão para cadastrar o Admin Cliente.');
        }

        $email = strtolower(trim($data['email']));

        $existente = User::whereRaw('LOWER(email) = ?', [$email])->first();

        // Admin desta empresa que foi tirado e ficou desativado: volta como
        // Admin Cliente na mesma conta (é a reatribuição).
        $reaproveitado = $this->reuseInactive->handle(
            $existente, (int) $company->id, User::ROLE_ADMIN_CLIENT, null, $data['name'],
        );

        if ($reaproveitado !== null) {
            return $reaproveitado;
        }

        // O e-mail é o login: uma conta tem um papel só, e reaproveitar o de
        // um Colaborador daria a ele a empresa inteira.
        if ($existente !== null) {
            throw new DomainException(
                'O e-mail '.$email.' já pertence a outra conta. Use outro e-mail para o Admin Cliente.',
            );
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $email,
            // Senha aleatória placeholder — a pessoa define a dela via convite
            'password' => Str::password(32),
            'password_set_at' => null,
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $company->id,
            'active' => true,
        ]);

        $this->inviteUser->handle($user);

        return $user;
    }
}
