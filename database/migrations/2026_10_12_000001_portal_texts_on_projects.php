<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Os textos do portal passam do Cliente para o Projeto.
 *
 * Cada campanha tem a sua voz ("Neste aniversário, o presente é para
 * você!"), e o mesmo Cliente roda campanhas diferentes. Além da saudação, da
 * chamada da escolha e do título das entregas, o Projeto guarda o nome e a
 * descrição de cada item como o colaborador os lê, pelo código do item
 * principal:
 *
 *   { saudacao, escolha, entregas, itens: { "prd11361": { nome, descricao } } }
 *
 * O que o Cliente já tinha escrito vai para todos os Projetos dele, e a
 * customização do Cliente deixa de existir.
 */
return new class extends Migration
{
    private const CHAVES = ['saudacao', 'escolha', 'entregas'];

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('portal_texts')->nullable();
        });

        DB::table('companies')->whereNotNull('customizations')->orderBy('id')->each(function ($empresa) {
            $custom = json_decode((string) $empresa->customizations, true) ?: [];
            $ligada = ($custom['portal_texts'] ?? false) === true;
            $textos = array_filter(
                array_intersect_key((array) ($custom['portal_textos'] ?? []), array_flip(self::CHAVES)),
                fn ($t) => is_string($t) && trim($t) !== '',
            );

            if ($ligada && $textos !== []) {
                DB::table('projects')
                    ->where('company_id', $empresa->id)
                    ->whereNull('portal_texts')
                    ->update(['portal_texts' => json_encode($textos)]);
            }

            unset($custom['portal_texts'], $custom['portal_textos']);

            DB::table('companies')->where('id', $empresa->id)->update(['customizations' => json_encode($custom)]);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('portal_texts');
        });
    }
};
