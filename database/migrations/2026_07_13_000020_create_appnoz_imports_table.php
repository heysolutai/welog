<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fluxo B: lote de importação de resgates appnoz e suas linhas.
 * Cada linha é também o registro do pedido dela decorrente (Omie/Trello/
 * NF/Flash), cobrindo o histórico exigido pelo módulo 3 e a rastreabilidade
 * do módulo 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appnoz_imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('file_hash', 64)->unique(); // evita reimportar o mesmo arquivo
            $table->string('source'); // upload | directory
            $table->foreignId('imported_by')->nullable()->constrained('users');
            $table->string('status')->default('processing'); // processing|completed|completed_with_blocks|failed
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_created')->default(0);
            $table->unsignedInteger('rows_blocked')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('appnoz_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appnoz_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');

            // Dados vindos da planilha appnoz
            $table->string('cpf', 11)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('product_code')->nullable();
            $table->string('product_name')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('zip', 8)->nullable();
            $table->string('street')->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();

            // Resultado do processamento
            // pending|blocked_structural|blocked_product|order_created|invoiced|dispatched|delivered
            $table->string('status')->default('pending');
            $table->text('block_reason')->nullable();
            $table->string('benefit_code')->nullable();
            $table->string('omie_customer_code')->nullable();
            $table->boolean('customer_auto_created')->default(false);
            $table->string('omie_order_id')->nullable();
            $table->string('omie_order_number')->nullable();
            $table->string('trello_card_id')->nullable();
            $table->string('trello_stage')->nullable(); // espelho passivo do card (montagem/envio)

            // Fiscal + expedição Flash
            $table->string('nf_key', 44)->nullable();
            $table->timestamp('nf_issued_at')->nullable();
            $table->timestamp('nf_xml_sent_at')->nullable();
            $table->string('tracking_code')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['appnoz_import_id', 'status']);
            $table->index('status');
            $table->index('nf_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appnoz_import_rows');
        Schema::dropIfExists('appnoz_imports');
    }
};
