<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dois índices para a hora do pico.
 *
 * `project_entitlements` só tinha a chave única (project_id, user_id,
 * product_id). Índice composto serve a consulta que começa pela PRIMEIRA
 * coluna, e a consulta mais quente da plataforma começa pela segunda: o portal
 * abre perguntando "quais brindes são MEUS", filtrando só por `user_id`. Com
 * nove mil autorizações numa campanha, isso é uma varredura da tabela inteira
 * a cada abertura do catálogo — multiplicada por nove mil pessoas chegando ao
 * mesmo tempo.
 *
 * O segundo índice é do anúncio da liberação, que pergunta em lotes "quem
 * deste Projeto ainda não foi avisado". Parcial, só sobre as linhas ainda não
 * avisadas: o índice encolhe conforme o disparo avança e some quando termina,
 * em vez de carregar as nove mil já notificadas para sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_entitlements', function (Blueprint $table) {
            $table->index('user_id', 'project_entitlements_user_id_index');
        });

        // Índice parcial é coisa de Postgres; o SQLite dos testes ignora o
        // `where` e cria o índice inteiro, o que serve igual para o plano.
        Schema::table('project_entitlements', function (Blueprint $table) {
            $table->index(['project_id', 'notified_at'], 'project_entitlements_pendentes_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_entitlements', function (Blueprint $table) {
            $table->dropIndex('project_entitlements_user_id_index');
            $table->dropIndex('project_entitlements_pendentes_index');
        });
    }
};
