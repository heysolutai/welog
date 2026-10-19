<?php

namespace App\Http\Controllers;

use App\Contracts\Integrations\FlashGateway;
use App\Contracts\Integrations\OmieGateway;
use App\Jobs\FluxoB\PollFlashTracking;
use App\Models\AppnozImportRow;
use App\Models\PartnerSku;
use App\Models\TrackingEvent;
use App\Services\FluxoB\ProcessAppnozRow;
use App\Services\FluxoB\SendShipmentsToFlash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Importações Parceiros: os resgates que a Reconhece manda pelo webhook.
 *
 * A unidade da tela é o RESGATE, não o lote. A planilha deixou de existir na
 * operação, e o lote do webhook é só o envelope de uma chamada — ninguém
 * procura "o lote de terça", procura a pessoa, o pedido ou o CPF. Cada resgate
 * percorre Pedido na Omie → NF → Flash → entrega, e é esse percurso que a
 * tela acompanha, como a página de Projeto acompanha o dela.
 *
 * Continua Fluxo B de ponta a ponta: nada aqui toca Projeto nem Resgates do
 * portal.
 */
class AppnozImportController extends Controller
{
    /** Etapas filtráveis, na ordem em que o resgate avança. */
    private const ETAPAS = [
        AppnozImportRow::STATUS_PENDING,
        AppnozImportRow::STATUS_ORDER_CREATED,
        AppnozImportRow::STATUS_INVOICED,
        AppnozImportRow::STATUS_DISPATCHED,
        AppnozImportRow::STATUS_DELIVERED,
    ];

    /** Os dois bloqueios num filtro só: para quem opera, são a mesma fila. */
    private const BLOQUEADOS = 'bloqueados';

    public function index(Request $request): Response
    {
        $filtros = [
            'busca' => trim((string) $request->query('busca', '')),
            'etapa' => (string) $request->query('etapa', 'todos'),
        ];

        $contagens = AppnozImportRow::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        $termo = mb_strtolower($filtros['busca']);
        $digitos = preg_replace('/\D/', '', $filtros['busca']);

        $rows = AppnozImportRow::query()
            ->when(
                $filtros['etapa'] === self::BLOQUEADOS,
                fn ($q) => $q->whereIn('status', AppnozImportRow::BLOCKED_STATUSES),
            )
            ->when(
                in_array($filtros['etapa'], self::ETAPAS, true),
                fn ($q) => $q->where('status', $filtros['etapa']),
            )
            ->when($filtros['busca'] !== '', fn ($q) => $q->where(function ($w) use ($filtros, $termo, $digitos) {
                $w->whereRaw('LOWER(customer_name) like ?', ["%{$termo}%"])
                    ->orWhereRaw('LOWER(product_name) like ?', ["%{$termo}%"])
                    ->orWhere('omie_order_number', 'like', "%{$filtros['busca']}%")
                    ->orWhere('external_id', 'like', "%{$filtros['busca']}%");

                // CPF aceito como o operador digitar: com ou sem pontuação.
                if ($digitos !== '') {
                    $w->orWhere('cpf', 'like', "%{$digitos}%");
                }
            }))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $variacoes = $this->variacoes($rows->getCollection());

        return Inertia::render('Imports/Index', [
            'rows' => [
                'data' => $rows->through(fn (AppnozImportRow $r) => [
                    'id' => $r->id,
                    'external_id' => $r->external_id,
                    'customer_name' => $r->customer_name,
                    'cpf' => $r->cpf,
                    'product_code' => $r->product_code,
                    'product_name' => $r->product_name,
                    'variacao' => $variacoes->get(mb_strtolower((string) $r->product_code))?->variacao(),
                    'quantity' => $r->quantity,
                    'omie_order_number' => $r->omie_order_number,
                    'status' => $r->status,
                    'block_reason' => $r->block_reason,
                    'tracking_code' => $r->tracking_code,
                    'divergence_pending' => ! empty($r->customer_divergences) && $r->divergence_resolved_at === null,
                    'created_at' => $r->created_at,
                ])->items(),
                'links' => $rows->linkCollection(),
                'total' => $rows->total(),
            ],
            'filtros' => $filtros,
            'contagens' => $contagens,
        ]);
    }

