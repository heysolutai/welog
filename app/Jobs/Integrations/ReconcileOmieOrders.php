<?php

namespace App\Jobs\Integrations;

use App\Contracts\Integrations\OmieGateway;
use App\Jobs\Concerns\IntegrationJob;
use App\Services\FluxoA\CreateProjectFromOmieOrder;
use App\Services\FluxoA\OmieProjectMarker;
use App\Services\FluxoA\SyncOmieOrdersIntoProjects;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reconciliação periódica com a Omie — rede de segurança para webhooks
 * perdidos: varre pedidos recentes sem Projeto e reavalia estoque dos
 * projetos pendentes.
 *
 * A varredura em si mora em `SyncOmieOrdersIntoProjects` — a mesma que o
 * `welog:omie:sync-projects` usa para carga inicial com janela maior.
 */
class ReconcileOmieOrders implements ShouldBeUnique, ShouldQueue
{
    use IntegrationJob, Queueable;

    /**
     * Roda de cinco em cinco minutos e pode demorar (uma chamada à Omie por
     * pedido novo). Sem unicidade, uma execução lenta empilharia com a próxima
     * e dobraria o consumo da API.
     *
     * A janela acompanha a frequência: com o valor antigo (uma hora) o lock da
     * primeira execução recusaria as onze seguintes, e a varredura voltaria na
     * prática a ser horária — o contrário do que a mudança veio fazer.
     */
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(
        OmieGateway $omie,
        CreateProjectFromOmieOrder $createProject,
        OmieProjectMarker $marker,
    ): void {
        // Pedidos dos últimos 2 dias que ainda não viraram Projeto.
        //
        // A reavaliação de ESTOQUE saiu daqui para `ReconcileOmieStock`, que
        // segue horário: ela varre o saldo de todos os Projetos abertos e, a
        // cada cinco minutos, repetiria a mesma consulta pesada à Omie — que
        // responde a repetição em janela curta com bloqueio por "consumo
        // redundante". Pedido novo precisa de pressa; saldo, não.
        (new SyncOmieOrdersIntoProjects($omie, $createProject, $marker))
            ->handle(now()->subDays(2));
    }
}
