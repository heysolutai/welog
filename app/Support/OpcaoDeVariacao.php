<?php

namespace App\Support;

/**
 * Uma opção de cor/tamanho de um produto pai, venha de onde vier.
 *
 * Duas origens produzem opções: a grade da própria Omie (os produtos
 * PRD11361_1, _2, _3 do pai PRD11361) e o catálogo do parceiro (SKUs da
 * Reconhece com o mesmo parent code). Quem mostra (portal, ficha do produto)
 * e quem confere (o resgate) não precisam saber qual das duas foi.
 *
 * `id` é o da linha de origem: o produto, na grade; o SKU, no catálogo. Um
 * produto só usa uma origem por vez, então os ids não se misturam.
 */
final class OpcaoDeVariacao
{
    public const ORIGEM_OMIE = 'omie';

    public const ORIGEM_PARCEIRO = 'parceiro';

    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly ?string $color,
        public readonly ?string $size,
        public readonly ?string $image,
        public readonly string $origem,
    ) {}
}
