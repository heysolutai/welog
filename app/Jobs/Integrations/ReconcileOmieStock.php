<?php

namespace App\Jobs\Integrations;

use App\Jobs\Concerns\IntegrationJob;
use App\Services\FluxoA\HandleOmieStockSignal;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reavaliação horária do estoque de todos os Projetos abertos.
 *
 * Saiu de dentro do `ReconcileOmieOrders` quando a varredura de pedidos passou
 * a rodar de cinco em cinco minutos: são ritmos diferentes por natureza.
 * Pedido novo parado é a operação esperando o card aparecer; saldo é um número
 * que muda devagar e cuja releitura custa uma consulta em lote de todos os
 * kits — repetida em janela curta, é exatamente o que a Omie bloqueia como
 * "consumo redundante".
 *
 * O caminho rápido do estoque continua sendo o webhook `Estoque.Alterado`, que
 * reavalia só os Projetos do produto que mudou. Isto aqui é a rede.
 */
class ReconcileOmieStock implements ShouldBeUnique, ShouldQueue
{
    use IntegrationJob, Queueable;

    /** Uma execução por hora basta; a próxima não empilha sobre a anterior. */
    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(HandleOmieStockSignal $stockSignal): void
    {
        $stockSignal->handle();
    }
}
