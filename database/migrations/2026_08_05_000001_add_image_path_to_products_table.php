<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto do kit enviada pela WeGift, separada da que é espelhada da Omie.
 *
 * Precisam ser colunas diferentes: o `MirrorOmieProduct` reescreve `image_url`
 * a cada sincronização do catálogo. Se a foto enviada morasse lá, o próximo
 * sync a apagaria — e ninguém entenderia por que a imagem sumiu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
