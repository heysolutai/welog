<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um item do catálogo como o parceiro o cadastrou.
 *
 * Ver a migração para por que isto não mora em `products`. Em uma frase: ali é
 * o espelho da Omie, aqui é a fala do parceiro, e o sync de um não pode
 * atropelar o outro.
 */
class PartnerSku extends Model
{
    public const SOURCE_RECONHECE = 'reconhece';

    protected $fillable = [
        'source', 'sku', 'enabled',
        'sku_name', 'color', 'size', 'voltage',
        'parent_code', 'name', 'description',
        'product_type', 'department', 'images',
        'product_id', 'linked_at', 'linked_by', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'images' => 'array',
            'payload' => 'array',
            'linked_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    /**
     * Item avulso, sem variação.
     *
     * O próprio contrato do parceiro define assim: `parentCode` igual ao `sku`
     * quer dizer "este produto não tem modelos".
     */
    public function isAvulso(): bool
    {
        return $this->parent_code === $this->sku;
    }

    /**
     * Como a variação se chama, para quem lê a tela.
     *
     * O parceiro manda `skuName` OU os atributos soltos, e nem sempre os dois.
     * Montar a partir do que veio evita uma coluna vazia justamente nos itens
     * que têm variação — que são os que mais precisam ser distinguidos.
     */
    public function variacao(): ?string
    {
        if (filled($this->sku_name)) {
            return $this->sku_name;
        }

        $partes = array_filter([$this->color, $this->size, $this->voltage], 'filled');

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /** Chegou e ninguém amarrou: resgate deste SKU vai travar. */
    public function scopeSemVinculo(Builder $query): Builder
    {
        return $query->whereNull('product_id');
    }

    public function scopeDoParceiro(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }
}
