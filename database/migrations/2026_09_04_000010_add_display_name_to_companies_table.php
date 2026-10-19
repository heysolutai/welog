<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nome de exibição da Empresa Cliente (feedback do UAT).
 *
 * `name` é a razão social espelhada da Omie: a sincronização SOBRESCREVE o
 * campo a cada reimportação do pedido, então "Suvinil Tintas Industriais S/A"
 * sempre volta. Só que é esse nome que aparecia no e-mail de introdução do
 * Usuário, e ninguém apresenta a empresa pelo carimbo do CNPJ.
 *
 * `display_name` é o nome definido pela WeGift na tela de Empresas, usado em
 * tudo que é voltado ao Usuário (e-mails, portal). A sincronização com a Omie
 * NUNCA toca nele. Nulo = exibir a razão social mesmo (Company::label).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('display_name'));
    }
};
