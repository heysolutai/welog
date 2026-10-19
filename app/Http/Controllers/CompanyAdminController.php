<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\Auth\InviteUser;
use App\Services\FluxoA\RegisterEndCustomer;
use App\Services\FluxoA\RegisterProjectAdmin;
use App\Services\FluxoA\RemoveProjectAdmin;
use App\Services\Projects\ProjectPurger;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cadastro de Admin de Projeto, a partir do Projeto.
 *
 * Só isso: aqui nasce quem administra ESTE Projeto, e ninguém mais. O
 * endpoint já criou também o Admin Cliente, com um campo "tipo" para escolher
 * entre os dois, e isso trazia dois problemas de uma vez.
 *
 * O primeiro é de alcance: ninguém cadastra o próprio nível. O Admin Cliente
 * enxerga a empresa inteira, então criar outro Admin Cliente é fabricar um
 * par — quem faz isso é a WeGift, na ficha da Empresa. O Admin de Projeto,
 * pela mesma régua, só cadastra Admin do Projeto dele.
 *
 * O segundo é de lugar: com o cadastro partindo de um Projeto, "Geral" era a
 * opção que ignorava o contexto da tela em que a pessoa estava. Sem ela, a
 * empresa e o Projeto vêm do próprio Projeto, e não sobra o que perguntar
 * além de nome e e-mail.
 */
