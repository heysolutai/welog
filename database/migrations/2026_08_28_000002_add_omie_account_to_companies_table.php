<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qual CNPJ da WeGift veio esta Empresa Cliente.
 *
 * A empresa nasce do pedido, e o pedido é de uma conta (aplicativo) da Omie.
 * Guardar a origem responde na tela "esse cliente é da WeBox ou da Santa?" sem
 * abrir Projeto por Projeto.
 *
 * Nulo = cadastrada antes do multi-CNPJ, ou criada à mão no WeLog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('omie_account')->nullable()->after('omie_customer_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('omie_account'));
    }
};
