<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\InviteUser;
use App\Services\Projects\ProjectPurger;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestão de usuários WeGift (módulo 10) — restrito ao Admin WeGift.
 * Usuários internos entram pelo mesmo fluxo de convite/primeiro acesso.
 */
class UserController extends Controller
{
    /** Perfis que o admin pode atribuir por aqui (equipe WeGift). */
    private const ASSIGNABLE_ROLES = [
        User::ROLE_ADMIN_WEGIFT,
        User::ROLE_WEGIFT_N1,
        User::ROLE_WEGIFT_N2,
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::whereIn('role', self::ASSIGNABLE_ROLES)
                ->orderBy('name')
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'active' => $u->active,
                    'access_pending' => ! $u->hasSetPassword(),
                ]),
            'roles' => $this->roleOptions(),
        ]);
    }

    public function store(Request $request, InviteUser $inviteUser): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Str::password(32),
            'password_set_at' => null,
            'role' => $data['role'],
            'active' => true,
        ]);

        $inviteUser->handle($user);

        Auditoria::registrar(AuditLog::USER_CREATED, "Criou o usuário {$user->name} ({$user->email}) com perfil {$user->role}.", $user, [
            'perfil' => $user->role,
        ]);

        return back()->with('status', 'Usuário criado. Convite enviado para '.$user->email.'.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless(in_array($user->role, self::ASSIGNABLE_ROLES, true), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        // Um admin não pode se rebaixar sozinho (evita ficar sem admin)
        if ($user->id === $request->user()->id && $data['role'] !== User::ROLE_ADMIN_WEGIFT) {
            return back()->withErrors(['role' => 'Você não pode alterar o próprio perfil de administrador.']);
        }

        $antes = $user->only(['name', 'role']);
        $user->update($data);

        // Mudança de perfil é a alteração de permissão: o "de → para" fica no registro.
        Auditoria::registrar(AuditLog::USER_UPDATED, "Alterou o usuário {$user->name} ({$user->email}).", $user, [
            'antes' => $antes,
            'depois' => $user->only(['name', 'role']),
        ]);

        return back()->with('status', 'Usuário atualizado.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        abort_unless(in_array($user->role, self::ASSIGNABLE_ROLES, true), 404);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['active' => 'Você não pode desativar a si mesmo.']);
        }

        $user->update(['active' => ! $user->active]);

        Auditoria::registrar(
            $user->active ? AuditLog::USER_ACTIVATED : AuditLog::USER_DEACTIVATED,
            ($user->active ? 'Ativou' : 'Desativou')." o usuário {$user->name} ({$user->email}).",
            $user,
        );

        return back()->with('status', $user->active ? 'Usuário ativado.' : 'Usuário desativado.');
    }

    /**
     * Exclusão definitiva de QUALQUER usuário, pelo Admin WeGift.
     *
     * Diferente de "desativar", que preserva o histórico: aqui somem a conta,
     * as autorizações e os resgates da pessoa. É o poder que o Admin WeGift
     * precisa ter sobre a base inteira, equipe e clientes.
     */
    public function destroy(Request $request, User $user, ProjectPurger $purger): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['destroy' => 'Você não pode excluir a si mesmo.']);
        }

        $nome = $user->name;
        $apagados = $purger->apagarUsuario($user);

        Log::info('Usuário excluído pelo Admin WeGift.', [
            'usuario' => $nome,
            'email' => $user->email,
            'papel' => $user->role,
            'por' => $request->user()->id,
        ] + $apagados);

        // O alvo já não existe: o registro guarda nome, e-mail e o que foi junto.
        Auditoria::registrar(AuditLog::USER_DELETED, "Excluiu o usuário {$nome} ({$user->email}).", dados: [
            'usuario_id' => $user->id,
            'perfil' => $user->role,
        ] + $apagados);

        return back()->with(
            'status',
            "{$nome} excluído: {$apagados['project_entitlements']} autorização(ões) e {$apagados['redemptions']} resgate(s) removidos.",
        );
    }

    public function resendInvite(User $user, InviteUser $inviteUser): RedirectResponse
    {
        abort_unless(in_array($user->role, self::ASSIGNABLE_ROLES, true), 404);

        if ($user->hasSetPassword()) {
            return back()->withErrors(['invite' => 'Este usuário já definiu a senha.']);
        }

        $inviteUser->handle($user);

        Auditoria::registrar(AuditLog::USER_INVITE_SENT, "Reenviou o convite de acesso para {$user->email}.", $user);

        return back()->with('status', 'Convite reenviado.');
    }

    private function roleOptions(): array
    {
        return [
            ['value' => User::ROLE_ADMIN_WEGIFT, 'label' => 'Admin WeGift'],
            ['value' => User::ROLE_WEGIFT_N1, 'label' => 'Operador N1'],
            ['value' => User::ROLE_WEGIFT_N2, 'label' => 'Operador N2 (consulta)'],
        ];
    }
}
