<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca quando a estrutura (BOM) do produto foi lida da Omie.
 *
 * Sem isso não existe a pergunta "já conferi este produto?" — só "ele tem
 * componentes?", que é outra coisa: a maioria do catálogo é produto simples e
 * responde "não" para sempre. A consequência prática era dupla: a varredura
 * nunca fechava em zero, e o backfill reconsultava os mesmos ~140 códigos a
 * cada execução, que é justamente a rajada bloqueada pela Omie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('structure_synced_at')->nullable()->after('is_kit');
            $table->index('structure_synced_at');
        });

        // Quem já tem componentes só pode tê-los vindo do `ConsultarProduto`:
        // é o único endpoint que devolve estrutura. Marcar evita uma releitura
        // inútil desses no primeiro backfill.
        DB::table('products')
            ->whereIn('id', DB::table('kit_items')->select('kit_product_id'))
            ->update(['structure_synced_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['structure_synced_at']);
            $table->dropColumn('structure_synced_at');
        });
    }
};
