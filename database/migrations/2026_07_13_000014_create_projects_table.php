<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projeto (Fluxo A): representação do ciclo completo de um pedido de kits
 * corporativos. Nasce do webhook da Omie (ou cadastro manual — fallback),
 * espelha o pipeline do Trello e controla estoque/liberação de resgate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->string('omie_order_id')->nullable()->unique(); // null = onboarding manual
            $table->string('omie_order_number')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->date('delivery_deadline')->nullable();
            // Espelho passivo do pipeline Trello: created|design|production|picking|packing|invoiced|done
            $table->string('pipeline_status')->default('created');
            $table->string('trello_card_id')->nullable();
            $table->string('trello_card_url')->nullable();
            $table->timestamp('stock_available_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('pipeline_status');
        });

        // Kits/produtos que compõem o pedido do Projeto
        Schema::create('project_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('quantity')->default(1);

            $table->unique(['project_id', 'product_id']);
        });

        // Clientes finais autorizados a resgatar em cada Projeto (e qual kit)
        Schema::create('project_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_entitlements');
        Schema::dropIfExists('project_products');
        Schema::dropIfExists('projects');
    }
};
