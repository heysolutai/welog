<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro bruto de todo webhook recebido (Omie, Trello).
 *
 * Serve a três propósitos: idempotência (a Omie/Trello reentregam eventos),
 * auditoria e reprocessamento manual em caso de falha do job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('source');                          // omie | trello
            $table->string('external_event_id')->nullable();   // messageId (Omie) / action id (Trello)
            $table->string('topic');                           // ex.: VendaProduto.Incluida
            $table->jsonb('payload');
            $table->string('status')->default('received');     // received | processed | failed
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_event_id']);
            $table->index(['source', 'topic']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
