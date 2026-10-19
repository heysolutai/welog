<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Rules\ValorValido;
use App\Services\FluxoA\RedeemKit;
use App\Support\OpcaoDeVariacao;
use App\Support\Values\Cpf;
use App\Support\Variacoes;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal do Usuário (módulo 6): catálogo dos kits a que tem direito,
 * confirmação do resgate com endereço e acompanhamento dos pedidos.
 */
class PortalController extends Controller
{
    /** Kits disponíveis: apenas de Projetos liberados aos quais está vinculado. */
    public function catalog(Request $request): Response
    {
        $entitlements = ProjectEntitlement::query()
            ->withRedeemedFlag()
            ->where('user_id', $request->user()->id)
            ->whereHas('project', fn ($q) => $q->whereNotNull('released_at'))
            ->with(['product.components', 'product.photos', 'project.company'])
            ->get();

        // Grupos de escolha já consumidos: alguma opção do grupo foi resgatada.
        // Calculado sobre a coleção carregada (o flag de resgate já veio na
        // query) — nada de uma consulta por brinde.
        $consumedGroups = $entitlements
            ->filter(fn (ProjectEntitlement $e) => $e->choice_group !== null && $e->isRedeemed())
            ->pluck('choice_group')
            ->unique();

        // Tamanhos de uma grade da Omie (Colete P, M, G…): a vitrine junta os
        // irmãos num presente só, com a escolha do tamanho.
        $paisDaGrade = Product::paisDaGrade($entitlements->pluck('product'));

        // As fotos são do item principal: num tamanho de grade, as do pai, e
        // nenhuma enquanto o pai não ganhar as dele.
        $principais = Product::principais($entitlements->pluck('product'));

        /*
         * Tudo o que a vitrine precisa, de todos os presentes de uma vez.
         *
         * Presente a presente, eram uma consulta de resgate, uma ou duas de
         * variações e uma de fotos por item: umas quarenta consultas para
         * abrir a tela de quem tem o Colete em sete tamanhos, e mil pessoas
         * abrindo o portal ao mesmo tempo multiplicavam isso por mil. Aqui o
         * número de consultas é o mesmo com um presente ou com nove.
         */
        $this->carregarFotos([...array_values($principais), ...array_values($paisDaGrade)]);
        $opcoes = Product::opcoesDeVariacaoEmLote($entitlements->pluck('product'));
        $resgates = $this->resgatesDe($request->user()->id, $entitlements->pluck('project_id')->unique()->all());

        return Inertia::render('Portal/Catalog', [
            'gifts' => $entitlements->map(function (ProjectEntitlement $e) use ($principais, $paisDaGrade, $consumedGroups, $opcoes, $resgates) {
                // Nome e descrição do item como a campanha os escreveu (Itens
                // do pedido > Tela de resgate), pelo código do item principal.
                $texto = $e->project->textoDoItem($principais[$e->product_id]->omie_code);

                return [
                'id' => $e->id,
                'product' => [
                    // Num tamanho de grade, o nome dele ("Colete M"): quem
                    // mostra o nome do presente é o pai, na grade.
                    'name' => isset($paisDaGrade[$e->product_id]) ? $e->product->name : ($texto['nome'] ?? $e->product->name),
                    // Só a descrição escrita para a campanha: a do catálogo
                    // vem da Omie e não foi pensada para quem resgata.
                    'summary' => $texto['descricao'] ?? null,
                    // `photo_url`: a foto enviada pela WeGift vence a espelhada
                    // da Omie. A chave segue `image_url` (a capa) porque é o
                    // que o portal já consome; `images` é a galeria inteira,
                    // que vira carrossel quando tem mais de uma.
                    'image_url' => $principais[$e->product_id]->photo_url,
                    'images' => $principais[$e->product_id]->fotos(),
                    'components' => $e->product->components->map(fn ($c) => [
                        'name' => $c->name,
                        'quantity' => $c->pivot->quantity,
                    ]),
                ],
                // Opções de cor/tamanho pelo parent code; vazio = produto único.
                'variants' => $this->opcoes($e, $opcoes[mb_strtolower((string) $e->product->omie_code)] ?? collect()),
                'company' => $e->project->company->name,
                // A chamada da escolha desta campanha, quando ela trocou.
                'escolha' => $e->project->textosDoPortal()['escolha'] ?? null,
                // O convite "só precisamos do seu CPF e do endereço" não pode
                // citar o CPF de quem o Cliente não coleta.
                'ask_cpf' => $e->project->company->pedeCpf(),
                'redeemed' => $e->isRedeemed(),
                // Opções de escolha (UAT): brindes com o MESMO `choice_group`
                // são alternativas — a tela agrupa e apresenta "escolha um dos
                // N". Nulo = brinde independente, sem agrupamento.
                'choice_group' => $e->choice_group,
                // Outra opção do grupo já foi escolhida: este aqui não pode
                // mais ser resgatado (a tela desabilita o botão e explica).
                'choice_locked' => $e->choice_group !== null
                    && ! $e->isRedeemed()
                    && $consumedGroups->contains($e->choice_group),
                // Freio de mão puxado por um admin: o brinde continua na lista
                // (some seria pior: a pessoa acha que perdeu o presente), mas
                // o resgate fica suspenso com o aviso na tela.
                'blocked' => $e->project->isRedemptionBlocked(),
                // Depois do resgate a tela vira acompanhamento: precisa do
                // pedido para mostrar status, etapas e data.
                'redemption' => $this->resumoDoResgate($resgates->get($e->project_id.'|'.$e->product_id)),
                'grade' => isset($paisDaGrade[$e->product_id]) ? [
                    'pai_code' => $paisDaGrade[$e->product_id]->omie_code,
                    'pai_nome' => $texto['nome'] ?? $paisDaGrade[$e->product_id]->name,
                    'pai_foto' => $paisDaGrade[$e->product_id]->photo_url,
                    'pai_fotos' => $paisDaGrade[$e->product_id]->fotos(),
                    ...$paisDaGrade[$e->product_id]->rotuloNaGrade($e->product),
                ] : null,
                ];
            }),
        ]);
    }

