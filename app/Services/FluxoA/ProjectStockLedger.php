<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Project;
use App\Models\Redemption;
use Illuminate\Support\Facades\DB;

/**
 * O saldo do Projeto, que é o saldo que a operação enxerga.
 *
 * A Omie continua sendo a origem — é ela que diz que o kit ficou pronto —, mas
 * a BAIXA é da WeLog e não volta para lá. Antes, cada resgate empurrava uma
 * saída de estoque para a Omie, e isso trazia dois problemas: o saldo do
 * produto lá é somado entre todos os projetos que usam o mesmo kit (ninguém
 * sabia quanto restava do SEU), e a plataforma dependia de a Omie estar no ar
 * para confirmar um resgate que ela já tinha aceitado.
 *
 * Aqui o número é local e o débito acontece na mesma transação do resgate.
 */
class ProjectStockLedger
{
    /**
     * Debita uma unidade do item no Projeto.
     *
     * Chamado DENTRO da transação do resgate: sem isso, dois resgates
     * simultâneos leem o mesmo saldo e os dois passam — e o projeto entrega
     * mais kits do que tem.
     */
    public function debitar(Project $project, int $productId, float $quantidade = 1): void
    {
        $linha = $this->linhaTravada($project->id, $productId);

        if ($linha === null) {
            // Item que não é do pedido não tem saldo para debitar. Não é erro
            // de estoque, é resgate de algo que o Projeto não contratou.
            throw new DomainException('Este item não faz parte do pedido deste Projeto.');
        }

        $saldo = $linha->stock_loaded_at === null
            ? $this->carregar($project->id, $productId, (float) $linha->quantity)
            : (float) $linha->stock_qty;

        if ($saldo < $quantidade) {
            throw new DomainException(
                'Sem saldo neste Projeto para este item. Restam '
                .self::formatar($saldo).' unidade(s).',
            );
        }

        DB::table('project_products')
            ->where('project_id', $project->id)
            ->where('product_id', $productId)
            ->update(['stock_qty' => DB::raw('stock_qty - '.(float) $quantidade)]);
    }

    /**
     * Devolve ao Projeto o que um resgate cancelado tinha consumido.
     *
     * Sem teto: o saldo devolvido pode passar da quantidade contratada se a
     * operação tiver ajustado o número para menos depois do resgate. Recusar
     * ali faria o cancelamento falhar por um ajuste que não é do cliente.
     */
    public function devolver(Redemption $redemption): void
    {
        DB::table('project_products')
            ->where('project_id', $redemption->project_id)
            ->where('product_id', $redemption->product_id)
            ->update(['stock_qty' => DB::raw('stock_qty + '.(float) ($redemption->quantity ?: 1))]);
    }

    /** A linha do item no Projeto, travada para escrita até o fim da transação. */
    private function linhaTravada(int $projectId, int $productId): ?object
    {
        return DB::table('project_products')
            ->where('project_id', $projectId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first(['quantity', 'stock_qty', 'stock_loaded_at']);
    }

    /**
     * Carrega o saldo do item na hora, para o Projeto que nunca passou pelo
     * sinal de estoque da Omie.
     *
     * O saldo nasce no sinal, mas nem todo Projeto chega por lá: estágio
     * ajustado à mão, consulta à Omie fora do ar no momento certo, ou Projeto
     * que já estava liberado antes de este saldo existir. Em todos esses casos
     * `stock_loaded_at` fica nulo e `stock_qty` vale zero — e zero não é "não
     * tem kit", é "ninguém contou ainda". Recusar o resgate por causa disso
     * seria fechar um Projeto que a operação já liberou.
     *
     * O que já saiu entra no desconto: um Projeto de 100 com 60 resgates
     * anteriores carrega 40, não 100.
     */
    private function carregar(int $projectId, int $productId, float $contratado): float
    {
        $jaResgatado = (float) DB::table('redemptions')
            ->where('project_id', $projectId)
            ->where('product_id', $productId)
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            ->sum(DB::raw('COALESCE(quantity, 1)'));

        $saldo = max(0, $contratado - $jaResgatado);

        DB::table('project_products')
            ->where('project_id', $projectId)
            ->where('product_id', $productId)
            ->update(['stock_qty' => $saldo, 'stock_loaded_at' => now()]);

        return $saldo;
    }

    /** Sem casas decimais quando é inteiro: "restam 3", não "restam 3,00". */
    private static function formatar(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',');
    }
}
