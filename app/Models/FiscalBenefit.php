<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalBenefit extends Model
{
    protected $fillable = ['product_code', 'benefit_code', 'description'];

    public static function codeForProduct(?string $productCode): ?string
    {
        if ($productCode === null) {
            return null;
        }

        return static::where('product_code', $productCode)->value('benefit_code');
    }
}
