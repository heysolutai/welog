<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeamento lista do Trello → status de pipeline na WeLog.
 * Mantido como dado (não código) para o Admin ajustar quando o board mudar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trello_list_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('trello_list_id')->unique();
            $table->string('trello_list_name');
            // O board vai até Envio; "Em Estoque" e "Concluído" são da WeLog.
            $table->string('pipeline_status'); // design|production|assembly|shipping
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trello_list_mappings');
    }
};
