<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parametrização do código de benefício fiscal aplicado aos pedidos do
 * Fluxo B (importação appnoz), chaveada pelo código do produto na Omie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_benefits', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->unique();
            $table->string('benefit_code');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_benefits');
    }
};
