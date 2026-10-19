<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\FluxoB\ProcessOmieInvoiceWebhook;
use App\Jobs\Integrations\ProcessOmieWebhook;
use App\Jobs\Integrations\ProcessSuvinilWebhook;
use App\Jobs\Integrations\ProcessTrelloWebhook;
use App\Models\Project;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Log dos webhooks recebidos de Omie e Trello.
 *
 * Existe para responder "o webhook chegou e não virou nada — onde parou?".
 * O evento é gravado ANTES de qualquer processamento, então a própria
 * ausência de linha já é resposta: significa que a requisição não chegou.
 */
class WebhookEventController extends Controller
{
    /** Janelas do filtro de período → início do intervalo. */
    private const PERIODOS = ['24h' => 'hoje', '7d' => '7 dias', '30d' => '30 dias'];

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'source' => ['nullable', 'in:omie,trello,suvinil'],
            'status' => ['nullable', 'in:received,processed,ignored,failed'],
            'topic' => ['nullable', 'string', 'max:120'],
            'period' => ['nullable', Rule::in(array_keys(self::PERIODOS))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        // Tudo menos `status`: as abas SÃO o filtro de situação, e a contagem
        // de cada uma tem de ser "quantos, dentro do que já está filtrado".
        $base = fn () => WebhookEvent::query()
            ->when($filtros['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filtros['topic'] ?? null, fn ($q, $v) => $q->where('topic', $v))
            ->when($filtros['period'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $this->desde($v)))
            ->when($filtros['q'] ?? null, fn ($q, $v) => $this->busca($q, $v));

        $eventos = $base()
            ->when($filtros['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Webhooks/Index', [
            'events' => [
                'data' => $eventos->through(fn (WebhookEvent $e) => [
                    'id' => $e->id,
                    'source' => $e->source,
                    'topic' => $e->topic,
                    'status' => $e->status,
                    'error' => $e->error,
                    'external_event_id' => $e->external_event_id,
                    'payload' => $e->payload,
                    'created_at' => $e->created_at?->toIso8601String(),
                    'processed_at' => $e->processed_at?->toIso8601String(),
                ])->items(),
                'links' => $eventos->linkCollection(),
                'total' => $eventos->total(),
                'per_page' => $eventos->perPage(),
                'current_page' => $eventos->currentPage(),
            ],
            'filters' => [
                'source' => $filtros['source'] ?? '',
                'status' => $filtros['status'] ?? '',
                'topic' => $filtros['topic'] ?? '',
                'period' => $filtros['period'] ?? '',
                'q' => $filtros['q'] ?? '',
            ],
            // Tópicos REAIS que já chegaram. Campo livre não servia: ninguém
            // digita "VendaProduto.Incluida" de cabeça, e errar um caractere
            // devolve lista vazia sem dizer por quê.
            'topics' => WebhookEvent::query()
                ->when($filtros['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
                ->distinct()
                ->orderBy('topic')
                ->pluck('topic'),
            'periods' => self::PERIODOS,
            // Contagens do CONJUNTO, não da página: um filtro que conta só a
            // página faz o operador achar que há 2 falhas quando há 40. E
            // respeitam os DEMAIS filtros — global, a aba dizia "40 com falha"
            // depois de filtrar por Trello, que não tem nenhuma.
            'counts' => $this->contagens($base()),
            // Alimenta o aviso de diagnóstico no topo da tela. Os números aqui
            // são SEMPRE globais: o diagnóstico responde "a integração está de
            // pé?", e com base filtrada ele acusaria "nenhum webhook recebido"
            // toda vez que uma busca não achasse nada.
            'diagnosis' => [
                'totalEvents' => WebhookEvent::count(),
                'queued' => WebhookEvent::where('status', WebhookEvent::STATUS_RECEIVED)->count(),
                'failedEvents' => WebhookEvent::where('status', WebhookEvent::STATUS_FAILED)->count(),
                'orderWebhooks' => WebhookEvent::where('topic', 'like', 'VendaProduto.%')->count(),
                'projectsFromOmie' => Project::whereNotNull('omie_order_id')->count(),
                'omieDriver' => config('integrations.omie.driver'),
                'omieTokenSet' => filled(config('integrations.omie.webhook_token')),
                'webhookUrl' => rtrim(config('app.url'), '/').'/webhooks/omie/{token}',
            ],
        ]);
    }

    /**
     * Início da janela do período.
     *
     * "hoje" é meia-noite no fuso do NEGÓCIO, não em UTC: às 21h de Curitiba já
     * é o dia seguinte em Greenwich, e o operador veria a lista de hoje esvaziar
     * no fim da tarde sem entender por quê.
     */
    private function desde(string $periodo): CarbonImmutable
    {
        $agora = CarbonImmutable::now(config('integrations.business_timezone'));

        return match ($periodo) {
            '24h' => $agora->startOfDay(),
            '7d' => $agora->subDays(7),
            '30d' => $agora->subDays(30),
        };
    }

    /**
     * Busca livre: número do evento, id da origem e o conteúdo do payload.
     *
     * O payload entra porque a pergunta real do operador é "cadê o webhook do
     * pedido 66661?" — e o número do pedido só existe lá dentro. Sem isso,
     * restava abrir evento por evento.
     *
     * `CAST(... AS TEXT)` com `LOWER` dos dois lados em vez de `ILIKE`: a coluna
     * é `jsonb` no Postgres e texto no SQLite dos testes, e `ILIKE` não existe
     * no segundo. Assim a mesma consulta roda nos dois.
     */
    private function busca(Builder $query, string $termo): Builder
    {
        $like = '%'.mb_strtolower($termo).'%';

        return $query->where(function (Builder $q) use ($termo, $like) {
            $q->when(ctype_digit($termo), fn ($q) => $q->orWhere('id', (int) $termo))
                ->orWhereRaw('LOWER(external_event_id) LIKE ?', [$like])
                ->orWhereRaw('LOWER(CAST(payload AS TEXT)) LIKE ?', [$like]);
        });
    }

    /**
     * Uma consulta agrupada em vez de quatro `count()`. Situação sem nenhum
     * evento não volta do banco, então o gabarito abaixo garante que a aba
     * apareça com zero em vez de sumir da fila.
     *
     * @return array<string, int>
     */
    private function contagens(Builder $base): array
    {
        // O agregado PRECISA de apelido. `pluck(DB::raw('count(*)'), …)` procura
        // uma propriedade chamada "count(*)": o SQLite dos testes nomeia a
        // coluna exatamente assim e funciona, o Postgres a nomeia "count" e
        // devolve null em tudo — as abas mostrariam zero em produção com a
        // suíte verde.
        $porStatus = $base->reorder()
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'total' => array_sum($porStatus),
            'received' => $porStatus[WebhookEvent::STATUS_RECEIVED] ?? 0,
            'processed' => $porStatus[WebhookEvent::STATUS_PROCESSED] ?? 0,
            'ignored' => $porStatus[WebhookEvent::STATUS_IGNORED] ?? 0,
            'failed' => $porStatus[WebhookEvent::STATUS_FAILED] ?? 0,
        ];
    }

    /** Reenfileira o processamento do evento, sem reenviar nada à origem. */
    public function reprocess(WebhookEvent $webhookEvent): RedirectResponse
    {
        // Volta para `received`: sem isso o job cai no early-return de
        // "já processado" e o reprocessamento não faria nada.
        $webhookEvent->update([
            'status' => WebhookEvent::STATUS_RECEIVED,
            'error' => null,
            'processed_at' => null,
        ]);

        match ($webhookEvent->source) {
            // Nota fiscal tem processamento próprio (o resgate vira "NF emitida").
            WebhookEvent::SOURCE_OMIE => str_starts_with((string) $webhookEvent->topic, 'NFe.')
                ? ProcessOmieInvoiceWebhook::dispatch($webhookEvent->id)
                : ProcessOmieWebhook::dispatch($webhookEvent->id),
            WebhookEvent::SOURCE_TRELLO => ProcessTrelloWebhook::dispatch($webhookEvent->id),
            WebhookEvent::SOURCE_SUVINIL => ProcessSuvinilWebhook::dispatch($webhookEvent->id),
            // Resgate que entrou pela URL da Reconhece: mesmo processamento.
            WebhookEvent::SOURCE_RECONHECE => $webhookEvent->topic === 'Resgate.Lote'
                ? ProcessSuvinilWebhook::dispatch($webhookEvent->id)
                : null,
            default => null,
        };

        return back()->with('status', "Evento #{$webhookEvent->id} reenfileirado para processamento.");
    }
}
