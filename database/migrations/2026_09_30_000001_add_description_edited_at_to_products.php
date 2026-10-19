<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descrição escrita na WeLog vale mais que a da Omie.
 *
 * O espelho da Omie reescreve a descrição a cada importação. Isso era certo
 * enquanto ninguém editava aqui; com a tela de edição, a descrição que o
 * operador escreveu — geralmente porque a Omie não tinha nenhuma e a Reconhece
 * exige uma — sumiria no próximo "Importar da Omie", sem aviso nenhum.
 *
 * Preenchido = alguém editou aqui, e o espelho deixa a descrição em paz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('description_edited_at')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('description_edited_at');
        });
    }
};
