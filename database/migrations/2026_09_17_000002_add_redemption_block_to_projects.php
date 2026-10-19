<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freio de mão do resgate, pedido pela operação.
 *
 * É diferente da liberação (`released_at`), e por isso um campo próprio: a
 * liberação é o ato de ABRIR, uma vez, com autor e hora; o bloqueio é uma
 * suspensão temporária de quem percebeu um problema — endereço errado em
 * massa, kit com defeito, campanha adiada — e que precisa ser desfeita por
 * quem responde pela operação.
 *
 * Reusar `released_at` (zerando na hora de bloquear) perderia o registro de
 * quem abriu e faria o desbloqueio disparar o e-mail de liberação de novo,
 * para todo mundo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('redemptions_blocked_at')->nullable()->after('released_by');
            $table->foreignId('redemptions_blocked_by')->nullable()->after('redemptions_blocked_at')
                ->constrained('users')->nullOnDelete();
            $table->string('redemptions_block_reason', 300)->nullable()->after('redemptions_blocked_by');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('redemptions_blocked_by');
            $table->dropColumn(['redemptions_blocked_at', 'redemptions_block_reason']);
        });
    }
};
