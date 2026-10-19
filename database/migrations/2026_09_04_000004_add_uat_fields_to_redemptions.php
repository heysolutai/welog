<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inclusões do UAT no resgate:
 *
 *  - `cpf` (11 dígitos, sem máscara) e `notes`: o CPF passa a ser obrigatório
 *    NAS NOVAS confirmações (a coluna é nullable porque os resgates antigos
 *    nasceram sem ele); as observações são opcionais.
 *  - `quantity` + `requested_by`: a solicitação de admin (Admin Cliente ou
 *    Admin de Projeto) cria UMA linha com N unidades, e `requested_by`
 *    preenchido é o que a distingue do resgate individual do portal.
 *  - `stock_pushed_at`: carimbo de idempotência da baixa de estoque na Omie
 *    (job PushRedemptionStockToOmie). Nulo = baixa ainda não enviada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->string('cpf', 11)->nullable()->after('recipient_name');
            $table->string('notes', 500)->nullable()->after('state');
            $table->unsignedInteger('quantity')->default(1)->after('product_id');
            // nullOnDelete: excluir a conta do admin que solicitou não pode
            // derrubar o histórico do resgate (a linha continua contando).
            $table->foreignId('requested_by')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('stock_pushed_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['cpf', 'notes', 'quantity', 'stock_pushed_at']);
        });
    }
};
