<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Divergência de cadastro do cliente (cronograma S5, "Validações").
 *
 * Cliente ausente na Omie já era criado automaticamente; produto ausente já
 * bloqueava. Faltava o terceiro caso: cliente que EXISTE na Omie, mas com
 * dados diferentes dos que vieram na planilha do parceiro.
 *
 * Divergência NÃO bloqueia — o Anexo I é explícito em que produto ausente é "a
 * única condição bloqueante do fluxo". Ela alerta: o pedido segue, e o operador
 * decide depois se o certo é o que está na Omie ou o que veio na planilha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appnoz_import_rows', function (Blueprint $table) {
            // [{campo, na_planilha, na_omie}] — guardamos os DOIS lados porque
            // a resolução é uma escolha entre eles, e sem o valor de origem o
            // operador não teria o que comparar na hora de decidir.
            $table->jsonb('customer_divergences')->nullable()->after('customer_auto_created');
            $table->timestamp('divergence_resolved_at')->nullable()->after('customer_divergences');
            $table->string('divergence_resolution')->nullable()->after('divergence_resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('appnoz_import_rows', function (Blueprint $table) {
            $table->dropColumn(['customer_divergences', 'divergence_resolved_at', 'divergence_resolution']);
        });
    }
};
