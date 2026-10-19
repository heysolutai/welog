<?php

namespace App\Services\FluxoB;

use App\Models\PartnerSku;
use App\Models\Product;

/**
 * Registra o item que o parceiro cadastrou e tenta amarrá-lo ao produto da
 * Omie na mesma passada.
 *
 * A amarração automática existe porque o caso comum é o código ser o mesmo dos
 * dois lados — quem cadastra na Reconhece copia o código da Omie. Deixar tudo
 * para amarração manual faria alguém reconfirmar o óbvio centenas de vezes, e
 * é assim que a tela deixa de ser olhada.
 *
 * O que NÃO casa sozinho fica com `product_id` nulo, em destaque na tela. Esse
 * é o estado que custa caro: resgate de SKU sem vínculo trava em "produto não
 * cadastrado", e descobrir isso no meio da campanha é o pior momento possível.
 */
class RegisterPartnerSku
{
    /**
     * @param  array<string, mixed>  $payload  um item no formato do parceiro
     */
    public function handle(array $payload, string $source = PartnerSku::SOURCE_RECONHECE): ?PartnerSku
    {
        $sku = trim((string) ($payload['sku'] ?? ''));

        if ($sku === '') {
            return null;
        }

        $produto = $payload['product'] ?? [];

        $registro = PartnerSku::firstOrNew(['source' => $source, 'sku' => $sku]);

        $registro->fill([
            // Ausente é diferente de `false`: só um "enabled": false explícito
            // tira o item da vitrine.
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'sku_name' => $this->texto($payload['skuName'] ?? null),
            'color' => $this->texto($payload['color'] ?? null),
            'size' => $this->texto($payload['size'] ?? null),
            'voltage' => $this->texto($payload['voltage'] ?? null),

            // Sem `parentCode`, o item é dele mesmo: é como o contrato do
            // parceiro descreve produto avulso, e evita uma coluna nula que
            // depois viraria caso especial em toda consulta.
            'parent_code' => $this->texto($produto['parentCode'] ?? null) ?? $sku,
            'name' => $this->texto($produto['name'] ?? null) ?? $sku,
            'description' => $this->texto($produto['description'] ?? null),
            'product_type' => $this->texto($produto['productType'] ?? null),
            'department' => $this->texto($produto['department'] ?? null),
            'images' => $this->imagens($payload['images'] ?? null),
            'payload' => $payload,
        ]);

        /*
         * Reenvio não desfaz amarração feita à mão.
         *
         * O parceiro reenvia o catálogo inteiro de tempos em tempos, e quem
         * amarrou um SKU de código divergente fez isso porque o automático não
         * dava conta. Recalcular a cada reenvio apagaria justamente o trabalho
         * que ninguém quer refazer.
         */
        if ($registro->product_id === null) {
            $registro->product_id = $this->produtoDaOmie($sku, $registro->parent_code)?->id;
            $registro->linked_at = $registro->product_id === null ? null : now();
        }

        $registro->save();

        return $registro;
    }

    /**
     * Procura pelo código do SKU e, em seguida, pelo do produto-pai.
     *
     * A segunda tentativa cobre o catálogo com variação: lá, o código da Omie
     * costuma ser o do produto, e cada cor vira um SKU derivado dele. Sem ela,
     * nenhum item com variação casaria sozinho.
     */
    private function produtoDaOmie(string $sku, string $parentCode): ?Product
    {
        return Product::whereRaw('LOWER(omie_code) = ?', [mb_strtolower($sku)])->first()
            ?? ($parentCode !== $sku
                ? Product::whereRaw('LOWER(omie_code) = ?', [mb_strtolower($parentCode)])->first()
                : null);
    }

    private function texto(mixed $valor): ?string
    {
        $limpo = trim((string) (is_scalar($valor) ? $valor : ''));

        return $limpo === '' ? null : $limpo;
    }

    /** @return array<int, string>|null */
    private function imagens(mixed $valor): ?array
    {
        $urls = array_values(array_filter(
            array_map(fn ($u) => trim((string) (is_scalar($u) ? $u : '')), (array) $valor),
            fn (string $u) => $u !== '',
        ));

        return $urls === [] ? null : $urls;
    }
}
