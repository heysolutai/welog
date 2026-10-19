<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TrackingEvent extends Model
{
    use MassPrunable;

    protected $fillable = ['occurred_at', 'status', 'description', 'location'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * Retenção: a timeline de entrega é histórico consultado pelo cliente
     * final e pelo suporte, então a janela é longa (12 meses por padrão).
     */
    public function prunable(): Builder
    {
        return static::query()->where('occurred_at', '<', now()->subDays(
            (int) config('integrations.retention.tracking_events_days', 365),
        ));
    }

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }
}
