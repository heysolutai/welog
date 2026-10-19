<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O catálogo como o PARCEIRO o enxerga (Reconhece, hoje).
 *
 * A vitrine onde o colaborador da Suvinil escolhe o brinde não é nossa: é da
 * Reconhece. Ela cadastra o item lá, avisa por webhook, e depois manda de volta
 * o resgate com o `ID Produto` dela. Sem guardar esse cadastro, o resgate chega
 * com um código que não existe do nosso lado e a linha trava em "produto não
 * cadastrado" — que é o único bloqueio do fluxo, e o mais caro de resolver a
 * frio, no meio de uma campanha.
 *
 * Tabela separada de `products` de propósito. `products` espelha a Omie, que é
 * a fonte da verdade do que existe fisicamente e de quanto tem em estoque. Isto
 * aqui é a fala do parceiro: o nome que ELE deu, a categoria que ELE usa, a
 * variação que ELE modelou. Misturar as duas faria o próximo sync da Omie
 * sobrescrever o que veio do parceiro, ou o contrário.
 *
 * `product_id` é a ponte entre os dois mundos, e é nulo até alguém amarrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_skus', function (Blueprint $table) {
            $table->id();

            // Hoje só existe a Reconhece. A coluna evita que o segundo parceiro
            // exija uma tabela nova e uma segunda tela para dizer a mesma coisa.
            $table->string('source')->default('reconhece');
            $table->string('sku');
            $table->boolean('enabled')->default(true);

            /*
             * Variação, na modelagem DELES: um SKU é um "modelo" do produto.
             * Todos nulos quando o item é avulso — e o próprio contrato diz
             * como reconhecer isso: `parent_code` igual ao `sku`.
             */
            $table->string('sku_name')->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('voltage')->nullable();

            // O produto-pai que agrupa as variações.
            $table->string('parent_code');
            $table->string('name');
            $table->text('description')->nullable();

            // Categoria em dois níveis: `product_type` é o pai, `department` o
            // filho. Chegam prontas do parceiro; não são cadastro nosso.
            $table->string('product_type')->nullable();
            $table->string('department')->nullable();

            $table->jsonb('images')->nullable();

            /*
             * A ponte. Nulo = chegou e ninguém amarrou ainda, que é o estado
             * que a tela precisa mostrar em destaque: resgate desse SKU vai
             * travar enquanto ele estiver assim.
             */
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * O corpo cru, como chegou.
             *
             * O parceiro vai mandar campo que hoje não lemos, e vai mudar o
             * formato sem avisar. Guardando o original, a descoberta é olhar um
             * registro — e não pedir para eles reenviarem o que já mandaram.
             */
            $table->jsonb('payload')->nullable();

            $table->timestamps();

            $table->unique(['source', 'sku']);
            $table->index(['source', 'parent_code']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_skus');
    }
};
