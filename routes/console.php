<?php

use App\Jobs\FluxoA\PollCarrierTracking;
use App\Jobs\FluxoB\PollFlashTracking;
use App\Jobs\FluxoB\SendPendingFlashShipments;
use App\Jobs\FluxoB\SyncOmieInvoices;
use App\Jobs\Integrations\ReconcileOmieOrders;
use App\Jobs\Integrations\ReconcileOmieStock;
use App\Models\AuditLog;
use App\Models\TrackingEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Agendamentos WeLog (executados pelo serviço "scheduler" do docker-compose)
|--------------------------------------------------------------------------
*/

// Fluxo B — varredura diária dos diretórios ativos de relatórios appnoz.
// Uma vez por dia porque o relatório é gerado à mão pelo operador WeGift, num
// ritmo de horas: varrer de dez em dez minutos era custo sem ganho.
Schedule::command('welog:consume-appnoz-directory')
    ->dailyAt(config('integrations.appnoz.scan_time', '07:00'))
    ->timezone(config('integrations.business_timezone', 'America/Sao_Paulo'));

// Fluxo B — vincula NFs emitidas pela Omie aos pedidos importados
Schedule::job(new SyncOmieInvoices)->hourly();

// Fluxo B — resgate com NF emitida vira encomenda na Flash (importação da
// Bridge Link). O envio sai quando a nota chega; isto é a reserva para o que
// falhou ou ficou para trás.
Schedule::job(new SendPendingFlashShipments)->everyTenMinutes();

// Rastreabilidade — Flash (Fluxo B) e Correios (Fluxo A). O rastreio da Flash
// consulta pelo mesmo número com que a encomenda foi importada (WEBOX + NF).
Schedule::job(new PollFlashTracking)->everyTwoHours();
Schedule::job(new PollCarrierTracking)->everyTwoHours();

/*
 * Pedidos novos: DESLIGADO por padrão (OMIE_RECONCILE_ORDERS).
 *
 * A varredura nasceu como rede por trás do webhook: lista os pedidos dos
 * últimos dois dias e cria Projeto para o que ainda não tem. O problema é que
 * ela não distingue "webhook se perdeu" de "este pedido não interessa" — a
 * conta da Omie atende a operação inteira, e tudo que passa pelo filtro de
 * projeto entra na plataforma, sem ninguém ter pedido para acompanhar.
 *
 * Foi ela também que levou a conta à SUSPENSÃO: uma chamada por projeto
 * configurado, em rajada, a cada 15 minutos. O espaçamento por método
 * resolveu a rajada, mas não muda o fato de que o caminho certo do pedido é
 * o webhook — imediato e só com o que a Omie decidiu avisar.
 *
 * Quando o webhook falhar (já ficou 7 dias fora), o resgate é manual e
 * explícito, que é como deve ser:
 *
 *   php artisan welog:omie:sync-projects --dias=2 --dry-run
 *   php artisan welog:omie:sync-projects --dias=2
 */
if (config('integrations.omie.reconcile_orders')) {
    Schedule::job(new ReconcileOmieOrders)->everyFifteenMinutes()->withoutOverlapping();
}

// Estoque: ritmo diferente, de propósito — ver ReconcileOmieStock.
Schedule::job(new ReconcileOmieStock)->hourly();

// Retenção: webhook_events e tracking_events crescem a cada evento recebido.
// Janelas em config/integrations.php → retention. A trilha de auditoria segue
// a sua (config/welog.php → audit.retention_days, 2 anos por padrão).
Schedule::command('model:prune', [
    '--model' => [WebhookEvent::class, TrackingEvent::class, AuditLog::class],
])->dailyAt('03:30')->timezone(config('integrations.business_timezone'));
