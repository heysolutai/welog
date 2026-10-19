<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O saldo dos Projetos que já existiam quando a coluna nasceu.
 *
 * `stock_qty` entrou com default 0 e `stock_loaded_at` nulo. Para quem chega
 * depois não há problema: o sinal de estoque da Omie carrega o número quando o
 * kit fica pronto. Mas todo Projeto que JÁ estava em estoque — inclusive os
 * liberados, com o portal aberto — ficou com saldo zero e nenhum sinal novo
 * para vir: a varredura automática foi desligada a pedido do cliente.
 *
 * O efeito seria o pior tipo de falha: a tela dizendo que o kit está
 * disponível e todo resgate sendo recusado por falta de saldo.
 *
 * O número carregado é o contratado menos o que já saiu — um Projeto de 100
 * com 60 resgates volta com 40, não com 100. Resgate cancelado não conta:
 * devolveu o kit à prateleira.
 */
return new class extends Migration
{
    public function up(): void
    {
        $linhas = DB::table('project_products as pp')
            ->join('projects as p', 'p.id', '=', 'pp.project_id')
            ->whereNull('pp.stock_loaded_at')
            // Só quem já chegou ao estoque: Projeto em Design não tem kit para
            // contar, e carregar saldo lá abriria um resgate que não existe.
            ->whereNotNull('p.stock_available_at')
            ->get(['pp.project_id', 'pp.product_id', 'pp.quantity']);

        foreach ($linhas as $linha) {
            $resgatado = (float) DB::table('redemptions')
                ->where('project_id', $linha->project_id)
                ->where('product_id', $linha->product_id)
                ->where('status', '!=', 'canceled')
                ->sum(DB::raw('COALESCE(quantity, 1)'));

            DB::table('project_products')
                ->where('project_id', $linha->project_id)
                ->where('product_id', $linha->product_id)
                ->update([
                    'stock_qty' => max(0, (float) $linha->quantity - $resgatado),
                    'stock_loaded_at' => now(),
                ]);
        }
    }

    /**
     * Sem volta: desfazer significaria zerar o saldo de Projetos em operação,
     * que é exatamente o estado que esta migração existe para corrigir.
     */
    public function down(): void {}
};
