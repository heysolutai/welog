<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppnozImport extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_BLOCKS = 'completed_with_blocks';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_DIRECTORY = 'directory';

    /** Empurrado pelo parceiro na nossa URL, sem arquivo no meio. */
    public const SOURCE_WEBHOOK = 'webhook';

    protected $fillable = [
        'filename', 'file_hash', 'source', 'imported_by', 'status',
        'rows_total', 'rows_created', 'rows_blocked', 'error',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(AppnozImportRow::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** Recalcula contadores/status a partir das linhas (chamado ao fim do processamento). */
    public function refreshCounters(): void
    {
        $rows = $this->rows()->get(['status']);
        $blocked = $rows->whereIn('status', [
            AppnozImportRow::STATUS_BLOCKED_STRUCTURAL,
            AppnozImportRow::STATUS_BLOCKED_PRODUCT,
        ])->count();

        $this->update([
            'rows_total' => $rows->count(),
            'rows_blocked' => $blocked,
            'rows_created' => $rows->count() - $blocked - $rows->where('status', AppnozImportRow::STATUS_PENDING)->count(),
            'status' => $blocked > 0 ? self::STATUS_COMPLETED_WITH_BLOCKS : self::STATUS_COMPLETED,
        ]);
    }
}
