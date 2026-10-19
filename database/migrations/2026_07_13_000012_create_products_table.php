<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo espelhado da Omie (fonte da verdade). Kits têm estrutura BOM
 * em kit_items. stock_qty é o último saldo conhecido via integração.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('omie_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_kit')->default(false);
            $table->boolean('active')->default(true);
            $table->decimal('stock_qty', 12, 2)->default(0);
            $table->timestamp('stock_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kit_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 12, 2)->default(1);

            $table->unique(['kit_product_id', 'component_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_items');
        Schema::dropIfExists('products');
    }
};
