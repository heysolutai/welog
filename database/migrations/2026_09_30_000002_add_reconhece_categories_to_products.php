<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categoria e departamento da Reconhece, guardados no produto.
 *
 * Antes eles só existiam no item já publicado: quem preenchia e ainda não
 * enviava perdia tudo, e a tela abria vazia a cada visita. Aqui ficam salvos
 * independente do envio — e fora do alcance do espelho da Omie, que não sabe
 * que eles existem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('reconhece_product_type', 120)->nullable()->after('description_edited_at');
            $table->string('reconhece_department', 120)->nullable()->after('reconhece_product_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['reconhece_product_type', 'reconhece_department']);
        });
    }
};
