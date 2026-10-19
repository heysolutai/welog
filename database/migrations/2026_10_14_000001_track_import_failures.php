<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As linhas recusadas de uma importação, com os dados delas.
 *
 * O `errors` da importação guarda só as cinquenta primeiras mensagens, sem a
 * linha: dava para ler o que deu errado, não para corrigir. Numa planilha de
 * nove mil, com trezentas recusadas, o operador tinha de caçar cada uma no
 * arquivo original. Aqui elas ficam inteiras, e a tela devolve uma planilha só
 * com elas, no formato do modelo, para corrigir e subir de novo.
 *
 * `ignored` conta quem já estava no Projeto (subir a mesma planilha duas vezes
 * não cadastra ninguém duas vezes) e `failed`, o total de recusadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entitlement_imports', function (Blueprint $table) {
            $table->unsignedInteger('ignored')->default(0)->after('admins');
            $table->unsignedInteger('failed')->default(0)->after('ignored');
        });

        Schema::create('entitlement_import_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_import_id')->constrained()->cascadeOnDelete();
            // O número NA PLANILHA, que é o que o operador procura.
            $table->unsignedInteger('linha');
            $table->text('mensagem');
            // A linha como veio: nome, e-mail, função, CPF, telefone.
            $table->json('dados');
            $table->timestamps();

            $table->index(['entitlement_import_id', 'linha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_import_failures');

        Schema::table('entitlement_imports', function (Blueprint $table) {
            $table->dropColumn(['ignored', 'failed']);
        });
    }
};
