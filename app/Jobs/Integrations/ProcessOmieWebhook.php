<?php

namespace App\Jobs\Integrations;

use App\Jobs\Concerns\IntegrationJob;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\WebhookEvent;
use App\Services\FluxoA\CreateProjectFromOmieOrder;
use App\Services\FluxoA\HandleOmieStockSignal;
use App\Services\Projects\ProjectPurger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa um webhook da Omie previamente persistido em webhook_events.
 *
 * Roteia por tópico:
 *  - VendaProduto.*  → criação/atualização de Projeto (Fluxo A)
 *  - Estoque.*       → disponibilidade de estoque que libera o resgate (Fluxo A)
 *
 * Fila `webhooks`, separada de `integrations`, com worker próprio. Webhook é o
 * caminho pelo qual a operação vê o Projeto aparecer, e antes ele dividia a
 * fila com as varreduras longas (reconciliação, rastreio dos Correios, sync de
 * estoque): um pedido fechado esperava minutos atrás de um job que nada tinha
 * a ver com ele. O que é interativo não pode ficar atrás do que é de fundo.
 */
class ProcessOmieWebhook implements ShouldQueue
{
    use IntegrationJob, Queueable;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        // O job pode rodar ANTES do INSERT do webhook estar visível: o dispatch
        // (Redis) costuma ser mais rápido que o commit ficar visível no banco
        // gerenciado. Em vez de estourar com findOrFail (crash-loop no worker),
        // reenfileira; nas próximas tentativas o evento já está lá.
        if ($event === null) {
            // Sem teto, um id que nunca aparece (evento podado, banco
            // restaurado) fica ciclando na fila para sempre. Depois de algumas
            // tentativas o atraso já cobriu qualquer latência de replicação:
            // se ainda não existe, não vai existir.
            if ($this->attempts() >= 3) {
                Log::warning('Webhook Omie descartado: evento inexistente.', [
                    'webhook_event_id' => $this->webhookEventId,
                    'attempts' => $this->attempts(),
                ]);

                return;
            }

            $this->release(15);

            return;
        }

        // `ignored` e `processed` já foram decididos: reprocessar não muda nada.
        if (in_array($event->status, [WebhookEvent::STATUS_PROCESSED, WebhookEvent::STATUS_IGNORED], true)) {
            return;
        }

