<?php

namespace App\Integrations\Reconhece;

use App\Contracts\Integrations\ReconheceGateway;

/**
 * Catálogo da Reconhece em memória (driver 'fake').
 *
 * Guarda o que foi publicado para que o teste possa afirmar o que subiu — e,
 * mais importante, COM QUE FORMA subiu. O contrato deles tem campo obrigatório
 * aninhado (`product.parentCode`, `product.department`), e é justamente esse
 * tipo de detalhe que quebra em silêncio quando o teste só verifica se a
 * chamada aconteceu.
 */
class ReconheceFakeGateway implements ReconheceGateway
{
    /** @var array<string, array<string, mixed>> */
    private array $catalogo = [];

    public function upsertSku(array $payload): array
    {
        $sku = (string) ($payload['sku'] ?? '');

        /*
         * Recusa o que o contrato deles recusaria.
         *
         * Um fake que aceita tudo transforma "passou nos testes" em promessa
         * vazia: o erro aparece na primeira chamada real, contra a API do
         * parceiro, que é onde ninguém quer descobrir.
         */
        $faltando = $this->obrigatoriosFaltando($payload);

        if ($faltando !== []) {
            return [
                'sku' => $sku,
                'accepted' => false,
                'message' => 'Campos obrigatórios ausentes: '.implode(', ', $faltando).'.',
            ];
        }

        $this->catalogo[$sku] = $payload;

        return ['sku' => $sku, 'accepted' => true, 'message' => null];
    }

    /** @return array<int, string> */
    private function obrigatoriosFaltando(array $payload): array
    {
        $produto = $payload['product'] ?? [];

        $exigidos = [
            'sku' => $payload['sku'] ?? null,
            'product.parentCode' => $produto['parentCode'] ?? null,
            'product.name' => $produto['name'] ?? null,
            'product.description' => $produto['description'] ?? null,
            'product.productType' => $produto['productType'] ?? null,
            'product.department' => $produto['department'] ?? null,
        ];

        return array_keys(array_filter($exigidos, fn ($v) => blank($v)));
    }

    /** @return array<string, mixed>|null */
    public function catalogItem(string $sku): ?array
    {
        return $this->catalogo[$sku] ?? null;
    }

    public function catalogCount(): int
    {
        return count($this->catalogo);
    }
}
