<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece o cadastro de empresa cliente: nome fantasia, contato e endereço.
 *
 * Tudo nullable de propósito — as empresas criadas automaticamente a partir do
 * webhook de pedido da Omie nascem só com razão social e CNPJ, e vão sendo
 * completadas (pelo `getCustomer` da Omie ou à mão pelo Admin WeGift).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('trade_name')->nullable()->after('name');

            // Contato comercial da empresa — não confundir com o Admin Cliente,
            // que é usuário da plataforma e vive em `users`.
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();

            $table->string('zip', 8)->nullable();
            $table->string('street')->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();

            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'trade_name', 'contact_name', 'contact_email', 'contact_phone',
                'zip', 'street', 'number', 'complement', 'district', 'city', 'state',
                'notes',
            ]);
        });
    }
};
