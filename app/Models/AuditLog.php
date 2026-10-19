<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Um registro da trilha de auditoria. Gravado por App\Support\Auditoria.
 *
 * Só inclusão: o modelo recusa editar e apagar um registro. A única saída é a
 * retenção (`model:prune`, WELOG_AUDIT_RETENTION_DAYS), que roda em massa e
 * por idade — ninguém escolhe o que some da trilha.
 */
class AuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /*
     * Ações registradas. O prefixo agrupa na tela de consulta (auth., user.,
     * project., ...) — ação nova entra no grupo sem tocar no Vue.
     */
    public const LOGIN = 'auth.login';

    public const LOGIN_FAILED = 'auth.login_failed';

    public const LOGOUT = 'auth.logout';

    public const PASSWORD_SET = 'auth.password_set';

    public const USER_CREATED = 'user.created';

    public const USER_UPDATED = 'user.updated';

    public const USER_ACTIVATED = 'user.activated';

    public const USER_DEACTIVATED = 'user.deactivated';

    public const USER_DELETED = 'user.deleted';

    public const USER_INVITE_SENT = 'user.invite_sent';

    public const ACCESS_REVIEW = 'user.access_review';

    public const COMPANY_UPDATED = 'company.updated';

    public const COMPANY_DELETED = 'company.deleted';

    public const COMPANY_ADMIN_CREATED = 'company.admin_created';

    public const PROJECT_RELEASED = 'project.released';

    public const PROJECT_DELETED = 'project.deleted';

    public const PROJECT_UPDATED = 'project.updated';

    public const PROJECT_REDEMPTIONS_BLOCKED = 'project.redemptions_blocked';

    public const PROJECT_REDEMPTIONS_UNBLOCKED = 'project.redemptions_unblocked';

    public const ENTITLEMENT_CREATED = 'entitlement.created';

    public const ENTITLEMENT_IMPORTED = 'entitlement.imported';

    public const ENTITLEMENT_DELETED = 'entitlement.deleted';

    public const REDEMPTION_CPF_CHANGED = 'redemption.cpf_changed';

    public const REDEMPTION_ADDRESS_CHANGED = 'redemption.address_changed';

    public const REDEMPTION_CANCELLED = 'redemption.cancelled';

    public const PARTNER_IMPORT_CREATED = 'partner_import.created';

    public const EXPORT_DOWNLOADED = 'export.downloaded';

    /** Rótulo de cada grupo na tela de consulta. */
    public const GROUPS = [
        'auth' => 'Acesso',
        'user' => 'Usuários',
        'company' => 'Clientes',
        'project' => 'Projetos',
        'entitlement' => 'Colaboradores',
        'redemption' => 'Resgates',
        'partner_import' => 'Importações Parceiros',
        'export' => 'Exportações',
    ];

    protected $fillable = [
        'user_id', 'user_name', 'user_email', 'user_role',
        'action', 'description', 'subject_type', 'subject_id',
        'properties', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registro de auditoria não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('Registro de auditoria não pode ser apagado.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): string
    {
        return explode('.', $this->action, 2)[0];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(
            (int) config('welog.audit.retention_days', 730),
        ));
    }
}
