<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de auditoria: quem fez o quê, quando e de onde.
 *
 * Até aqui as ações sensíveis deixavam rastro só no log de arquivo (14 dias)
 * ou em colunas soltas (`released_by`, `imported_by`). Nenhuma das duas
 * responde à pergunta de um assessment de segurança — "mostre quem acessou e
 * alterou dados pessoais no último ano".
 *
 * O ator é guardado em CÓPIA (nome, e-mail, papel) além do `user_id`: a conta
 * pode ser excluída depois, e o registro precisa continuar dizendo quem foi.
 * Só `created_at`, sem `updated_at` — registro de auditoria não se edita.
 *
 * Também cria `users.last_login_at`, que a revisão periódica de acessos usa
 * para achar conta privilegiada esquecida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_role', 40)->nullable();
            $table->string('action', 80);
            $table->string('description', 500);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });

        Schema::dropIfExists('audit_logs');
    }
};
