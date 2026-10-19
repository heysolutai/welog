<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O quadro do Trello passa a governar TRÊS movimentações: Design, Produção e
 * Montagem (decisão da operação).
 *
 * "Envio" sai do board: quem move o Projeto para lá é a expedição dos
 * resgates, não card arrastado. Enquanto o de-para antigo continuasse no
 * banco, uma lista "Envio" no quadro seguiria empurrando o Projeto — duas
 * fontes disputando o mesmo campo, que é justamente o que o recorte veio
 * evitar.
 *
 * Só o MAPEAMENTO é apagado. Projeto que já está em Envio fica onde está: a
 * etapa dele é história, não configuração.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('trello_list_mappings')
            ->whereNotIn('pipeline_status', Project::TRELLO_STATUSES)
            ->delete();
    }

    public function down(): void
    {
        // Não há como saber quais listas do quadro eram "Envio"; o
        // `welog:trello:setup` remonta o de-para a partir dos nomes reais.
    }
};
