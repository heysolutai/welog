<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Galeria de fotos do produto: até 10, em ordem, a primeira é a capa.
 *
 * Antes cabia uma foto só, em `products.image_path`. Ela passa a ser a foto 1
 * da galeria, e a coluna fica vazia: uma origem só para a foto enviada. A
 * imagem espelhada da Omie (`image_url`) continua à parte, como reserva de
 * quem não recebeu foto nenhuma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        $agora = now();

        DB::table('products')->whereNotNull('image_path')->orderBy('id')->each(function ($p) use ($agora) {
            DB::table('product_photos')->insert([
                'product_id' => $p->id,
                'path' => $p->image_path,
                'position' => 0,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        });

        DB::table('products')->whereNotNull('image_path')->update(['image_path' => null]);
    }

    public function down(): void
    {
        // A capa volta a ser a foto única; as demais se perdem no caminho.
        DB::table('product_photos')->where('position', 0)->orderBy('id')->each(function ($f) {
            DB::table('products')->where('id', $f->product_id)->update(['image_path' => $f->path]);
        });

        Schema::dropIfExists('product_photos');
    }
};
