<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conta (CNPJ) da Omie que originou o dado.
 *
 * A WeGift tem mais de um CNPJ, e cada um é um aplicativo Omie com chave
 * própria: consultar um pedido exige a chave da conta CERTA. Guardar a conta
 * no evento e no Projeto é o que permite reconsultar depois sem adivinhação.
 *
 * Nulo = conta padrão (o histórico inteiro, gravado quando havia um CNPJ só).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('omie_account')->nullable()->after('source');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('omie_account')->nullable()->after('omie_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', fn (Blueprint $t) => $t->dropColumn('omie_account'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('omie_account'));
    }
};
