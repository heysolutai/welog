<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identificação do Projeto pelos dados do pedido na Omie (inclusão UAT).
 *
 *  · `client_order_number` — "Nº do pedido do cliente" digitado no Pedido de
 *    Venda (`informacoes_adicionais.numero_pedido_cliente` no ConsultarPedido).
 *    É como o cliente final se refere à compra; a tela oferece esse número
 *    como nome do Projeto.
 *  · `ordered_at` — data em que o pedido foi incluído na Omie
 *    (`infoCadastro.dInc`, formato dd/mm/aaaa). A UI exibe como "Data da
 *    compra", que hoje não existia em lugar nenhum da plataforma.
 *  · `details_edited_at` — carimbo de edição manual de nome/prazo. O webhook
 *    `VendaProduto.Alterada` reescreve o Projeto a partir da Omie; sem o
 *    carimbo, o nome e o prazo ajustados pelo operador seriam desfeitos pela
 *    primeira alteração do pedido — a edição que a UAT pediu duraria minutos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('client_order_number')->nullable()->after('omie_order_number');
            $table->date('ordered_at')->nullable()->after('delivery_deadline');
            $table->timestamp('details_edited_at')->nullable()->after('ordered_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn([
            'client_order_number', 'ordered_at', 'details_edited_at',
        ]));
    }
};
