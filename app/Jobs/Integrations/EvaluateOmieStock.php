<?php

namespace App\Jobs\Integrations;

use App\Integrations\Omie\OmieAccounts;
use App\Jobs\Concerns\IntegrationJob;
use App\Services\FluxoA\HandleOmieStockSignal;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reavaliação de estoque, COALESCIDA.
 *
 * `Estoque.Alterado` dispara a cada movimentação da conta inteira na Omie —
 * numa operação real são centenas ou milhares por dia. Processar um a um
 * significava uma consulta de posição por webhook: a fila enchia e a Omie
 * passava a recusar por "consumo redundante".
 *
 * Aqui a rajada inteira vira UMA reavaliação: `ShouldBeUnique` descarta o
 * despacho enquanto já houver um pendente, e o atraso de alguns segundos dá
 * tempo de a rajada terminar antes de a janela fechar.
 *
 * Não recebe o código do produto de propósito: o `HandleOmieStockSignal` já
 * consulta a união dos códigos de todos os projetos pendentes numa chamada só,
 * então avaliar tudo custa o mesmo que avaliar um — e não perde o produto de
 * um evento que foi descartado por coalescência.
 */
class EvaluateOmieStock implements ShouldBeUnique, ShouldQueue
{
    use IntegrationJob, Queueable;

    /** Janela de coalescência: novos sinais dentro dela não geram outro job. */
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(HandleOmieStockSignal $stockSignal, OmieAccounts $accounts): void
    {
        // Uma passada por CNPJ: cada conta consulta o saldo dos SEUS Projetos
        // com a própria chave. Com um CNPJ só, é uma iteração e nada muda.
        foreach ($accounts->names() as $account) {
            $stockSignal->handle([], $account);
        }
    }
}
