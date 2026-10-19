<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resgate (Fluxo A): confirmação do cliente final com endereço de entrega.
 * A baixa individual NÃO retorna à Omie (responsabilidade dela termina na
 * sinalização de estoque). Expedição: correios (API) | mrb | ddm (manuais).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('product_id')->constrained();
            // confirmed → shipped → delivered (canceled a qualquer momento pelo operador)
            $table->string('status')->default('confirmed');

            // Endereço de entrega informado no resgate
            $table->string('recipient_name');
            $table->string('zip', 8);
            $table->string('street');
            $table->string('number', 20);
            $table->string('complement')->nullable();
            $table->string('district');
            $table->string('city');
            $table->string('state', 2);

            $table->string('carrier')->nullable(); // correios | mrb | ddm
            $table->string('tracking_code')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};
