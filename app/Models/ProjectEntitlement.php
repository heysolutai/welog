<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autorização de resgate: Usuário X pode resgatar o kit Y no Projeto Z.
 *
 * `choice_group` (UAT): autorizações no mesmo grupo são OPÇÕES alternativas.
 * A pessoa resgata UMA delas e as irmãs ficam indisponíveis. Nulo =
 * autorização independente (comportamento original).
 */
class ProjectEntitlement extends Model
{
    protected $fillable = ['project_id', 'user_id', 'product_id', 'choice_group', 'notified_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Pré-carrega o flag de resgate numa única query (evita 1 consulta por
     * usuário nas listagens do Projeto e do portal).
     */
    public function scopeWithRedeemedFlag(Builder $query): Builder
    {
        return $query->addSelect([
            'is_redeemed' => Redemption::query()
                ->selectRaw('1')
                ->whereColumn('redemptions.project_id', 'project_entitlements.project_id')
                ->whereColumn('redemptions.user_id', 'project_entitlements.user_id')
                ->whereColumn('redemptions.product_id', 'project_entitlements.product_id')
                ->where('status', '!=', Redemption::STATUS_CANCELED)
                ->limit(1),
        ]);
    }

    /** Já existe resgate ativo (não cancelado) para esta autorização? */
    public function isRedeemed(): bool
    {
        if (array_key_exists('is_redeemed', $this->attributes)) {
            return (bool) $this->attributes['is_redeemed'];
        }

        return Redemption::query()
            ->where('project_id', $this->project_id)
            ->where('user_id', $this->user_id)
            ->where('product_id', $this->product_id)
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            ->exists();
    }

    /**
     * Alguém do grupo de escolha já foi resgatado? É o que torna as demais
     * opções indisponíveis: quem escolheu um dos N brindes não leva os outros.
     * Olha o grupo INTEIRO, inclusive esta autorização, porque "já escolheu"
     * vale também quando a escolhida foi exatamente esta.
     */
    public function choiceGroupConsumed(): bool
    {
        if ($this->choice_group === null) {
            return false;
        }

        $productIds = self::query()
            ->where('choice_group', $this->choice_group)
            ->pluck('product_id');

        return Redemption::query()
            ->where('project_id', $this->project_id)
            ->where('user_id', $this->user_id)
            ->whereIn('product_id', $productIds)
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            ->exists();
    }
}
