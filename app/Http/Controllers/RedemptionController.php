<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Mail\NotificaColaborador;
use App\Mail\ShipmentUpdateMail;
use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\Redemption;
use App\Models\TrackingEvent;
use App\Rules\ValorValido;
use App\Services\FluxoA\CancelRedemption;
use App\Services\FluxoA\ShipRedemption;
use App\Support\Auditoria;
use App\Support\Values\Cpf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RedemptionController extends Controller
{
    /**
     * Resgates de Projetos: só a tabela `redemptions`.
     *
     * Esta tela já uniu os dois processos (os resgates de Projetos e as linhas
     * importadas de parceiros, o "Fluxo B" interno), mas são operações
     * independentes, com status e expedição próprios — o cliente pediu a
     * separação. O rastreio das importações vive no módulo Importações
     * Parceiros (imports.index).
     */
    /** Períodos do filtro, em dias. */
    private const PERIODOS = [7, 30, 90];

    public function index(Request $request): Response
    {
        $filtros = $this->filtros($request);

        $pagina = $this->filtrado($filtros)
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        // Uma vez por PÁGINA, não por linha: dentro do `map` isto seria o N+1
        // na própria tela que existe para consolidar rastreio.
        $eventos = $this->ultimosEventos($pagina->items());

        return Inertia::render('Redemptions/Index', [
            'redemptions' => [
                'data' => collect($pagina->items())
                    ->map(fn ($r) => $this->linha($r, $eventos))
                    ->values(),
                'links' => $pagina->linkCollection(),
                'total' => $pagina->total(),
                'from' => $pagina->firstItem(),
                'to' => $pagina->lastItem(),
            ],
            'filtros' => $filtros,
            'contagens' => $this->contagens($filtros),
            'projetos' => $this->projetosComResgate(),
            'empresas' => $this->empresasComResgate(),
            'canShip' => $request->user()?->canPerformCriticalActions() ?? false,
        ]);
    }

    /**
     * Todos os resgates do filtro atual, não só a página — para "selecionar
     * todos os 340" e mudar o status de uma vez.
     *
     * Volta o mínimo para a barra de seleção contar quantos cabem em cada
     * ação e para o painel de expedição listar quem vai ser expedido.
     */
    public function selection(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerformCriticalActions(), 403);

        return response()->json([
            'itens' => $this->filtrado($this->filtros($request))
                ->orderByDesc('created_at')
                ->get(['id', 'pessoa', 'cidade', 'uf', 'status'])
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'pessoa' => $r->pessoa,
                    'destino' => $r->cidade.'/'.$r->uf,
                    'status' => $r->status,
                ]),
        ]);
    }

    /**
     * Os filtros da lista, lidos da querystring. 0 / vazio = todos.
     *
     * O Projeto é o recorte que a operação faz o tempo todo: a transportadora
     * avisa a entrega de uma campanha inteira, e a busca por texto não separa
     * dois Projetos do mesmo cliente. Empresa, transportadora e período são os
     * recortes de quem vai mudar o status em massa.
     */
    private function filtros(Request $request): array
    {
        $periodo = (int) $request->query('periodo', 0);

        return [
            'busca' => trim((string) $request->query('busca', '')),
            'situacao' => $request->query('situacao', 'todas'),
            'projeto' => (int) $request->query('projeto', 0),
            'empresa' => (int) $request->query('empresa', 0),
            // '' = todas; 'sem' = ainda sem transportadora.
            'transportadora' => (string) $request->query('transportadora', ''),
            'periodo' => in_array($periodo, self::PERIODOS, true) ? $periodo : 0,
        ];
    }

    /** A consulta da lista com os filtros aplicados; `$comSituacao` deixa de fora o status. */
    private function filtrado(array $filtros, bool $comSituacao = true): Builder
    {
        return DB::query()
            ->fromSub($this->resgates(), 'resgates')
            ->when($comSituacao && $filtros['situacao'] !== 'todas', fn ($q) => $q->where('situacao', $filtros['situacao']))
            ->when($filtros['projeto'] > 0, fn ($q) => $q->where('project_id', $filtros['projeto']))
            ->when($filtros['empresa'] > 0, fn ($q) => $q->where('company_id', $filtros['empresa']))
            ->when($filtros['transportadora'] === 'sem', fn ($q) => $q->whereNull('carrier'))
            ->when(
                ! in_array($filtros['transportadora'], ['', 'sem'], true),
                fn ($q) => $q->where('carrier', $filtros['transportadora']),
            )
            ->when($filtros['periodo'] > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($filtros['periodo'])))
            ->when($filtros['busca'] !== '', fn ($q) => $q->where(
                fn ($w) => $w->whereRaw('LOWER(pessoa) like ?', ['%'.mb_strtolower($filtros['busca']).'%'])
                    ->orWhere('tracking_code', 'like', "%{$filtros['busca']}%")
                    ->orWhere('referencia', 'like', "%{$filtros['busca']}%"),
            ));
    }

    /**
     * As Empresas que aparecem no filtro: só as que têm resgate.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    private function empresasComResgate(): array
    {
        return DB::table('companies as c')
            ->join('projects as p', 'p.company_id', '=', 'c.id')
            ->join('redemptions as r', 'r.project_id', '=', 'p.id')
            ->distinct()
            ->orderBy('c.name')
            ->get(['c.id', 'c.name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'nome' => $c->name])
            ->all();
    }

    /**
     * Os Projetos que aparecem no filtro: só os que têm resgate.
     *
     * Listar o catálogo inteiro encheria o seletor de Projetos em Design, que
     * nunca vão aparecer nesta tela.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    private function projetosComResgate(): array
    {
        return DB::table('projects as p')
            ->join('redemptions as r', 'r.project_id', '=', 'p.id')
            ->distinct()
            ->orderBy('p.name')
            ->get(['p.id', 'p.name'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'nome' => $p->name])
            ->all();
    }

    /**
     * `situacao` traduz o status técnico para o vocabulário das abas; o selo
     * de cada linha continua mostrando o status real.
     */
    private function resgates(): Builder
    {
        return DB::table('redemptions as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->join('projects as pj', 'pj.id', '=', 'r.project_id')
            ->join('companies as c', 'c.id', '=', 'pj.company_id')
            ->selectRaw("
                r.id,
                r.created_at,
                u.name as pessoa,
                r.city || '/' || r.state as destino,
                r.recipient_name as destinatario,
                r.street as rua,
                r.number as numero,
                r.complement as complemento,
                r.district as bairro,
                r.city as cidade,
                r.state as uf,
                r.zip as cep,
                p.name as kit,
                r.variant_color,
                r.variant_size,
                c.name as referencia,
                c.id as company_id,
                pj.id as project_id,
                r.status,
                CASE r.status
                    WHEN 'confirmed' THEN 'aguardando'
                    WHEN 'shipped'   THEN 'em_transporte'
                    WHEN 'delivered' THEN 'entregue'
                    WHEN 'canceled'  THEN 'cancelado'
                    ELSE 'aguardando'
                END as situacao,
                r.carrier,
                r.tracking_code,
                r.tracking_url,
                r.cpf,
                r.notes,
                r.quantity,
                r.requested_by
            ");
    }

    /**
     * Contagem por situação, para as abas dizerem quanto há em cada uma.
     *
     * Os demais filtros entram na conta (menos o próprio status): com eles
     * aplicados, a aba dizendo "Entregue 340" enquanto a tabela mostra 6
     * linhas é um número que não descreve nada do que está na tela.
     */
    private function contagens(array $filtros): array
    {
        return $this->filtrado($filtros, comSituacao: false)
            ->selectRaw('situacao, count(*) as total')
            ->groupBy('situacao')
            ->pluck('total', 'situacao')
            ->all();
    }

    /**
     * Último evento de rastreio de cada linha da página, em UMA consulta.
     * Buscar evento por linha devolveria o N+1 justamente na tela que existe
     * para consolidar rastreio.
     *
     * @param  array<int, object>  $itens
     * @return array<int, TrackingEvent>
     */
    private function ultimosEventos(array $itens): array
    {
        $ids = collect($itens)->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        return TrackingEvent::query()
            ->where('trackable_type', Redemption::class)
            ->whereIn('trackable_id', $ids)
            ->orderByDesc('occurred_at')
            ->get()
            // Ordenado por data desc: o primeiro de cada resgate é o mais recente.
            ->unique('trackable_id')
            ->keyBy('trackable_id')
            ->all();
    }

    /** @param array<int, TrackingEvent> $eventos */
    private function linha(object $r, array $eventos): array
    {
        $ultimo = $eventos[$r->id] ?? null;

        return [
            'id' => $r->id,
            'pessoa' => $r->pessoa,
            'destino' => $r->destino,
            // O endereço de entrega em duas linhas curtas: rua e número; e
            // bairro, cidade/UF. O CEP vai junto, que é o que a expedição usa.
            'endereco' => [
                'linha1' => trim(implode(', ', array_filter([$r->rua, $r->numero, $r->complemento]))),
                'linha2' => trim(implode(' · ', array_filter([$r->bairro, $r->destino]))),
                'cep' => $r->cep ? preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $r->cep) : null,
                // Campo a campo, para o "Alterar endereço" abrir preenchido.
                'campos' => [
                    'recipient_name' => $r->destinatario,
                    'zip' => $r->cep ? preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $r->cep) : '',
                    'street' => $r->rua,
                    'number' => $r->numero,
                    'complement' => $r->complemento,
                    'district' => $r->bairro,
                    'city' => $r->cidade,
                    'state' => $r->uf,
                ],
            ],
            // Com variação, a cor/tamanho escolhidos vão junto: é o que a
            // expedição separa ("Camiseta Preta · M").
            'kit' => implode(' · ', array_filter([$r->kit, $r->variant_color, $r->variant_size])),
            'referencia' => $r->referencia,
            'project_id' => $r->project_id,
            'status' => $r->status,
            'situacao' => $r->situacao,
            // Inclusões UAT: CPF sempre mascarado (resgates antigos vêm null),
            // observações, quantidade e o marcador de solicitação de admin.
            'cpf' => $r->cpf ? Cpf::doBanco($r->cpf)->formatado() : null,
            'observacoes' => $r->notes,
            'quantidade' => (int) $r->quantity,
            'solicitacao' => $r->requested_by !== null,
            'carrier' => $r->carrier,
            'tracking_code' => $r->tracking_code,
            'tracking_url' => $r->tracking_url,
            'created_at' => $r->created_at,
            'ultimo_evento' => $ultimo ? [
                'titulo' => $ultimo->description ?: $ultimo->status,
                'ocorrido_em' => $ultimo->occurred_at,
            ] : null,
        ];
    }

    /**
     * Define transportadora e expede.
     *
     * A lista de transportadoras é cadastro, não constante: validar contra as
     * ativas é o que impede a quarta transportadora de aparecer na tela e ser
     * recusada aqui, que era o efeito do `in:correios,mrb,ddm`.
     */
    public function ship(Redemption $redemption, Request $request, ShipRedemption $ship): RedirectResponse
    {
        $data = $request->validate([
            'carrier' => ['required', Rule::exists('carriers', 'slug')->where('active', true)],
            'tracking_code' => ['nullable', 'string', 'max:50'],
            // Link vem pronto da transportadora; o tamanho acompanha a coluna.
            'tracking_url' => ['nullable', 'string', 'max:500', 'url'],
        ], [], [
            'carrier' => 'transportadora',
            'tracking_code' => 'código de rastreio',
            'tracking_url' => 'link de rastreio',
        ]);

        try {
            $ship->handle(
                $redemption,
                $data['carrier'],
                $request->user(),
                $data['tracking_code'] ?? null,
                $data['tracking_url'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->withErrors(['ship' => $e->getMessage()]);
        }

        return back()->with('status', 'Resgate expedido.');
    }

    /**
     * O CPF que ficou para depois.
     *
     * A WeGift abre a solicitação com o que chegou por telefone ou e-mail, e o
     * número costuma vir junto da nota. Ele se completa aqui, na linha da
     * lista, que é onde a pendência aparece — sem isso, o CPF em branco só
     * teria saída recriando o resgate.
     */
    public function updateCpf(Redemption $redemption, Request $request): RedirectResponse
    {
        if (! $request->user()->canPerformCriticalActions()) {
            abort(403);
        }

        if ($redemption->status === Redemption::STATUS_CANCELED) {
            return back()->withErrors(['cpf' => 'Este resgate foi cancelado.']);
        }

        $data = $request->validate([
            'cpf' => ['required', 'string', new ValorValido(Cpf::class)],
        ], [], ['cpf' => 'CPF']);

        // Só dígitos no banco; a máscara é apresentação (cpf_masked).
        $tinhaCpf = filled($redemption->cpf);
        $redemption->update(['cpf' => Cpf::tentar($data['cpf'])->digitos]);

        // O CPF em si não vai para o registro: a trilha diz QUE mudou, e quem.
        Auditoria::registrar(AuditLog::REDEMPTION_CPF_CHANGED, ($tinhaCpf ? 'Alterou' : 'Informou')." o CPF do resgate #{$redemption->id}.", $redemption);

        return back()->with('status', 'CPF informado.');
    }

    /**
     * Corrigir o endereço de entrega de um resgate.
     *
     * A WeGift decide, em qualquer momento: depois de expedir, ela pode já ter
     * combinado a troca com a transportadora, e o sistema tem de refletir para
     * onde o pacote vai de fato. Quem avisa que o pacote já saiu é a tela, com
     * uma confirmação; aqui só o cancelado fica de fora.
     *
     * O endereço anterior vai para o registro: é o que permite responder
     * depois "para onde ia, e quem mudou".
     */
    public function updateAddress(Redemption $redemption, Request $request): RedirectResponse
    {
        if (! $request->user()->canPerformCriticalActions()) {
            abort(403);
        }

        if ($redemption->status === Redemption::STATUS_CANCELED) {
            return back()->withErrors(['address' => 'Este resgate foi cancelado.']);
        }

        // As mesmas regras do endereço no portal (PortalController::redeem).
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:255'],
            'zip' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
        ], [], ['recipient_name' => 'nome do destinatário', 'zip' => 'CEP', 'state' => 'UF']);

        $campos = ['recipient_name', 'zip', 'street', 'number', 'complement', 'district', 'city', 'state'];
        $anterior = $redemption->only($campos);

        $redemption->update([
            ...$data,
            'zip' => preg_replace('/\D/', '', $data['zip']),
            'state' => mb_strtoupper($data['state']),
        ]);

        Auditoria::registrar(
            AuditLog::REDEMPTION_ADDRESS_CHANGED,
            "Alterou o endereço de entrega do resgate #{$redemption->id}"
                .($redemption->status === Redemption::STATUS_CONFIRMED ? '.' : ', já expedido.'),
            $redemption,
            ['anterior' => $anterior, 'status' => $redemption->status],
        );

        return back()->with('status', 'Endereço de entrega atualizado.');
    }

    /** Entrega manual para transportadoras sem API (MRB/DDM). */
    public function markDelivered(Redemption $redemption, Request $request): RedirectResponse
    {
        if (! $request->user()->canPerformCriticalActions()) {
            abort(403);
        }

        if ($redemption->status !== Redemption::STATUS_SHIPPED) {
            return back()->withErrors(['deliver' => 'Só resgates expedidos podem ser marcados como entregues.']);
        }

        $this->entregar($redemption);

        return back()->with('status', 'Resgate marcado como entregue.');
    }

    /**
     * Marca como entregue tudo que foi selecionado na lista.
     *
     * A transportadora entrega em lote e avisa em lote: quinze kits chegaram
     * ao mesmo destino no mesmo dia, e marcar um a um são quinze confirmações
     * e quinze recarregamentos da página. Quem não está expedido é ignorado em
     * silêncio, não recusado — a seleção pode ter pegado uma linha já entregue
     * de arrasto, e falhar o lote inteiro por causa dela seria pior.
     */
    public function deliverBatch(Request $request): RedirectResponse
    {
        if (! $request->user()->canPerformCriticalActions()) {
            abort(403);
        }

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], [], ['ids' => 'resgates']);

        $resgates = Redemption::with('user', 'project')
            ->whereIn('id', $data['ids'])
            ->where('status', Redemption::STATUS_SHIPPED)
            ->get();

        if ($resgates->isEmpty()) {
            return back()->withErrors([
                'deliver' => 'Nenhum dos resgates selecionados está em transporte.',
            ]);
        }

        foreach ($resgates as $resgate) {
            $this->entregar($resgate);
        }

        $total = $resgates->count();

        return back()->with(
            'status',
            $total === 1
                ? '1 resgate marcado como entregue.'
                : $total.' resgates marcados como entregues.',
        );
    }

    /**
     * Expede vários resgates pela mesma transportadora, cada um com o próprio
     * rastreio.
     *
     * Uma regra por resgate, a mesma do `ship`: o serviço é chamado linha a
     * linha. Linha recusada não derruba as outras — volta no bag `ship_batch` com o
     * nome de quem ficou, e as demais seguem expedidas.
     */
    public function shipBatch(Request $request, ShipRedemption $ship): RedirectResponse
    {
        abort_unless($request->user()->canPerformCriticalActions(), 403);

        $data = $request->validate([
            'carrier' => ['required', Rule::exists('carriers', 'slug')->where('active', true)],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.id' => ['required', 'integer'],
            'itens.*.tracking_code' => ['nullable', 'string', 'max:50'],
            'itens.*.tracking_url' => ['nullable', 'string', 'max:500', 'url'],
        ], [], ['carrier' => 'transportadora', 'itens' => 'resgates']);

        $resgates = Redemption::with('user', 'project')
            ->whereIn('id', array_column($data['itens'], 'id'))
            ->get()
            ->keyBy('id');

        $expedidos = 0;
        $recusas = [];

        foreach ($data['itens'] as $item) {
            $resgate = $resgates->get($item['id']);

            if ($resgate === null) {
                continue;
            }

            try {
                $ship->handle(
                    $resgate,
                    $data['carrier'],
                    $request->user(),
                    $item['tracking_code'] ?? null,
                    $item['tracking_url'] ?? null,
                );
                $expedidos++;
            } catch (DomainException $e) {
                $recusas[] = $resgate->user->name.': '.$e->getMessage();
            }
        }

        $resposta = back()->with('status', $expedidos === 1 ? '1 resgate expedido.' : "{$expedidos} resgates expedidos.");

        return $recusas === [] ? $resposta : $resposta->withErrors(['ship_batch' => implode(' | ', $recusas)]);
    }

    /**
     * Cancela vários resgates ainda não expedidos, devolvendo o saldo de cada
     * um. Quem já saiu para entrega é ignorado em silêncio — mesma lógica da
     * entrega em massa: a seleção pode ter pegado uma linha de arrasto.
     */
    public function cancelBatch(Request $request, CancelRedemption $cancel): RedirectResponse
    {
        abort_unless($request->user()->canPerformCriticalActions(), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], [], ['ids' => 'resgates']);

        $resgates = Redemption::whereIn('id', $data['ids'])
            ->where('status', Redemption::STATUS_CONFIRMED)
            ->get();

        if ($resgates->isEmpty()) {
            return back()->withErrors(['cancel' => 'Nenhum dos resgates selecionados está aguardando expedição.']);
        }

        foreach ($resgates as $resgate) {
            $cancel->handle($resgate, $request->user());
        }

        $total = $resgates->count();

        return back()->with('status', $total === 1 ? '1 resgate cancelado.' : "{$total} resgates cancelados.");
    }

    /** A entrega em si: carimbo, status e o aviso para quem recebeu. */
    private function entregar(Redemption $redemption): void
    {
        $redemption->update([
            'status' => Redemption::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        NotificaColaborador::enfileirar($redemption->user, new ShipmentUpdateMail(
            $redemption,
            'Pedido entregue!',
            'Seu presente foi entregue. Esperamos que aproveite!',
            EmailTemplate::KEY_REDEMPTION_DELIVERED,
        ));
    }

    /**
     * Cancela um resgate ainda não expedido e devolve o saldo baixado
     * (plataforma e Omie) — ver `CancelRedemption`. A autorização da pessoa
     * reabre sozinha: `isRedeemed`/`choiceGroupConsumed` ignoram cancelados.
     */
    public function cancel(Redemption $redemption, Request $request, CancelRedemption $cancel): RedirectResponse
    {
        if (! $request->user()->canPerformCriticalActions()) {
            abort(403);
        }

        try {
            $cancel->handle($redemption, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        Auditoria::registrar(AuditLog::REDEMPTION_CANCELLED, "Cancelou o resgate #{$redemption->id}.", $redemption);

        return back()->with('status', 'Resgate cancelado. O estoque baixado volta para a Omie em instantes.');
    }
}
