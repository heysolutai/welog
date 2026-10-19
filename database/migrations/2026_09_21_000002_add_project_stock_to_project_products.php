<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo do item DENTRO do Projeto, controlado pela WeLog.
 *
 * Até aqui existiam dois números e faltava o do meio:
 *
 *   quantity ......... quanto o pedido pede (Qtde Requerida)
 *   products.stock_qty  quanto existe na Omie, do produto inteiro (Estoque Omie)
 *
 * O saldo da Omie é do PRODUTO, somando todos os projetos que usam aquele
 * item. Com ele não dá para responder a pergunta que a operação faz o tempo
 * todo: quantas unidades ainda restam DESTE projeto. Dois projetos com o mesmo
 * kit liam o mesmo número e nenhum dos dois era o seu.
 *
 * `stock_qty` aqui é esse saldo: nasce quando o kit entra em estoque, cai a
 * cada resgate e é ajustável na plataforma. A Omie deixa de receber a baixa —
 * o controle é da WeLog, e o número de lá passa a ser só referência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_products', function (Blueprint $table) {
            // Mesma precisão de `products.stock_qty`: o kit é contado em
            // unidades, mas o componente pode ser fracionado.
            $table->decimal('stock_qty', 12, 2)->default(0)->after('quantity');
            // Quando o saldo foi carregado a partir do sinal de estoque. Nulo
            // distingue "ainda não chegou" de "chegou e já acabou" — sem isso,
            // zero significaria as duas coisas.
            $table->timestamp('stock_loaded_at')->nullable()->after('stock_qty');
        });
    }

    public function down(): void
    {
        Schema::table('project_products', function (Blueprint $table) {
            $table->dropColumn(['stock_qty', 'stock_loaded_at']);
        });
    }
};