    /**
     * As opções de cor/tamanho do produto pai, para a vitrine e o resgate.
     *
     * Vêm da grade da Omie ou do catálogo do parceiro (Product::opcoesDeVariacao).
     * Não têm estoque próprio no resgate —
     * a variação é produzida depois da escolha —, então nenhuma "esgota"
     * sozinha: o que acaba é o saldo do pai, e esse a tela já trata.
     *
     * `$opcoes`: as do produto, já buscadas em lote (vitrine); sem elas, busca
     * só as deste (tela de resgate, um presente).
     *
     * @param  \Illuminate\Support\Collection<int, OpcaoDeVariacao>|null  $opcoes
     * @return array<int, array{id: int, sku: string, color: ?string, color_hex: ?string, size: ?string, image_url: ?string, available: bool}>
     */
    private function opcoes(ProjectEntitlement $e, ?\Illuminate\Support\Collection $opcoes = null): array
    {
        return ($opcoes ?? $e->product->opcoesDeVariacao())
            ->map(fn (OpcaoDeVariacao $s) => [
                'id' => $s->id,
                'sku' => $s->sku,
                'color' => $s->color,
                'color_hex' => Variacoes::hexDaCor($s->color),
                'size' => $s->size,
                // A foto é a do presente, não a da variação: escolher o
                // tamanho não troca a galeria.
                'image_url' => $e->product->photo_url,
                'available' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * Os resgates da pessoa nestes Projetos, numa consulta só: o mais recente
     * de cada item, por "projeto|produto".
     *
     * @param  array<int, int>  $projetos
     * @return \Illuminate\Support\Collection<string, Redemption>
     */
    private function resgatesDe(int $userId, array $projetos): \Illuminate\Support\Collection
    {
        if ($projetos === []) {
            return collect();
        }

        return Redemption::query()
            ->where('user_id', $userId)
            ->whereIn('project_id', $projetos)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (Redemption $r) => $r->project_id.'|'.$r->product_id)
            ->keyBy(fn (Redemption $r) => $r->project_id.'|'.$r->product_id);
    }

    /**
     * As fotos de vários produtos numa consulta só. Pai de grade só de memória
     * (sem cadastro) não tem fotos e não consulta nada.
     *
     * @param  array<int, Product>  $produtos
     */
    private function carregarFotos(array $produtos): void
    {
        $semFotos = array_values(array_filter(
            $produtos,
            fn (Product $p) => $p->exists && ! $p->relationLoaded('photos'),
        ));

        if ($semFotos !== []) {
            (new EloquentCollection($semFotos))->load('photos');
        }
    }

    /** @return array{id: int, status: string, created_at: string|null, shipped_at: string|null, delivered_at: string|null, variant: ?string}|null */
    private function resumoDoResgate(?Redemption $r): ?array
    {
        return $r === null ? null : [
            'id' => $r->id,
            'status' => $r->status,
            'created_at' => $r->created_at?->toISOString(),
            'shipped_at' => $r->shipped_at?->toISOString(),
            'delivered_at' => $r->delivered_at?->toISOString(),
            // A cor/tamanho que a pessoa escolheu ("Preto · M").
            'variant' => $r->rotuloDaVariacao(),
        ];
    }

    public function redeemForm(Request $request, ProjectEntitlement $entitlement): Response|RedirectResponse
    {
        abort_unless($entitlement->user_id === $request->user()->id, 403);

        // Grupo de escolha consumido: a irmã escolhida levou o direito. O
        // bloqueio na LEITURA evita a pessoa preencher o endereço inteiro para
        // ouvir "não" só no envio.
        if ($entitlement->choice_group !== null
            && ! $entitlement->isRedeemed()
            && $entitlement->choiceGroupConsumed()) {
            return redirect()->route('portal.catalog')
                ->withErrors(['redeem' => 'Você já escolheu seu brinde neste projeto.']);
        }

        // Mesma ideia para o freio de mão: barrar na LEITURA poupa a pessoa de
        // preencher o endereço inteiro para ouvir "não" no envio.
        if ($entitlement->project->isRedemptionBlocked()) {
            return redirect()->route('portal.catalog')
                ->withErrors(['redeem' => 'Os resgates deste projeto estão temporariamente bloqueados. Fale com a WeGift.']);
        }

        $entitlement->load('product.components', 'product.photos', 'project.company');

        $principal = Product::principais([$entitlement->product])[$entitlement->product_id];
        $texto = $entitlement->project->textoDoItem($principal->omie_code);
        // Num tamanho de grade o nome é o do presente, como a vitrine mostra
        // ("Jaqueta + Necessaire"), e o tamanho vem à parte ("G"). O nome cru
        // do tamanho na Omie ("Jaqueta corta vento G") deixava o item pela metade.
        $eTamanho = $principal->isNot($entitlement->product);

        return Inertia::render('Portal/Redeem', [
            'entitlement' => [
                'id' => $entitlement->id,
                'company' => $entitlement->project->company->name,
                'product' => [
                    'name' => $texto['nome'] ?? ($eTamanho ? $principal->name : $entitlement->product->name),
                    'tamanho' => $eTamanho ? $principal->rotuloNaGrade($entitlement->product)['rotulo'] : null,
                    'image_url' => $principal->photo_url,
                    'images' => $principal->fotos(),
                    // A da campanha vence a do catálogo.
                    'description' => $texto['descricao'] ?? $entitlement->product->description,
                    'components' => $entitlement->product->components->map(fn ($c) => [
                        'name' => $c->name,
                        'quantity' => $c->pivot->quantity,
                    ]),
                ],
                // Com opções, o resgate começa pela escolha de cor e tamanho.
                'variants' => $this->opcoes($entitlement),
            ],
            'defaultName' => $request->user()->name,
            'askCpf' => $entitlement->project->company->pedeCpf(),
        ]);
    }

    public function redeem(
        Request $request,
        ProjectEntitlement $entitlement,
        RedeemKit $redeemKit,
    ): RedirectResponse {
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:255'],
            // CPF obrigatório (inclusão UAT): segue para a NF e para a baixa
            // de estoque na Omie. Aceita com ou sem máscara; o serviço guarda
            // só os dígitos. Cliente que não coleta CPF não tem o campo na
            // tela — e o que vier nele é descartado.
            'cpf' => ($entitlement->project->company?->pedeCpf() ?? true)
                ? ['required', 'string', new ValorValido(Cpf::class)]
                : ['exclude'],
            'zip' => ['required', 'string'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Opção de cor/tamanho escolhida. O serviço confere se é do
            // parent code deste produto; aqui só o formato.
            'variant_sku_id' => ['nullable', 'integer'],
        ], [], ['recipient_name' => 'nome do destinatário', 'notes' => 'observações', 'variant_sku_id' => 'cor e tamanho']);

        try {
            $redeemKit->handle($entitlement, $data, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['redeem' => $e->getMessage()]);
        }

        return redirect()->route('portal.redemptions')
            ->with('status', 'Resgate confirmado! Você receberá as atualizações por e-mail.');
    }

    /** Histórico de resgates com rastreio (módulos 6 e 9). */
    public function redemptions(Request $request): Response
    {
        $redemptions = $request->user()->redemptions()
            ->with('product', 'project.company', 'trackingEvents')
            ->latest()
            ->get();

        return Inertia::render('Portal/Redemptions', [
            'redemptions' => $redemptions->map(fn ($r) => [
                'id' => $r->id,
                // Com variação: "Camiseta Preta · M".
                'product' => implode(' · ', array_filter([$r->product->name, $r->rotuloDaVariacao()])),
                'company' => $r->project->company->name,
                'status' => $r->status,
                // Sempre mascarado na exibição (000.000.000-00); resgates
                // antigos, de antes da obrigatoriedade, vêm null.
                'cpf' => $r->cpf_masked,
                'notes' => $r->notes,
                'quantity' => $r->quantity,
                'carrier' => $r->carrier,
                'tracking_code' => $r->tracking_code,
                'tracking_url' => $r->tracking_url,
                'created_at' => $r->created_at,
                'address' => $r->short_address,
                'events' => $r->trackingEvents->map(fn ($e) => [
                    'occurred_at' => $e->occurred_at,
                    'description' => $e->description,
                    'location' => $e->location,
                ]),
            ]),
        ]);
    }
}
