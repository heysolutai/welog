<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variação escolhida pelo colaborador (cor/tamanho), pelo parent code.
 *
 * O pedido da Omie traz só o produto PAI ("Camiseta Preta" × 15) e o saldo é
 * dele: nada é produzido antes de as pessoas escolherem. As opções vêm do
 * catálogo do parceiro — os SKUs com `parent_code` igual ao código do pai —, e
 * o resgate guarda a escolha em CÓPIA (sku, cor, tamanho): é o que a produção
 * soma depois, e precisa sobreviver a uma mudança no catálogo.
 *
 * Também desfaz as colunas de família/cor/tamanho em `products` da primeira
 * versão (estoque por variação), que não corresponde à operação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->string('variant_sku', 120)->nullable();
            $table->string('variant_color', 60)->nullable();
            $table->string('variant_size', 20)->nullable();
        });

        if (Schema::hasColumn('products', 'variant_family')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['variant_family']);
                $table->dropColumn(['variant_family', 'variant_color', 'variant_color_hex', 'variant_size']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropColumn(['variant_sku', 'variant_color', 'variant_size']);
        });
    }
};
