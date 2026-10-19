<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tirar itens do Projeto (e devolvê-los), à mão, pelo Admin WeGift.
 *
 * Só sai item SEM resgate: o que alguém já pediu está em produção, a caminho
 * ou entregue, e tirar o item apagaria o rastro disso. Resgate cancelado não
 * conta, porque não vai ser produzido.
 *
 * Saem junto as autorizações do item: o colaborador deixa de ter aquele item
 * para pedir. E o item fica lembrado em `removed_items`, com a quantidade e o
 * saldo que tinha: a próxima alteração do pedido na Omie não o traz de volta,
 * e restaurar devolve o item como estava.
 */
class RemoveProjectItems
{
    /**
     * @param  array<int, int>  $productIds  um item, ou todos os tamanhos de uma grade
     * @return array{itens: int, autorizacoes: int}
     */
    public function remover(Project $project, array $productIds, User $actor): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $itens = $project->products()->whereIn('products.id', $productIds)->get();

        if ($productIds === [] || $itens->count() !== count($productIds)) {
            throw new DomainException('Este item não está no Projeto.');
        }

        $comResgate = Redemption::query()
            ->where('project_id', $project->id)
            ->whereIn('product_id', $productIds)
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            ->exists();

        if ($comResgate) {
            throw new DomainException('Este item já tem resgate. Só dá para remover item que ninguém pediu.');
        }

        return DB::transaction(function () use ($project, $itens, $productIds, $actor) {
            $autorizacoes = ProjectEntitlement::query()
                ->where('project_id', $project->id)
                ->whereIn('product_id', $productIds)
                ->delete();

            $removidos = (array) ($project->removed_items ?? []);

            foreach ($itens as $item) {
                $removidos[mb_strtolower((string) $item->omie_code)] = [
                    'product_id' => $item->id,
                    'omie_code' => $item->omie_code,
                    'name' => $item->name,
                    'quantity' => (int) $item->pivot->quantity,
                    'stock_qty' => $item->pivot->stock_qty,
                    'stock_loaded_at' => $item->pivot->stock_loaded_at,
                    'removed_at' => now()->toIso8601String(),
                    'removed_by' => $actor->name,
                ];
            }

            $project->products()->detach($productIds);
            $project->forceFill(['removed_items' => $removidos])->save();
            $this->recontar($project);

            return ['itens' => count($productIds), 'autorizacoes' => $autorizacoes];
        });
    }

    /** Devolve ao Projeto um item removido, com a quantidade e o saldo que tinha. */
    public function restaurar(Project $project, string $codigo): string
    {
        $codigo = mb_strtolower($codigo);
        $removidos = (array) ($project->removed_items ?? []);
        $item = $removidos[$codigo] ?? null;

        if ($item === null) {
            throw new DomainException('Este item não foi removido deste Projeto.');
        }

        DB::transaction(function () use ($project, $removidos, $codigo, $item) {
            $project->products()->syncWithoutDetaching([
                $item['product_id'] => [
                    'quantity' => $item['quantity'],
                    'stock_qty' => $item['stock_qty'] ?? null,
                    'stock_loaded_at' => $item['stock_loaded_at'] ?? null,
                ],
            ]);

            unset($removidos[$codigo]);
            $project->forceFill(['removed_items' => $removidos ?: null])->save();
            $this->recontar($project);
        });

        return (string) $item['name'];
    }

    /** A quantidade do Projeto é a soma dos itens que ficaram. */
    private function recontar(Project $project): void
    {
        $project->forceFill([
            'quantity' => (int) DB::table('project_products')->where('project_id', $project->id)->sum('quantity'),
        ])->save();
    }
}