class CompanyAdminController extends Controller
{
    public function store(
        Request $request,
        RegisterProjectAdmin $registerAdmin,
        RegisterEndCustomer $registerCollaborator,
    ): RedirectResponse {
        $actor = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // O e-mail é o login e não pode colidir com outra conta. Quem
            // confere é o RegisterProjectAdmin: ele também reconhece o Admin
            // desativado deste Projeto, que volta em vez de ser recusado.
            'email' => ['required', 'email', 'max:255'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            // O Admin também resgata, como Colaborador: os itens e o modo são
            // os mesmos do cadastro de Colaborador.
            'resgata' => ['sometimes', 'boolean'],
            'product_ids' => ['exclude_unless:resgata,true', 'required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'distinct'],
            'mode' => ['exclude_unless:resgata,true', 'nullable', 'in:all,choose'],
        ], [], [
            'name' => 'nome',
            'email' => 'e-mail',
            'project_id' => 'Projeto',
            'product_ids' => 'itens',
            'mode' => 'modo',
        ]);

        $resgata = (bool) ($data['resgata'] ?? false);

        $project = Project::findOrFail($data['project_id']);

        // Admin de Projeto só cadastra no Projeto DELE: apontar para outro
        // seria alcançar o que ele nem enxerga.
        if ($actor->isProjectAdmin() && (int) $data['project_id'] !== (int) $actor->admin_project_id) {
            return back()->withErrors(['project_id' => 'Você administra apenas o seu Projeto.']);
        }

        // Quem não é da WeGift só age dentro da própria empresa, e o Projeto
        // não pode ser a porta dos fundos para fora dela.
        if (! $actor->canPerformCriticalActions() && $project->company_id !== $actor->company_id) {
            return back()->withErrors(['project_id' => 'Este Projeto pertence a outra Empresa.']);
        }

        /*
         * Liberar o resgate de um Admin é do Admin Cliente e da WeGift. O
         * Admin Projeto cadastra outros Admins do Projeto dele, mas não dá a
         * eles (nem a si) presente para resgatar.
         */
        if ($resgata && ! $this->podeDarResgate($actor, $project)) {
            return back()->withErrors(['resgata' => 'Só o Admin Cliente ou a WeGift liberam o resgate para um Admin.']);
        }

        // Conferido antes de criar a conta: item fora do pedido não pode
        // deixar um Admin criado pela metade.
        if ($resgata && ! $this->saoItensDoPedido($project, $data['product_ids'])) {
            return back()->withErrors(['product_ids' => 'Escolha apenas itens deste pedido.']);
        }

        // As checagens acima já filtram o previsível; o catch existe para o
        // que passa entre elas — dois cadastros do mesmo e-mail no mesmo
        // instante, que sem ele viraria 500 na cara de quem clicou.
        try {
            $user = $registerAdmin->handle($project, [
                'name' => $data['name'],
                'email' => $data['email'],
            ], $actor);
        } catch (DomainException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        if ($resgata) {
            try {
                $this->darResgate($registerCollaborator, $project, $user, $data, $actor);
            } catch (DomainException $e) {
                return back()->with('status', "Admin {$user->name} criado, mas sem o resgate: {$e->getMessage()}");
            }
        }

        Auditoria::registrar(
            AuditLog::COMPANY_ADMIN_CREATED,
            "Criou o Admin do Projeto {$user->name} ({$user->email}) em \"{$project->name}\"".($resgata ? ', com resgate.' : '.'),
            $user,
            ['projeto_id' => $project->id, 'resgata' => $resgata],
        );

        return back()->with(
            'status',
            'Admin do Projeto '.$project->name.' criado. Convite enviado para '.$user->email.'.'
                .($resgata ? ' Ele também pode resgatar o presente.' : ''),
        );
    }

    /**
     * Ligar o resgate de um Admin do Projeto que já existe.
     *
     * O cadastro já oferece a opção; isto é para quem foi cadastrado sem ela.
     * A mesma conta passa a resgatar, com a mesma escolha de itens do
     * Colaborador, e o "Meu presente" aparece no cabeçalho dele.
     */
    public function grantRedemption(
        Project $project,
        User $user,
        Request $request,
        RegisterEndCustomer $registerCollaborator,
    ): RedirectResponse {
        $actor = $request->user();

        abort_unless($this->podeDarResgate($actor, $project), 403);
        $this->garantirAdminDoProjeto($project, $user);

        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'distinct'],
            'mode' => ['nullable', 'in:all,choose'],
        ], [], ['product_ids' => 'itens', 'mode' => 'modo']);

        if (! $this->saoItensDoPedido($project, $data['product_ids'])) {
            return back()->withErrors(['product_ids' => 'Escolha apenas itens deste pedido.']);
        }

        if (ProjectEntitlement::where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            return back()->withErrors(['resgate' => "{$user->name} já pode resgatar neste Projeto."]);
        }

        try {
            $this->darResgate($registerCollaborator, $project, $user, $data, $actor);
        } catch (DomainException $e) {
            return back()->withErrors(['resgate' => $e->getMessage()]);
        }

        Auditoria::registrar(
            AuditLog::ENTITLEMENT_CREATED,
            "Liberou o resgate do Admin {$user->name} ({$user->email}) em \"{$project->name}\".",
            $user,
            ['projeto_id' => $project->id],
        );

        return back()->with('status', "{$user->name} também pode resgatar o presente.");
    }

    /**
     * Desligar o resgate de um Admin do Projeto. Ele continua Admin; sai o
     * presente, e o que já tiver resgatado sai junto, com o rastreio.
     */
    public function revokeRedemption(Project $project, User $user, Request $request, ProjectPurger $purger): RedirectResponse
    {
        abort_unless($this->podeDarResgate($request->user(), $project), 403);
        $this->garantirAdminDoProjeto($project, $user);

        $apagados = $purger->tirarDoProjeto($project, $user);

        Auditoria::registrar(
            AuditLog::ENTITLEMENT_DELETED,
            "Tirou o resgate do Admin {$user->name} ({$user->email}) em \"{$project->name}\".",
            $project,
            ['colaborador_id' => $user->id, 'conta_excluida' => false] + $apagados,
        );

        return back()->with('status', "{$user->name} não resgata mais neste Projeto. Continua como Admin.");
    }

    /**
     * Dar resgate a um Admin é da WeGift crítica e do Admin Cliente da
     * empresa do Projeto. O Admin Projeto cadastra outros Admins do Projeto
     * dele, mas não dá a eles (nem a si) presente para resgatar.
     */
    private function podeDarResgate(User $actor, Project $project): bool
    {
        return $actor->canPerformCriticalActions()
            || ($actor->isClientAdmin() && $actor->managesProject($project));
    }

    /** @param  array<int, int|string>  $ids */
    private function saoItensDoPedido(Project $project, array $ids): bool
    {
        $ids = array_unique(array_map('intval', $ids));

        return $project->products()->whereIn('products.id', $ids)->count() === count($ids);
    }

    private function garantirAdminDoProjeto(Project $project, User $user): void
    {
        abort_unless(
            $user->isProjectAdmin() && (int) $user->admin_project_id === (int) $project->id && $user->active,
            404,
        );
    }

    /**
     * O Admin vira também Colaborador deste Projeto: as mesmas autorizações,
     * pela mesma regra de itens e de escolha (RegisterEndCustomer).
     *
     * @param  array{product_ids: array<int, int>, mode?: ?string}  $data
     */
    private function darResgate(RegisterEndCustomer $registerCollaborator, Project $project, User $admin, array $data, User $actor): void
    {
        $registerCollaborator->handle($project, [
            'name' => $admin->name,
            'email' => $admin->email,
            'product_ids' => $data['product_ids'],
            'mode' => $data['mode'] ?? RegisterEndCustomer::MODE_ALL,
            'admin_com_resgate' => true,
        ], $actor);
    }

    /**
     * Tirar o Admin do Projeto. Só Admin WeGift (rota em `role:admin`): é o
     * mesmo nível de quem exclui o Projeto inteiro.
     */
    public function destroy(Project $project, User $user, RemoveProjectAdmin $removeAdmin): RedirectResponse
    {
        try {
            $apagado = $removeAdmin->handle($project, $user);
        } catch (DomainException $e) {
            return back()->withErrors(['remove' => $e->getMessage()]);
        }

        Auditoria::registrar(
            $apagado ? AuditLog::USER_DELETED : AuditLog::USER_DEACTIVATED,
            ($apagado ? 'Apagou' : 'Desativou')." o Admin do Projeto {$user->name} ({$user->email}) em \"{$project->name}\".",
            $apagado ? null : $user,
            ['projeto_id' => $project->id],
        );

        return back()->with('status', "{$user->name} não é mais Admin do Projeto {$project->name}.");
    }

    /**
     * Reenvia o convite de um Admin deste Projeto que ainda não criou a senha.
     * O primeiro pode ter ido para a quarentena do cliente ou vencido, e a
     * aba Admin não tinha como mandar de novo.
     */
    public function resendInvite(Project $project, User $user, InviteUser $inviteUser): RedirectResponse
    {
        abort_unless(
            $user->role === User::ROLE_PROJECT_ADMIN && (int) $user->admin_project_id === $project->id && $user->active,
            404,
        );

        if ($user->hasSetPassword()) {
            return back()->withErrors(['invite' => "{$user->name} já criou a senha."]);
        }

        $inviteUser->handle($user);

        Auditoria::registrar(AuditLog::USER_INVITE_SENT, "Reenviou o convite de acesso para {$user->email}.", $user);

        return back()->with('status', 'Convite reenviado para '.$user->email.'.');
    }
}
