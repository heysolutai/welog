<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O "ID Solicitação" da appnoz — a trava contra despachar o mesmo brinde duas
 * vezes.
 *
 * Até aqui a única trava era o lote inteiro: reenviou o mesmo lote, nada é
 * reprocessado. Isso resolve o retry do webhook e não resolve o caso comum da
 * operação, que é a mesma solicitação aparecer em DOIS relatórios — o da
 * manhã, ainda "solicitado", e o da tarde, junto com as novas. Sem um
 * identificador por linha, a segunda passada abre outro Pedido de Venda na
 * Omie, e um brinde de verdade sai duas vezes.
 *
 * `nullable` porque planilhas antigas não têm a coluna, e único porque é
 * exatamente essa a garantia que se quer: Postgres permite vários NULL num
 * índice único, então quem não traz o ID continua entrando como sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appnoz_import_rows', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('row_number');
            $table->unique('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('appnoz_import_rows', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
