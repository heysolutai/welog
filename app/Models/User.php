<?php

namespace App\Models;

use App\Mail\PasswordResetMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

#[Fillable(['name', 'email', 'password', 'role', 'company_id', 'admin_project_id', 'cpf', 'phone', 'active', 'invited_at', 'password_set_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Módulo 10 — perfis de acesso
    public const ROLE_ADMIN_WEGIFT = 'admin_wegift';

    public const ROLE_WEGIFT_N1 = 'wegift_n1';

    public const ROLE_WEGIFT_N2 = 'wegift_n2';

    public const ROLE_ADMIN_CLIENT = 'admin_client';

    // Admin Projeto (UAT): o que o Admin Cliente enxerga, escopado a UM
    // Projeto. Tem company_id E admin_project_id preenchidos.
    public const ROLE_PROJECT_ADMIN = 'project_admin';

    public const ROLE_END_CUSTOMER = 'end_customer';

    // Papéis com acesso à área operacional WeGift
    public const OPERATOR_ROLES = [
        self::ROLE_ADMIN_WEGIFT,
        self::ROLE_WEGIFT_N1,
        self::ROLE_WEGIFT_N2,
    ];

    // Papéis autorizados a executar ações críticas (liberar Projeto, importar)
    public const CRITICAL_ACTION_ROLES = [
        self::ROLE_ADMIN_WEGIFT,
        self::ROLE_WEGIFT_N1,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'invited_at' => 'datetime',
            'password_set_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * "Esqueci minha senha" em português e na moldura da WeLog, no lugar da
     * notificação padrão do Laravel (que saía em inglês).
     */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->queue(new PasswordResetMail($this, $token));
    }

    /** Já definiu a própria senha? Se não, o login por senha fica bloqueado. */
    public function hasSetPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    /** Nome da rota inicial conforme o perfil (destino pós-login). */
    public function homeRouteName(): string
    {
        return match (true) {
            $this->isOperator() => 'dashboard',
            $this->isClientSideAdmin() => 'projects.index',
            default => 'portal.catalog',
        };
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Projeto que este usuário administra (apenas o papel project_admin). */
    public function adminProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'admin_project_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(ProjectEntitlement::class);
    }

    /**
     * Colaborador que não está mais em Projeto nenhum: sem autorização e sem
     * resgate em andamento (cancelado não conta).
     *
     * A conta fica presa à empresa em que nasceu. Quando a pessoa sai de todos
     * os Projetos dela (removida, ou o item dela tirado do Projeto), não há o
     * que proteger, e outro Cliente pode cadastrá-la: a conta muda de empresa
     * em vez de o e-mail ficar bloqueado para sempre.
     */
    public function colaboradorSemProjeto(): bool
    {
        return $this->role === self::ROLE_END_CUSTOMER
            && ! $this->entitlements()->exists()
            && ! Redemption::where('user_id', $this->id)->where('status', '!=', Redemption::STATUS_CANCELED)->exists();
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    public function isOperator(): bool
    {
        return in_array($this->role, self::OPERATOR_ROLES, true);
    }

    public function canPerformCriticalActions(): bool
    {
        return in_array($this->role, self::CRITICAL_ACTION_ROLES, true);
    }

    public function isClientAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN_CLIENT;
    }

    public function isProjectAdmin(): bool
    {
        return $this->role === self::ROLE_PROJECT_ADMIN;
    }

    /** Admin do lado do cliente: Admin Cliente (admin_client) ou Admin Projeto. */
    public function isClientSideAdmin(): bool
    {
        return $this->isClientAdmin() || $this->isProjectAdmin();
    }

    /**
     * Gerencia este Projeto? O Admin Cliente gerencia todos os
     * Projetos da empresa dele; o Admin Projeto, apenas o Projeto ao qual
     * está vinculado. Papéis WeGift não passam por aqui (têm as próprias regras).
     */
    public function managesProject(Project $project): bool
    {
        return match (true) {
            $this->isClientAdmin() => $this->company_id !== null && $this->company_id === $project->company_id,
            $this->isProjectAdmin() => $this->admin_project_id !== null && $this->admin_project_id === $project->id,
            default => false,
        };
    }

    public function isEndCustomer(): bool
    {
        return $this->role === self::ROLE_END_CUSTOMER;
    }
}
