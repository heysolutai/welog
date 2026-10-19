<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devolução de estoque no cancelamento (pedido do cliente): a baixa feita na
 * Omie pelo resgate (`stock_pushed_at`) precisa do carimbo simétrico para a
 * entrada de volta ser idempotente — sem ele, uma retentativa do job
 * devolveria o mesmo saldo duas vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->timestamp('stock_returned_at')->nullable()->after('stock_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropColumn('stock_returned_at');
        });
    }
};
