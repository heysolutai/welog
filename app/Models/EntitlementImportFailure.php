<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha recusada de uma importação, com os dados dela (ver a migração). */
class EntitlementImportFailure extends Model
{
    protected $fillable = ['entitlement_import_id', 'linha', 'mensagem', 'dados'];

    protected function casts(): array
    {
        return ['dados' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(EntitlementImport::class, 'entitlement_import_id');
    }
}
