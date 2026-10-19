<?php

namespace App\Http\Controllers;

use App\Models\PartnerSku;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O catálogo como o parceiro o enxerga, e a amarração dele ao produto da Omie.
 *
 * A tela existe por um motivo só, e ele é caro: SKU sem vínculo trava o resgate
 * em "produto não cadastrado", e isso aparece no meio da campanha, com o
 * colaborador do outro lado esperando. Aqui dá para ver a lista de pendentes
 * ANTES de a campanha rodar, que é a única hora em que corrigir é barato.
 *
 * Por isso a tela abre filtrada nos sem vínculo: é a pergunta que se vem
 * responder aqui, e o catálogo inteiro só interessa depois.
 */
class PartnerSkuController extends Controller
{
    public function index(Request $request): Response
    {
        $busca = trim((string) $request->query('q', ''));
        $filtro = $request->query('status', 'pendentes');

        $skus = PartnerSku::query()
            ->with('product:id,omie_code,name')
            ->when($filtro === 'pendentes', fn ($q) => $q->semVinculo())
            ->when($filtro === 'vinculados', fn ($q) => $q->whereNotNull('product_id'))
            ->when($busca !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('sku', 'ilike', "%{$busca}%")
                ->orWhere('name', 'ilike', "%{$busca}%")
                ->orWhere('parent_code', 'ilike', "%{$busca}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PartnerSku $s) => [
                'id' => $s->id,
                'sku' => $s->sku,
                'name' => $s->name,
                'variacao' => $s->variacao(),
                'parent_code' => $s->parent_code,
                'avulso' => $s->isAvulso(),
                'product_type' => $s->product_type,
                'department' => $s->department,
                'enabled' => $s->enabled,
                'image' => $s->images[0] ?? null,
                'product' => $s->product ? [
                    'id' => $s->product->id,
                    'omie_code' => $s->product->omie_code,
                    'name' => $s->product->name,
                ] : null,
            ]);

        return Inertia::render('Products/PartnerCatalog', [
            'skus' => $skus,
            'filters' => ['q' => $busca, 'status' => $filtro],
            'summary' => [
                'total' => PartnerSku::count(),
                'pendentes' => PartnerSku::semVinculo()->count(),
            ],
            /*
             * O catálogo da Omie inteiro vai junto para o seletor.
             *
             * Só o código e o nome, e sem paginar: quem amarra precisa
             * PROCURAR pelo nome, porque o código do parceiro não ajuda em
             * nada — se ajudasse, o vínculo automático já teria resolvido.
             */
            'products' => Product::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'omie_code', 'name'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'omie_code' => $p->omie_code,
                    'name' => $p->name,
                ]),
            'canLink' => in_array($request->user()->role, User::CRITICAL_ACTION_ROLES, true),
        ]);
    }

    /** Amarra, ou desfaz quando `product_id` vem nulo. */
    public function link(Request $request, PartnerSku $partnerSku): RedirectResponse
    {
        abort_unless($request->user()->canPerformCriticalActions(), 403);

        $dados = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $produtoId = $dados['product_id'] ?? null;

        $partnerSku->update([
            'product_id' => $produtoId,
            'linked_at' => $produtoId === null ? null : now(),
            'linked_by' => $produtoId === null ? null : $request->user()->id,
        ]);

        return back()->with('status', $produtoId === null
            ? "Vínculo do SKU {$partnerSku->sku} desfeito."
            : "SKU {$partnerSku->sku} vinculado. Resgates dele já sabem qual kit expedir.");
    }
}
