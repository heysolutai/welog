<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca a NF que não existe: gerada pelo `welog:nf:simular` para testar o
 * Fluxo B sem emitir nota de verdade (nota oficial tem custo).
 *
 * É a marca que impede a nota simulada de seguir para a Flash real e que a
 * identifica na tela — sem ela, uma linha de teste seria indistinguível de um
 * pedido faturado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appnoz_import_rows', function (Blueprint $table) {
            $table->boolean('nf_simulated')->default(false)->after('nf_key');
        });
    }

    public function down(): void
    {
        Schema::table('appnoz_import_rows', function (Blueprint $table) {
            $table->dropColumn('nf_simulated');
        });
    }
};
