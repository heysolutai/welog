<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo da campanha (Evento, Com estoque, Amostra, Sem estoque, Bosch), vindo
 * do projeto cadastrado na Omie.
 *
 * Fica gravado no Projeto, e não só derivado do código na hora de criar o
 * card, porque a tela mostra o tipo e o card pode ser recriado depois
 * (`welog:trello:setup --pedido=`) — reler a Omie só para lembrar de que tipo
 * a campanha é seria uma chamada a mais por card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('template', 40)->nullable()->after('omie_account');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('template');
        });
    }
};
