<?php

namespace App\Services\Catalog;

use App\Exceptions\DomainException;
use App\Models\Product;
use App\Models\ProductPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A galeria de fotos do item principal: até 10, em ordem, a primeira é a capa.
 *
 * As fotos pertencem ao PRODUTO, não ao Projeto: o mesmo item aparece em vários
 * Projetos e no portal do Colaborador, e todos mostram a mesma galeria.
 *
 * Só o item principal tem galeria. Tamanho de grade (PRD11361_2) usa a do pai,
 * e enviar foto para ele é recusado: era o que deixava cada tamanho com uma
 * foto diferente e o colaborador sem saber qual era o produto.
 *
 * Grava em `product_photos`, nunca em `image_url`: esse é reescrito a cada
 * sincronização com a Omie e apagaria o envio manual.
 */
class ProductGallery
{
    private const DIRECTORY = 'kits';

    /**
     * O produto que recebe as fotos: pelo id ou, para o pai que só existe de
     * memória (a Omie tem só os tamanhos), pelo código, que o cadastra.
     */
    public function alvo(?int $productId, ?string $codigoDoPai): Product
    {
        $produto = $productId !== null
            ? Product::find($productId)
            : Product::materializarPaiDaGrade((string) $codigoDoPai);

        if ($produto === null) {
            throw new DomainException('Item não encontrado.');
        }

        if ($produto->eTamanhoDeGrade()) {
            throw new DomainException('Os tamanhos usam as fotos do item principal. Envie as fotos no item de cima.');
        }

        return $produto;
    }

    /**
     * @param  array<int, UploadedFile>  $arquivos
     * @return int quantas fotos a galeria ficou
     */
    public function adicionar(Product $produto, array $arquivos): int
    {
        $atual = $produto->photos()->count();

        if ($atual + count($arquivos) > Product::MAX_FOTOS) {
            $cabem = Product::MAX_FOTOS - $atual;

            throw new DomainException($cabem > 0
                ? "Cabem mais {$cabem} ".($cabem === 1 ? 'foto' : 'fotos').' neste item (até '.Product::MAX_FOTOS.').'
                : 'Este item já tem '.Product::MAX_FOTOS.' fotos. Remova alguma para enviar outra.');
        }

        $posicao = (int) $produto->photos()->max('position') + ($atual > 0 ? 1 : 0);

        foreach ($arquivos as $arquivo) {
            $produto->photos()->create([
                'path' => $arquivo->store(self::DIRECTORY, ProductPhoto::DISK),
                'position' => $posicao++,
            ]);
        }

        return $atual + count($arquivos);
    }

    public function remover(ProductPhoto $foto): void
    {
        $produto = $foto->product;

        DB::transaction(function () use ($foto, $produto) {
            $foto->delete();
            $this->renumerar($produto, $produto->photos()->pluck('id')->all());
        });

        // Só depois de sair do banco: se o disco falhar, sobra um arquivo
        // órfão, nunca uma foto quebrada na tela.
        Storage::disk(ProductPhoto::DISK)->delete($foto->path);
    }

    /**
     * Nova ordem da galeria. A primeira vira a capa.
     *
     * @param  array<int, int>  $ids
     */
    public function ordenar(Product $produto, array $ids): void
    {
        $existentes = $produto->photos()->pluck('id')->all();

        // Ordem tem de citar exatamente as fotos do item: id de outro produto
        // ou uma foto esquecida deixariam a galeria com buraco na sequência.
        $ids = array_map('intval', $ids);
        if (count($ids) !== count($existentes) || array_diff($existentes, $ids) !== []) {
            throw new DomainException('A galeria mudou enquanto você reorganizava. Atualize a página.');
        }

        DB::transaction(fn () => $this->renumerar($produto, $ids));
    }

    /** @param  array<int, int>  $ids */
    private function renumerar(Product $produto, array $ids): void
    {
        foreach (array_values($ids) as $posicao => $id) {
            ProductPhoto::whereKey($id)->where('product_id', $produto->id)->update(['position' => $posicao]);
        }
    }
}
