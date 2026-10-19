<?php

namespace App\Http\Controllers;

use App\Jobs\Integrations\ImportOmieCatalog;
use App\Models\Product;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Integrations\PublishProductToReconhece;
use App\Support\Variacoes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de Produtos e Kits (Módulo 5) — consulta e exibição, para o operador
 * WeGift, do catálogo espelhado da Omie; e disparo do sync sob demanda.
 * A Omie permanece como fonte da verdade: aqui só se lê e se reflete.
 */
class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        /*
         * Os tamanhos da grade da Omie (PRD11361_1, _2, _3) não são produtos à
         * parte para quem olha o catálogo: são o PRD11361. Saem da lista e
         * aparecem como subitens do pai.
         */
        $filhos = Product::filhosDeGrade();
        $idsFilhos = array_keys($filhos);

        // Quem busca "PRD11361_2" ou "jaqueta M" quer achar a jaqueta: a busca
        // que acerta um tamanho traz o pai.
        $paisDaBusca = $search === '' || $idsFilhos === [] ? [] : Product::query()
            ->whereIn('id', $idsFilhos)
            ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('omie_code', 'ilike', "%{$search}%"))
            ->pluck('id')
            ->map(fn (int $id) => $filhos[$id])
            ->unique()
            ->values()
            ->all();

        /*
         * Grade sem o pai no catálogo (a Omie tem só os tamanhos): o grupo
         * ocupa a linha do primeiro tamanho, com o nome em comum, e os demais
         * saem da lista — como os de uma grade com pai.
         */
        $semPai = Product::gradesSemPai();
        $representantes = [];
        $representanteDe = [];
        $ocultos = $idsFilhos;

        foreach ($semPai as $grupo) {
            $representantes[$grupo['representante']] = $grupo;

            foreach ($grupo['ids'] as $id) {
                $representanteDe[$id] = $grupo['representante'];

                if ($id !== $grupo['representante']) {
                    $ocultos[] = $id;
                }
            }
        }

        // A busca que acerta qualquer tamanho do grupo traz a linha do grupo.
        $representantesDaBusca = $search === '' || $representanteDe === [] ? [] : Product::query()
            ->whereIn('id', array_keys($representanteDe))
            ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('omie_code', 'ilike', "%{$search}%"))
            ->pluck('id')
            ->map(fn (int $id) => $representanteDe[$id])
            ->unique()
            ->values()
            ->all();

        $pagina = Product::query()
            ->withCount('components')
            ->with('partnerSku:id,product_id,product_type,department,enabled', 'photos')
            ->when($ocultos !== [], fn ($query) => $query->whereNotIn('id', $ocultos))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('omie_code', 'ilike', "%{$search}%")
                ->when($paisDaBusca !== [], fn ($q) => $q->orWhereIn(DB::raw('LOWER(omie_code)'), $paisDaBusca))
                ->when($representantesDaBusca !== [], fn ($q) => $q->orWhereIn('id', $representantesDaBusca))))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $variacoes = $this->variacoesDaPagina($pagina->getCollection(), $filhos)
            ->union($this->variacoesSemPai($pagina->getCollection(), $representantes));

        $products = $pagina->through(fn (Product $p) => [
                'id' => $p->id,
                // Linha de grade sem pai: o grupo ("Colete", PRD11363), não o
                // primeiro tamanho que a ocupa.
                'omie_code' => isset($representantes[$p->id]) ? $representantes[$p->id]['codigo'] : $p->omie_code,
                'name' => isset($representantes[$p->id]) ? $representantes[$p->id]['nome'] : $p->name,
                'grupo_sem_pai' => isset($representantes[$p->id]),
                // A foto enviada num Projeto tem de aparecer aqui: é a prova de
                // que o upload atualizou o catálogo, e não só aquela tela.
                // Grupo sem pai não tem foto: a do primeiro tamanho seria a de
                // um tamanho, e eles não têm foto própria.
                'photo_url' => isset($representantes[$p->id]) ? null : $p->photo_url,
                'is_kit' => $p->is_kit,
                'components_count' => $p->components_count,
                'stock_qty' => (float) $p->stock_qty,
                'stock_synced_at' => $p->stock_synced_at?->toIso8601String(),
                'active' => $p->active,
                // A descrição não é exibida na tabela: vai junto porque a
                // Reconhece a exige, e a tela avisa ANTES de o operador
                // abrir o diálogo e descobrir no erro.
                'has_description' => filled($p->description),
                // Publicado ou não: decide o selo e o texto do item no menu.
                'reconhece' => $p->partnerSku ? ['enabled' => $p->partnerSku->enabled] : null,
                /*
                 * O que está salvo, para o diálogo de envio abrir preenchido.
                 *
                 * As categorias são do parceiro e não existem na Omie: quem
                 * envia não deve ter de lembrar o que digitou antes.
                 */
                'categorias' => [
                    'product_type' => $p->reconhece_product_type ?? $p->partnerSku?->product_type,
                    'department' => $p->reconhece_department ?? $p->partnerSku?->department,
                ],
                'variacoes' => $variacoes->get($p->id, []),
            ]);

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => ['q' => $search],
            'summary' => [
                // Tamanho da grade não conta: na lista ele é subitem do pai.
                'total' => Product::count() - count($ocultos),
                'kits' => Product::where('is_kit', true)->count(),
            ],
            // Só admin/N1 podem disparar a importação (N2 é consulta).
            'canImport' => in_array($request->user()->role, User::CRITICAL_ACTION_ROLES, true),
            'canPublish' => in_array($request->user()->role, User::CRITICAL_ACTION_ROLES, true),
            'sugestoes' => $this->sugestoesReconhece(),
        ]);
    }

    /**
     * Os tamanhos da grade de cada produto da página, numa consulta só, em
     * ordem de loja (P, M, G…).
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $produtos
     * @param  array<int, string>  $filhos  id do filho => código do pai
     * @return \Illuminate\Support\Collection<int, array<int, array<string, mixed>>> por id do pai
     */
    private function variacoesDaPagina(\Illuminate\Support\Collection $produtos, array $filhos): \Illuminate\Support\Collection
    {
        $paisPorCodigo = $produtos->keyBy(fn (Product $p) => mb_strtolower((string) $p->omie_code));
        $ids = array_keys(array_filter($filhos, fn (string $pai) => $paisPorCodigo->has($pai)));

        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(function (Product $filho) use ($filhos, $paisPorCodigo) {
                $pai = $paisPorCodigo->get($filhos[$filho->id]);

                return ['pai_id' => $pai->id] + $this->variacao($pai, $filho);
            })
            ->sortBy(fn (array $v) => [mb_strtolower((string) $v['color']), ...Variacoes::ordemDoTamanho($v['size'])])
            ->groupBy('pai_id')
            ->map(fn ($grupo) => $grupo->values()->all());
    }

    /**
     * Os tamanhos de cada grade sem pai que está na página, pela linha do
     * representante.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $produtos
     * @param  array<int, array{codigo: string, nome: string, ids: array<int, int>, representante: int}>  $representantes
     */
    private function variacoesSemPai(\Illuminate\Support\Collection $produtos, array $representantes): \Illuminate\Support\Collection
    {
        $resultado = collect();

        foreach ($produtos as $produto) {
            $grupo = $representantes[$produto->id] ?? null;

            if ($grupo === null) {
                continue;
            }

            // O pai só de memória, com o nome comum: é dele que sai o rótulo
            // de cada tamanho ("Colete G" → "G").
            $pai = new Product(['omie_code' => $grupo['codigo'], 'name' => $grupo['nome']]);

            $resultado->put($produto->id, Product::query()
                ->whereIn('id', $grupo['ids'])
                ->get()
                ->map(fn (Product $filho) => $this->variacao($pai, $filho))
                ->sortBy(fn (array $v) => [mb_strtolower((string) $v['color']), ...Variacoes::ordemDoTamanho($v['size'])])
                ->values()
                ->all());
        }

        return $resultado;
    }

    /** Um tamanho, como a lista de Produtos o mostra. */
    private function variacao(Product $pai, Product $filho): array
    {
        [$cor, $tamanho] = $pai->variacaoDoFilho($filho);

        return [
            'id' => $filho->id,
            'omie_code' => $filho->omie_code,
            'name' => $filho->name,
            'color' => $cor,
            'size' => $tamanho,
            'stock_qty' => (float) $filho->stock_qty,
            'stock_synced_at' => $filho->stock_synced_at?->toIso8601String(),
            'active' => $filho->active,
        ];
    }

    public function import(): RedirectResponse
    {
        ImportOmieCatalog::dispatch();

        return back()->with('status', 'Importação do catálogo iniciada. Os produtos aparecem aqui em instantes.');
    }

    /**
     * Publica o produto na vitrine da Reconhece.
     *
     * Síncrono, e não na fila, porque o operador está olhando: a recusa do
     * parceiro ("categoria inexistente", "falta descrição") é o desfecho mais
     * provável das primeiras tentativas, e ela só serve se chegar na tela de
     * quem pode corrigir. Numa fila, viraria um job falhado que ninguém abre.
     */
    public function publishToReconhece(
        Request $request,
        Product $product,
        PublishProductToReconhece $publica,
    ): RedirectResponse {
        abort_unless($request->user()->canPerformCriticalActions(), 403);

        $dados = $request->validate([
            'product_type' => ['required', 'string', 'max:120'],
            'department' => ['required', 'string', 'max:120'],
        ], [], [
            'product_type' => 'categoria',
            'department' => 'departamento',
        ]);

        // Grava antes de enviar: se a Reconhece recusar, o que foi digitado
        // continua lá para a próxima tentativa.
        $product->update([
            'reconhece_product_type' => trim($dados['product_type']),
            'reconhece_department' => trim($dados['department']),
        ]);

        $sku = $publica->handle($product, $dados['product_type'], $dados['department'], $request->user());

        return back()->with('status', "\"{$sku->name}\" publicado na Reconhece e já vinculado ao kit da Omie.");
    }

    /** Guarda categoria e departamento sem enviar nada à Reconhece. */
    public function saveReconheceCategories(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->canPerformCriticalActions(), 403);

        $dados = $request->validate([
            'product_type' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
        ], [], [
            'product_type' => 'categoria',
            'department' => 'departamento',
        ]);

        $product->update([
            'reconhece_product_type' => trim((string) ($dados['product_type'] ?? '')) ?: null,
            'reconhece_department' => trim((string) ($dados['department'] ?? '')) ?: null,
        ]);

        return back()->with('status', "Categorias de {$product->name} salvas.");
    }

    /**
     * Categorias e departamentos já usados em qualquer produto.
     *
     * Vão para a tela como sugestão: a Reconhece recusa nome que não bate
     * exatamente com o cadastro dela, e redigitar "Casa e Construção" em cada
     * produto é como nasce o "Casa e construçao" que ela não reconhece.
     *
     * @return array{product_types: array<int, string>, departments: array<int, string>}
     */
    private function sugestoesReconhece(): array
    {
        $distintos = fn (string $coluna) => Product::query()
            ->whereNotNull($coluna)
            ->distinct()
            ->orderBy($coluna)
            ->pluck($coluna)
            ->values()
            ->all();

        return [
            'product_types' => $distintos('reconhece_product_type'),
            'departments' => $distintos('reconhece_department'),
        ];
    }

    public function edit(Request $request, Product $product): Response
    {
        $product->load('partnerSku:id,product_id,sku,product_type,department,enabled', 'photos');

        $principal = Product::principais([$product])[$product->id];

        return Inertia::render('Products/Edit', [
            'product' => [
                'id' => $product->id,
                'omie_code' => $product->omie_code,
                'name' => $product->name,
                'description' => $product->description,
                'description_edited' => $product->description_edited_at !== null,
                // Tamanho de grade não tem galeria: a tela aponta o item
                // principal, que é onde as fotos moram.
                'galeria' => $principal->is($product) ? $product->galeriaParaTela() : null,
                'item_principal' => $principal->is($product) ? null : [
                    'id' => $principal->exists ? $principal->id : null,
                    'name' => $principal->name,
                    'omie_code' => $principal->omie_code,
                ],
                'is_kit' => $product->is_kit,
                'active' => $product->active,
                'stock_qty' => (float) $product->stock_qty,
                'stock_synced_at' => $product->stock_synced_at?->toIso8601String(),
                // Publicado ou não: decide o selo e o texto do botão.
                'reconhece' => $product->partnerSku ? [
                    'enabled' => $product->partnerSku->enabled,
                ] : null,
                // O que está salvo no produto, publicado ou não. Cai no que foi
                // publicado para quem enviou antes de as categorias morarem aqui.
                'categorias' => [
                    'product_type' => $product->reconhece_product_type ?? $product->partnerSku?->product_type,
                    'department' => $product->reconhece_department ?? $product->partnerSku?->department,
                ],
                'em_uso' => $this->emUso($product),
                // Opções de cor/tamanho que o colaborador vai ver no portal:
                // SKUs do catálogo do parceiro com este código como parent code.
                'opcoes' => $product->opcoesDeVariacao()->map(fn ($s) => [
                    'sku' => $s->sku,
                    'color' => $s->color,
                    'size' => $s->size,
                ])->values(),
            ],
            'sugestoes' => $this->sugestoesReconhece(),
            'canEdit' => $request->user()->canPerformCriticalActions(),
        ]);
    }

    /**
     * Só a descrição é editável, e o motivo é o espelho da Omie.
     *
     * Nome, estoque e situação são reescritos a cada importação: editá-los aqui
     * seria prometer uma mudança que some sozinha no próximo sync. A descrição
     * é a exceção porque a Omie quase nunca a tem e a Reconhece a exige — e,
     * uma vez editada, o espelho passa a respeitá-la.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->canPerformCriticalActions(), 403);

        $dados = $request->validate([
            'description' => ['nullable', 'string', 'max:5000'],
        ], [], ['description' => 'descrição']);

        $descricao = trim((string) ($dados['description'] ?? '')) ?: null;

        $product->update([
            'description' => $descricao,
            // Apagar a descrição devolve o campo à Omie: é o jeito de desfazer
            // a edição sem precisar de outro botão.
            'description_edited_at' => $descricao === null ? null : now(),
        ]);

        return back()->with('status', "Produto {$product->name} atualizado.");
    }

    /**
     * Apaga o produto da WeLog — só quando nada depende dele.
     *
     * Projetos, autorizações e resgates apontam para o produto sem cascata, de
     * propósito: apagar levaria junto o histórico de quem recebeu o quê. Aqui
     * a recusa vem com o motivo, em vez de um erro de banco.
     *
     * E o aviso que importa: produto ativo na Omie VOLTA na próxima
     * importação. Apagar aqui não o tira do catálogo de origem.
     */
    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->canPerformCriticalActions(), 403);

        if (($motivo = $this->emUso($product)) !== null) {
            return back()->withErrors(['product' => "Não dá para excluir {$product->name}: {$motivo}."]);
        }

        $nome = $product->name;
        $product->delete();

        return redirect()->route('products.index')->with(
            'status',
            "{$nome} excluído. Se ele continuar ativo na Omie, volta na próxima importação.",
        );
    }

    /** O que impede excluir, em palavras. Null quando nada depende do produto. */
    private function emUso(Product $product): ?string
    {
        $projetos = DB::table('project_products')->where('product_id', $product->id)->count();

        if ($projetos > 0) {
            return $projetos === 1 ? 'ele está em 1 Projeto' : "ele está em {$projetos} Projetos";
        }

        if (ProjectEntitlement::where('product_id', $product->id)->exists()) {
            return 'há Colaboradores autorizados a ele';
        }

        if (Redemption::where('product_id', $product->id)->exists()) {
            return 'há resgates dele';
        }

        return null;
    }
}
