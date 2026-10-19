<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importação cancelada pela tela.
 *
 * Sem isto, parar uma planilha errada de nove mil linhas era limpar a fila no
 * servidor, e a tela ficava com um "Importação concluída · 0 de 8.815" que
 * não dizia nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entitlement_imports', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('entitlement_imports', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
