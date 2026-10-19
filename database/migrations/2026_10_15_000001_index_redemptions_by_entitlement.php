<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Esta autorização já foi resgatada?" é a pergunta que a tela do Projeto e o
 * portal fazem por autorização (ProjectEntitlement::withRedeemedFlag): um
 * resgate do mesmo Projeto, pessoa e item. O índice que existia era
 * (project_id, status), que numa campanha de nove mil resgates obrigava cada
 * pergunta a percorrer todos os resgates do Projeto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->index(['project_id', 'user_id', 'product_id'], 'redemptions_entitlement_index');
        });
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropIndex('redemptions_entitlement_index');
        });
    }
};
