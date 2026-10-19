<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As características do produto na Omie ("Tamanho Corta Vento: PP").
 *
 * O tamanho de cada variação da grade era lido do nome, e o nome é texto
 * livre: "Jaqueta corta vento G Unissex + Necessaire" põe o tamanho no meio,
 * e a leitura chegou a dar "Necessaire" como tamanho. A característica diz o
 * tamanho com todas as letras, e passa a valer antes do nome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // [{nome: "Tamanho Corta Vento", conteudo: "PP"}], como a Omie manda.
            $table->json('caracteristicas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('caracteristicas');
        });
    }
};
