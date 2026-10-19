<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duas mudanças nas Customizações do Cliente.
 *
 * 1. Logo do Cliente: a marca da empresa, no topo das telas do Admin Cliente.
 *    Mora ao lado da logo de cada Projeto, não no lugar dela — a campanha
 *    continua com a marca própria ("85 anos").
 *
 * 2. "Definir campos para resgate" virou uma chave liga/desliga. Desligada,
 *    o Cliente coleta todos os campos. Quem JÁ tinha desmarcado alguma coluna
 *    (a Sotreq não coleta CPF) nasce com a chave ligada: sem isto, o ajuste
 *    sumiria no deploy e o portal voltaria a pedir CPF.
 */
return new class extends Migration
{
    private const OPCIONAIS = ['funcao', 'cpf', 'telefone'];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('notes');
        });

        DB::table('companies')->whereNotNull('customizations')->orderBy('id')->each(function ($empresa) {
            $customizacoes = json_decode($empresa->customizations, true) ?: [];
            $colunas = $customizacoes['import_columns'] ?? null;

            if (! is_array($colunas) || array_key_exists('import_fields', $customizacoes)) {
                return;
            }

            $customizacoes['import_fields'] = array_diff(self::OPCIONAIS, $colunas) !== [];

            DB::table('companies')->where('id', $empresa->id)->update([
                'customizations' => json_encode($customizacoes),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
