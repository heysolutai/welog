<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O pipeline passou de sete estágios (espelho literal do board Trello) para os
 * seis que a operação usa: Design, Produção, Montagem, Envio, Em Estoque e
 * Concluído.
 *
 * "Em Estoque" é o estágio novo e o mais importante: é a partir dele que o
 * resgate pode ser liberado. Nenhum status antigo corresponde a ele, então
 * quem o define aqui é o sinal de estoque da Omie já gravado em
 * `stock_available_at` — o dado que a regra antiga usava para o mesmo fim.
 */
return new class extends Migration
{
    /** Status antigo → novo, pelo significado da etapa. */
    private const REMAP = [
        'created' => Project::STATUS_DESIGN,      // nascia no webhook, antes de qualquer trabalho
        'design' => Project::STATUS_DESIGN,
        'production' => Project::STATUS_PRODUCTION,
        'picking' => Project::STATUS_ASSEMBLY,    // separação de itens = montagem do kit
        'packing' => Project::STATUS_SHIPPING,    // embalado é o que está de saída
        'invoiced' => Project::STATUS_SHIPPING,   // NF emitida acompanha o envio
        'done' => Project::STATUS_DONE,
    ];

    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->string('pipeline_status')
            ->default(Project::STATUS_DESIGN)
            ->change());

        foreach (self::REMAP as $old => $new) {
            DB::table('projects')->where('pipeline_status', $old)->update(['pipeline_status' => $new]);
            DB::table('trello_list_mappings')->where('pipeline_status', $old)->update(['pipeline_status' => $new]);
        }

        // Vence o mapa acima: quem já tem estoque sinalizado está em estoque,
        // qualquer que fosse a etapa antiga. Concluído fica onde está — já
        // passou por aqui.
        DB::table('projects')
            ->whereNotNull('stock_available_at')
            ->where('pipeline_status', '!=', Project::STATUS_DONE)
            ->update(['pipeline_status' => Project::STATUS_IN_STOCK]);

        // O Trello vai até Envio. Uma lista apontando para Em Estoque ou
        // Concluído colocaria duas fontes disputando o mesmo campo.
        DB::table('trello_list_mappings')
            ->whereNotIn('pipeline_status', Project::TRELLO_STATUSES)
            ->delete();
    }

    /**
     * Volta ao vocabulário antigo no que dá. `assembly` era `picking` e
     * `shipping` era `packing`; "Em Estoque" não existia lá, e o mais próximo
     * é o estágio em que a liberação ficava pendurada.
     */
    public function down(): void
    {
        foreach ([
            Project::STATUS_ASSEMBLY => 'picking',
            Project::STATUS_SHIPPING => 'packing',
            Project::STATUS_IN_STOCK => 'invoiced',
        ] as $new => $old) {
            DB::table('projects')->where('pipeline_status', $new)->update(['pipeline_status' => $old]);
            DB::table('trello_list_mappings')->where('pipeline_status', $new)->update(['pipeline_status' => $old]);
        }

        Schema::table('projects', fn (Blueprint $table) => $table->string('pipeline_status')
            ->default('created')
            ->change());
    }
};
