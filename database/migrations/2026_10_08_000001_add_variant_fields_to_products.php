<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variações de produto (cor e tamanho), mostradas no portal como numa loja.
 *
 * Na Omie cada variação é um produto à parte — código e estoque próprios
 * ("Moletom com Capuz Preto P", "… Preto M"). Aqui elas continuam sendo
 * produtos à parte, porque o estoque É por variação; o que se acrescenta é a
 * marca de família, cor e tamanho que a Omie não tem em campo próprio.
 *
 * `variant_family` é o nome do produto que o colaborador vê ("Moletom com
 * Capuz"); produtos com o mesmo valor são variações umas das outras.
 * Nulo = produto sem variação, como sempre foi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('variant_family', 150)->nullable()->index();
            $table->string('variant_color', 60)->nullable();
            // Cor da bolinha no portal (#RRGGBB). Nula = a tela deduz do nome.
            $table->string('variant_color_hex', 7)->nullable();
            $table->string('variant_size', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['variant_family']);
            $table->dropColumn(['variant_family', 'variant_color', 'variant_color_hex', 'variant_size']);
        });
    }
};
