<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

/**
 * Nome do Projeto passa a sair do "Nº do pedido do cliente" da Omie — o campo
 * onde a operação escreve como a campanha se chama.
 *
 * Os Projetos que já existem nasceram com "<pedido> - <razão social>", que não
 * informava nada: a empresa já aparece embaixo do título, e dois pedidos do
 * mesmo cliente ficavam com nomes que só diferiam no número. Sem este acerto,
 * a regra nova valeria só para pedido novo e a lista ficaria com dois padrões
 * convivendo.
 *
 * Só toca em quem tem o campo preenchido e NÃO foi renomeado à mão
 * (`details_edited_at`): nome editado pelo operador é decisão dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Project::query()
            ->whereNotNull('client_order_number')
            ->whereNotNull('omie_order_number')
            ->whereNull('details_edited_at')
            ->each(function (Project $project) {
                $project->update([
                    'name' => trim($project->omie_order_number.' - '.$project->client_order_number),
                ]);
            });
    }

    public function down(): void
    {
        // O nome anterior era derivado da razão social e não foi guardado;
        // reconstruí-lo aqui inventaria dado. O próximo `VendaProduto.Alterada`
        // (ou o backfill acima) reescreve pelo campo da Omie de qualquer forma.
    }
};