        try {
            match (true) {
                str_starts_with($event->topic, 'VendaProduto.') => $this->handleSalesOrder($event),
                str_starts_with($event->topic, 'Estoque.') => $this->handleStock($event),
                default => Log::info('Webhook Omie ignorado (tópico não mapeado)', [
                    'webhook_event_id' => $event->id,
                    'topic' => $event->topic,
                ]),
            };

            // `markIgnored` (pedido recusado pelo filtro) já decidiu o
            // desfecho com o motivo junto; sobrescrever aqui apagaria a única
            // explicação que a tela tem.
            if ($event->status !== WebhookEvent::STATUS_IGNORED) {
                $event->markProcessed();
            }
        } catch (Throwable $e) {
            $event->markFailed($e->getMessage());

            throw $e; // deixa a fila reexecutar (tries=3)
        }
    }

    /**
     * Tentativas esgotadas.
     *
     * Sem isto o evento fica "Na fila" para sempre quando a falha acontece
     * ANTES do `try` — o `find()` lá em cima, por exemplo, quando o banco está
     * inalcançável. Quem abre o Log de Webhooks vê um evento parado, sem erro
     * nenhum, e não tem como saber que o job já desistiu dele.
     */
    public function failed(?Throwable $e): void
    {
        $message = $e?->getMessage() ?? 'Falha desconhecida no processamento.';

        Log::channel('integrations')->error('Job de integração falhou definitivamente.', [
            'job' => static::class,
            'exception' => $message,
        ]);

        try {
            WebhookEvent::find($this->webhookEventId)?->markFailed($message);
        } catch (Throwable) {
            // Quando o que caiu É o banco, não há onde gravar. O log acima já
            // registrou; engolir aqui evita trocar um erro por outro.
        }
    }

    /** O único tópico que dá origem a Projeto. */
    public const TOPIC_ORDER_CREATED = 'VendaProduto.Incluida';

    /**
     * Tópicos que ENCERRAM o pedido na Omie e, com ele, o Projeto.
     *
     * `Excluida` é o pedido apagado do ERP; `Cancelada`, o pedido que continua
     * registrado lá, mas morto. Para a esteira dá no mesmo: não há mais kits a
     * produzir, e manter o Projeto seria exibir uma campanha que não existe.
     * Os dois tópicos são os documentados pela Omie para Pedido de Venda, ao
     * lado de `Incluida`/`Alterada`/`EtapaAlterada`/`Faturada`.
     */
    public const TOPIC_ORDER_REMOVED = ['VendaProduto.Excluida', 'VendaProduto.Cancelada'];

    /**
     * Pedido de Venda na Omie → Projeto (Fluxo A).
     *
     * Só a INCLUSÃO cria. `VendaProduto.Alterada` e os demais tópicos de
     * pedido atualizam o Projeto que já existe e não fazem nada quando ele
     * não existe: alteração é notícia sobre um pedido, não o nascimento dele.
     * Exclusão/cancelamento apagam o Projeto correspondente (ver
     * `handleOrderRemoval`).
     */
    private function handleSalesOrder(WebhookEvent $event): void
    {
        $orderId = $event->payload['event']['idPedido']
            ?? $event->payload['event']['codigo_pedido']
            ?? null;

        if ($orderId === null) {
            Log::warning('Webhook Omie de pedido sem idPedido', ['webhook_event_id' => $event->id]);

            return;
        }

        // Exclusão não passa pelo caminho de criação/atualização: o pedido já
        // não existe na Omie, e o `ConsultarPedido` só devolveria "não
        // encontrado" — era exatamente o que acontecia antes deste tratamento.
        if (in_array($event->topic, self::TOPIC_ORDER_REMOVED, true)) {
            $this->handleOrderRemoval($event, (string) $orderId);

            return;
        }

        $create = $event->topic === self::TOPIC_ORDER_CREATED;

        // A consulta do pedido sai da MESMA conta (CNPJ) que enviou o evento.
        $service = app(CreateProjectFromOmieOrder::class);
        $project = $service->handle((string) $orderId, $create, $event->omie_account);

        Log::info('Webhook Omie: pedido de venda processado', [
            'webhook_event_id' => $event->id,
            'topic' => $event->topic,
            'criou' => $create,
            'conta_omie' => $event->omie_account,
            'project_id' => $project?->id,
        ]);

        // Recebido em segundos e sem Projeto nenhum: o evento tem de DIZER
        // isso. Marcado só como "Processado", quem abre o Log de Webhooks
        // procura um Projeto que nunca existiu e conclui que o sistema está
        // lento — quando ele, de propósito, recusou o pedido.
        if ($project === null && $service->motivoDaRecusa() !== null) {
            $event->markIgnored($service->motivoDaRecusa());
        }
    }

    /**
     * Pedido excluído/cancelado na Omie → Projeto apagado da plataforma.
     *
     * A exclusão usa o mesmo `ProjectPurger::apagarProjeto()` do botão do
     * Admin: rastreio → resgates → autorizações → Projeto, em transação.
     * Empresa e usuários ficam, como em toda exclusão de UM Projeto.
     *
     * Exceção conservadora: Projeto com resgate JÁ EXPEDIDO (shipped ou
     * delivered) não é apagado. Nesse ponto existe kit em trânsito ou entregue
     * no mundo físico — apagar o registro tiraria da operação o rastreio e o
     * endereço de algo que ainda pode precisar de suporte/devolução. Fica o
     * warning no log e o Projeto na tela, para a WeGift decidir à mão.
     */
    private function handleOrderRemoval(WebhookEvent $event, string $orderId): void
    {
        $project = Project::where('omie_order_id', $orderId)->first();

        if ($project === null) {
            // Não é falha: a maioria dos pedidos da conta nunca virou Projeto
            // (filtro OMIE_PROJECT_CODES) — a exclusão deles não deixa rastro.
            Log::info('Webhook Omie: pedido excluído sem Projeto correspondente — nada a apagar.', [
                'webhook_event_id' => $event->id,
                'topic' => $event->topic,
                'omie_order_id' => $orderId,
                'conta_omie' => $event->omie_account,
            ]);

            return;
        }

        $expedidos = $project->redemptions()
            ->whereIn('status', [Redemption::STATUS_SHIPPED, Redemption::STATUS_DELIVERED])
            ->count();

        if ($expedidos > 0) {
            Log::warning('Webhook Omie: pedido excluído, mas o Projeto tem resgate expedido — mantido.', [
                'webhook_event_id' => $event->id,
                'topic' => $event->topic,
                'omie_order_id' => $orderId,
                'project_id' => $project->id,
                'project_name' => $project->name,
                'resgates_expedidos' => $expedidos,
            ]);

            return;
        }

        $apagados = app(ProjectPurger::class)->apagarProjeto($project);

        Log::info('Webhook Omie: pedido excluído na Omie — Projeto apagado da plataforma.', [
            'webhook_event_id' => $event->id,
            'topic' => $event->topic,
            'omie_order_id' => $orderId,
            'project_id' => $project->id,
            'project_name' => $project->name,
            'conta_omie' => $event->omie_account,
        ] + $apagados);
    }

    /**
     * Movimentação/disponibilidade de estoque → habilita liberação do resgate.
     */
    private function handleStock(WebhookEvent $event): void
    {
        $code = $event->payload['event']['codigo_produto']
            ?? $event->payload['event']['codigo']
            ?? null;

        // Sem código no evento, reavalia todos os projetos pendentes de estoque
        app(HandleOmieStockSignal::class)->handle($code !== null ? [(string) $code] : [], $event->omie_account);
    }
}
