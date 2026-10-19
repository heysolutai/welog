<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos de e-mail parametrizáveis pelo Admin WeGift (Módulo 11).
 * Assunto e corpo (Markdown com placeholders {{ variavel }}) editáveis pela UI;
 * a moldura com a identidade da marca permanece no layout do e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // project_released | redemption_confirmed | shipment_update
            $table->string('name');
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
