<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O link que a transportadora entrega pronto.
 *
 * Guardar a URL dentro de `tracking_code` seria cômodo e errado: o campo é
 * lido como código em quatro telas, entra no e-mail de expedição como "Código
 * de rastreio: ..." e é o que o robô dos Correios consulta. Uma URL em
 * qualquer um desses lugares vira lixo na cara de quem recebeu o presente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->string('tracking_url', 500)->nullable()->after('tracking_code');
        });
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropColumn('tracking_url');
        });
    }
};
