<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envio do resgate à Flash (importação de encomendas na Bridge Link).
 *
 * `flash_sent_at` marca o envio aceito; `flash_error` guarda a última recusa,
 * para a página do resgate dizer por que a encomenda não foi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appnoz_import_rows', function (Blueprint $table) {
            $table->timestamp('flash_sent_at')->nullable()->after('tracking_code');
            $table->text('flash_error')->nullable()->after('flash_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appnoz_import_rows', function (Blueprint $table) {
            $table->dropColumn(['flash_sent_at', 'flash_error']);
        });
    }
};
