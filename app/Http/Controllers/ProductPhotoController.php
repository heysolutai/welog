<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Services\Catalog\ProductGallery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Galeria de fotos do item principal, enviada da aba Itens do pedido de um
 * Projeto ou da tela do Produto. Equipe WeGift (rota em `role:critical`).
 */
class ProductPhotoController extends Controller
{
    public function store(Request $request, ProductGallery $galeria): RedirectResponse
    {
        $request->validate([
            // Pelo id, ou pelo código do pai que só existe de memória.
            'product_id' => ['nullable', 'integer', 'required_without:grade_code'],
            'grade_code' => ['nullable', 'string', 'max:60', 'required_without:product_id'],
            'photos' => ['required', 'array', 'min:1', 'max:'.Product::MAX_FOTOS],
            // `dimensions` barra o SVG, que aceita script embutido.
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=200,min_height=200'],
        ], [
            'photos.max' => 'Envie até '.Product::MAX_FOTOS.' fotos por item.',
            'photos.*.max' => 'Cada imagem deve ter no máximo 4 MB.',
            'photos.*.dimensions' => 'Cada imagem deve ter pelo menos 200×200 pixels.',
            'photos.*.mimes' => 'Use JPG, PNG ou WEBP.',
            'photos.*.image' => 'Use JPG, PNG ou WEBP.',
        ], ['photos' => 'fotos']);

        try {
            $produto = $galeria->alvo($request->integer('product_id') ?: null, $request->input('grade_code'));
            $total = $galeria->adicionar($produto, $request->file('photos'));
        } catch (DomainException $e) {
            return back()->withErrors(['photos' => $e->getMessage()]);
        }

        return back()->with('status', "Fotos de {$produto->name}: {$total} de ".Product::MAX_FOTOS.'.');
    }

    public function reorder(Request $request, Product $product, ProductGallery $galeria): RedirectResponse
    {
        $dados = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        try {
            $galeria->ordenar($product, $dados['ids']);
        } catch (DomainException $e) {
            return back()->withErrors(['photos' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(ProductPhoto $photo, ProductGallery $galeria): RedirectResponse
    {
        $galeria->remover($photo);

        return back()->with('status', 'Foto removida.');
    }
}
