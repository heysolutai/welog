<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customizações do Cliente — o que a WeGift liga e desliga por Empresa.
 *
 * Uma coluna JSON e não uma coluna por feature: a lista vai crescer a cada
 * ajuste pedido, e `ALTER TABLE` a cada chave nova é migração para um booleano.
 * O que existe hoje é `project_logo`.
 *
 * Nulo = nada foi parametrizado ainda. Quem lê resolve pelo padrão da feature
 * (ver `Company::customizacao()`), então ausência nunca vira erro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('customizations')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('customizations');
        });
    }
};
