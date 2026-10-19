<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Em Estoque" passou para ANTES de "Envio" na esteira.
 *
 * Até aqui Envio vinha antes de Estoque e significava "kit a caminho da
 * WeLog". Agora significa "kits saindo para as pessoas" — etapa pós-estoque,
 * com resgate aberto. Um Projeto parado em Envio SEM sinal de estoque estava
 * no sentido antigo: se ficasse, viraria pós-estoque sem kit nenhum e o portal
 * abriria para ele. Volta para Montagem, que é a última etapa antes do estoque.
 *
 * Quem está em Envio COM `stock_available_at` já passou pelo estoque no
 * sentido novo (ou equivalente) e fica onde está.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')
            ->where('pipeline_status', Project::STATUS_SHIPPING)
            ->whereNull('stock_available_at')
            ->update(['pipeline_status' => Project::STATUS_ASSEMBLY]);
    }

    public function down(): void
    {
        // Não há como saber quais Montagem eram Envio; nada a desfazer.
    }
};
