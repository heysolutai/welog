<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logo do Projeto — a marca da campanha, ao lado do título.
 *
 * Fica no PROJETO e não na empresa: o mesmo Cliente roda campanhas com marcas
 * próprias ("85 anos", programa de parceria), e uma logo por empresa obrigaria
 * todas elas a dividir a mesma imagem.
 *
 * Guarda o caminho no disco, não a URL: a URL depende do APP_URL e mudaria
 * sozinha entre dev, staging e produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('template');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