    /** A página do resgate: o percurso inteiro dele, do webhook à entrega. */
    public function show(Request $request, AppnozImportRow $row): Response
    {
        $row->load(['import:id,filename,source,error,updated_at', 'trackingEvents']);

        $sku = $this->variacoes(collect([$row]))->first();
        $critico = $request->user()?->canPerformCriticalActions() ?? false;

        /*
         * O número que a Flash conhece, só depois da NF.
         *
         * É o `numEncCli` da consulta: antes do faturamento a Flash não tem a
         * encomenda, e mostrar o número do pedido ali sugeriria que dá para
         * procurar por ele lá.
         */
        $referenciaFlash = $row->nf_key !== null
            ? PollFlashTracking::referencia($row)
            : null;

        /*
         * O JSON que vai para a Flash, como vai: conta, contrato e encomenda.
         * Quando a importação recusa, é a primeira coisa que o suporte da
         * Bridge Link pede, e sem isto só dava para ver pelo servidor.
         */
        $jsonFlash = null;

        if ($critico && $row->nf_key !== null) {
            try {
                $corpo = app(FlashGateway::class)->corpoDaImportacao([app(SendShipmentsToFlash::class)->encomenda($row)]);
                $jsonFlash = json_encode(json_decode($corpo, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $e) {
                // Um dado que não vira JSON não pode derrubar a página do resgate.
                $jsonFlash = 'Não foi possível montar o JSON: '.$e->getMessage();
            }
        }

        return Inertia::render('Imports/Show', [
            'resgate' => [
                'id' => $row->id,
                'external_id' => $row->external_id,
                'status' => $row->status,
                'block_reason' => $row->block_reason,
                'customer_name' => $row->customer_name,
                'cpf' => $row->cpf,
                'email' => $row->email,
                'phone' => $row->phone,
                'zip' => $row->zip,
                'street' => $row->street,
                'number' => $row->number,
                'complement' => $row->complement,
                'district' => $row->district,
                'city' => $row->city,
                'state' => $row->state,
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'variacao' => $sku?->variacao(),
                'product_image' => $sku?->images[0] ?? null,
                'quantity' => $row->quantity,
                'benefit_code' => $row->benefit_code,
                'omie_customer_code' => $row->omie_customer_code,
                'customer_auto_created' => $row->customer_auto_created,
                'omie_order_number' => $row->omie_order_number,
                // O ID interno: é ele que a nota da Omie traz (id_pedido).
                'omie_order_id' => $row->omie_order_id,
                'nf_key' => $row->nf_key,
                'nf_number' => $row->nfNumber(),
                'nf_simulated' => $row->nf_simulated,
                'nf_issued_at' => $row->nf_issued_at,
                'tracking_code' => $row->tracking_code,
                'flash_reference' => $referenciaFlash,
                'flash_sent_at' => $row->flash_sent_at,
                'flash_error' => $row->flash_sent_at === null ? $row->flash_error : null,
                'flash_json' => $jsonFlash,
                'delivered_at' => $row->delivered_at,
                'created_at' => $row->created_at,
                'lote' => $row->import?->filename,
                // O motivo de um resgate parado sem pedido (ex.: Omie ocupada).
                'erro' => $row->status === AppnozImportRow::STATUS_PENDING ? $row->import?->error : null,
                'erro_em' => $row->status === AppnozImportRow::STATUS_PENDING && $row->import?->error ? $row->import->updated_at : null,
                'divergences' => $row->customer_divergences ?? [],
                'divergence_resolved' => $row->divergence_resolved_at !== null,
                'divergence_resolution' => $row->divergence_resolution,
                // Mais recente primeiro, como a relação já ordena.
                'events' => $row->trackingEvents->map(fn (TrackingEvent $e) => [
                    'description' => $e->description,
                    'location' => $e->location,
                    'occurred_at' => $e->occurred_at,
                ])->values()->all(),
            ],
            'permissions' => [
                'reprocess' => $critico && ($row->status === AppnozImportRow::STATUS_BLOCKED_PRODUCT || $row->isStuck()),
                'resolve_divergence' => $critico,
                // Consultar a nota precisa do pedido: é por ele que a Omie acha.
                'fetch_invoice' => $critico && filled($row->omie_order_id),
                'send_to_flash' => $critico && SendShipmentsToFlash::podeEnviar($row),
            ],
        ]);
    }

    /**
     * Reprocessa um resgate bloqueado após correção (ex.: produto vinculado) ou
     * parado sem pedido depois de uma recusa da Omie.
     */
    public function reprocessRow(AppnozImportRow $row, ProcessAppnozRow $process): RedirectResponse
    {
        if (! $row->isBlocked() && ! $row->isStuck()) {
            return back()->withErrors(['row' => 'Só resgate bloqueado ou parado sem pedido pode ser reprocessado.']);
        }

        // Bloqueio estrutural (falta CPF, endereço…) só se corrige na origem.
        if ($row->status === AppnozImportRow::STATUS_BLOCKED_STRUCTURAL) {
            return back()->withErrors(['row' => 'Resgate com dados incompletos: corrija na Reconhece e peça o reenvio.']);
        }

        try {
            $process->handle($row);
        } catch (Throwable $e) {
            // A tela mostra a recusa MAIS RECENTE, não a da primeira tentativa.
            $row->import->update(['error' => $e->getMessage()]);

            return back()->withErrors(['row' => 'A Omie recusou agora: '.$e->getMessage()]);
        }

        $row->import->refreshCounters();

        if (! $row->import->rows()->where('status', AppnozImportRow::STATUS_PENDING)->exists()) {
            $row->import->update(['error' => null]);
        }

        return back()->with(
            'status',
            $row->fresh()->isBlocked() ? 'O resgate continua bloqueado (produto ainda sem vínculo com a Omie).' : 'Resgate reprocessado: pedido criado na Omie.',
        );
    }

    /**
     * "Buscar NF na Omie": a nota do pedido deste resgate, pelo ID interno.
     *
     * Para não esperar o webhook nem a varredura de hora em hora — e para
     * destravar o resgate cuja nota já saiu e ele não andou.
     */
    public function fetchInvoice(AppnozImportRow $row, OmieGateway $omie): RedirectResponse
    {
        if (blank($row->omie_order_id)) {
            return back()->withErrors(['nf' => 'Este resgate ainda não tem pedido na Omie.']);
        }

        try {
            $nota = $omie->findInvoiceByOrder((string) $row->omie_order_id);
        } catch (Throwable $e) {
            return back()->withErrors(['nf' => 'A Omie recusou a consulta: '.$e->getMessage()]);
        }

        if ($nota === null) {
            return back()->with('status', "A Omie ainda não tem nota para o pedido {$row->omie_order_number}.");
        }

        $row->registrarNota($nota['nf_key'], $nota['issued_at']);

        return back()->with('status', trim('NF '.($nota['nf_number'] ?? '')).' encontrada e gravada no resgate.');
    }

    /**
     * "Enviar à Flash": importa a encomenda deste resgate na Bridge Link agora.
     *
     * O envio é automático quando a nota chega; o botão é para o que falhou
     * (credencial, contrato) ou para reenviar — o que é seguro, porque número
     * já importado é ignorado do lado de lá.
     */
    public function sendToFlash(AppnozImportRow $row, SendShipmentsToFlash $envio): RedirectResponse
    {
        if (! SendShipmentsToFlash::podeEnviar($row)) {
            return back()->withErrors(['flash' => 'O resgate precisa de NF emitida (de verdade, não simulada) para ir à Flash.']);
        }

        try {
            $envio->handle([$row]);
        } catch (Throwable $e) {
            return back()->withErrors(['flash' => 'A Flash recusou: '.$e->getMessage()]);
        }

        return back()->with('status', 'Encomenda '.PollFlashTracking::referencia($row).' enviada à Flash.');
    }

    /**
     * Resolve a divergência entre o cadastro na Omie e o que veio no resgate.
     *
     * Duas saídas, e as duas são decisões legítimas: manter o que a Omie tem
     * (o cadastro do parceiro estava desatualizado) ou gravar na Omie o que
     * veio no resgate (a pessoa mudou de endereço e avisou o parceiro).
     *
     * Não existe "resolver automaticamente": qual dos dois lados está certo é
     * informação que só quem opera tem.
     */
    public function resolveDivergence(AppnozImportRow $row, Request $request): RedirectResponse
    {
        if (! $request->user()->canPerformCriticalActions()) {
            abort(403);
        }

        $data = $request->validate([
            'resolucao' => ['required', 'in:manter_omie,usar_planilha'],
        ]);

        if (empty($row->customer_divergences)) {
            return back()->withErrors(['divergence' => 'Este resgate não tem divergência pendente.']);
        }

        if ($data['resolucao'] === 'usar_planilha') {
            try {
                app(OmieGateway::class)->upsertCustomer([
                    // Cliente JÁ cadastrado (a divergência é com ele): a chave é
                    // o código Omie dele, senão a Omie recusa ou cria outro.
                    'codigo_cliente_omie' => $row->omie_customer_code,
                    'cnpj_cpf' => $row->cpf,
                    'razao_social' => $row->customer_name,
                    'email' => $row->email,
                    'telefone1_numero' => $row->phone,
                    'endereco' => $row->street,
                    'endereco_numero' => $row->number,
                    'complemento' => $row->complement,
                    'bairro' => $row->district,
                    'cidade' => $row->city,
                    'estado' => $row->state,
                    'cep' => $row->zip,
                ]);
            } catch (Throwable $e) {
                return back()->withErrors(['divergence' => 'Não foi possível atualizar na Omie: '.$e->getMessage()]);
            }
        }

        $row->update([
            'divergence_resolved_at' => now(),
            'divergence_resolution' => $data['resolucao'],
        ]);

        return back()->with('status', $data['resolucao'] === 'usar_planilha'
            ? 'Cadastro atualizado na Omie com os dados do resgate.'
            : 'Divergência arquivada: o cadastro da Omie foi mantido.');
    }

    /**
     * O item do catálogo do parceiro por trás de cada código, para mostrar a
     * variação (cor, tamanho) que a pessoa escolheu na vitrine.
     *
     * Uma consulta para a página inteira, chaveada em minúsculas como a
     * tradução de SKU do ProcessAppnozRow.
     *
     * @param  Collection<int, AppnozImportRow>  $rows
     * @return Collection<string, PartnerSku>
     */
    private function variacoes(Collection $rows): Collection
    {
        $codigos = $rows->pluck('product_code')->filter()->unique()->values();

        if ($codigos->isEmpty()) {
            return collect();
        }

        return PartnerSku::query()
            ->whereIn('sku', $codigos)
            ->get()
            ->keyBy(fn (PartnerSku $s) => mb_strtolower($s->sku));
    }
}
