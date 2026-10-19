<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens tirados do Projeto à mão pelo Admin WeGift.
 *
 * A Omie reenvia os itens do pedido a cada alteração do Pedido de Venda, e
 * sem esta lista o item removido voltaria na próxima. Cada entrada guarda o
 * que a ligação tinha (quantidade e saldo do Projeto), para restaurar:
 *
 *   { "prd11361_3": { product_id, omie_code, name, quantity, stock_qty,
 *                     stock_loaded_at, removed_at, removed_by } }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('removed_items')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('removed_items');
        });
    }
};
