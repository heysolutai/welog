<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opções de escolha (UAT): autorizações no MESMO `choice_group` são
 * alternativas entre si. O Usuário pode ter 5 opções e apenas 1 resgate: ao
 * resgatar uma, as irmãs do grupo ficam indisponíveis (a trava vive no
 * RedeemKit e na leitura do portal). Nulo = autorização independente, o
 * comportamento que sempre existiu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_entitlements', function (Blueprint $table) {
            $table->uuid('choice_group')->nullable()->after('product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('project_entitlements', function (Blueprint $table) {
            $table->dropIndex(['choice_group']);
            $table->dropColumn('choice_group');
        });
    }
};
