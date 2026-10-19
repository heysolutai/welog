<?php

namespace App\Services\Catalog;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Espelha um produto da Omie (fonte da verdade) no catálogo local,
 * incluindo a estrutura BOM quando for kit (módulo 5).
 *
 * ── Kit dentro de kit ───────────────────────────────────────────────────────
 *
 * Um componente também pode ser kit, e a estrutura DELE só vem num
 * `ConsultarProduto` próprio: o retorno do kit pai traz os filhos, nunca os
 * netos. Enquanto o componente entrava no catálogo como folha, a tela do
 * Projeto mostrava um nível só e um sub-kit parecia item simples. Por isso o
 * espelho desce a árvore — com teto de profundidade, corte de ciclo e
 * reaproveitamento do que já foi conferido, porque cada nível custa chamada
 * na Omie (que freia por consumo repetido).
 */
class MirrorOmieProduct
{
    /**
     * Até onde o espelho desce. Três níveis cobrem o que a operação monta
     * (kit de campanha → kit de item → peça) e impede que um cadastro torto
     * na Omie vire uma varredura sem fim.
     */
    private const MAX_DEPTH = 3;

    /**
     * Códigos em espelhamento AGORA, do topo até o nível atual.
     *
     * Serve para duas coisas: medir a profundidade e cortar ciclo — kit que
     * se contém, direta ou indiretamente, existe em cadastro real e sem isto
     * a recursão não teria fim.
     *
     * @var array<int, string>
     */
    private array $stack = [];

    public function __construct(
        private readonly OmieAccounts $accounts,
        /** Gateway fixo, para quem monta o serviço à mão (testes). */
        private readonly ?OmieGateway $omie = null,
    ) {}

    /**
     * Códigos já consultados nesta execução.
     *
     * Sem isso, a reconciliação horária consultava o MESMO kit uma vez por
     * pedido: 70 pedidos com os mesmos 8 kits viram 560 chamadas idênticas em
     * segundos, e a Omie responde `Consumo redundante detectado. Aguarde 51
     * segundos` — que é exatamente a proteção contra chamada repetida. O
     * espelho do produto não muda entre dois pedidos do mesmo lote.
     *
     * @var array<string, ?Product>
     */
    private array $seen = [];

    /** Devolve o produto local espelhado, ou null se não cadastrado na Omie. */
    public function handle(string $omieCode, ?string $account = null): ?Product
    {
        // O catalogo tambem e por CNPJ: quem enxerga o produto e a chave da
        // conta do pedido. A memoria de codigos ja vistos passa a ser por conta.
        $chave = ($account ?? '').'|'.$omieCode;

        if (array_key_exists($chave, $this->seen)) {
            return $this->seen[$chave];
        }

        $remote = ($this->omie ?? $this->accounts->gateway($account))->findProductByCode($omieCode);

        return $this->seen[$chave] = $remote === null ? null : $this->mirror($remote, $account);
    }

    /** Espelha o catálogo completo (sync inicial / agendado). */
    public function syncAll(): int
    {
        $count = 0;
        $page = 1;

        do {
            ['items' => $items, 'total_pages' => $totalPages] = ($this->omie ?? $this->accounts->gateway())->listProducts($page);

            foreach ($items as $remote) {
                $this->mirror($remote);
                $count++;
            }

            $page++;
        } while ($page <= $totalPages);

        return $count;
    }

    private function mirror(array $remote, ?string $account = null): Product
    {
        $atributos = [
            'name' => $remote['name'] ?? $remote['descricao'] ?? 'Produto sem nome',
            'description' => $remote['description'] ?? $remote['descr_detalhada'] ?? null,
            'image_url' => $remote['image_url'] ?? $remote['url_imagem'] ?? null,
            'is_kit' => (bool) ($remote['is_kit'] ?? false),
            'active' => ($remote['inativo'] ?? 'N') !== 'S',
        ];

        // Características ("Tamanho Corta Vento: PP"): é delas que sai o
        // tamanho de cada variação da grade. Só grava quando a resposta traz o
        // campo — a listagem só o manda com `exibir_caracteristicas`, e a
        // ausência ali não quer dizer que o produto não tem nenhuma.
        if (array_key_exists('caracteristicas', $remote)) {
            $atributos['caracteristicas'] = self::caracteristicas($remote['caracteristicas']) ?: null;
        }

        // Só carimba quando a resposta REALMENTE traz estrutura (consulta por
        // código). A importação em lote passa por aqui sem esse dado — marcar
        // ali seria registrar uma conferência que não aconteceu.
        if ($remote['structure_checked'] ?? false) {
            $atributos['structure_synced_at'] = now();
        }

        $codigo = $remote['omie_code'] ?? $remote['codigo'];

        /*
         * Descrição editada na WeLog não é reescrita.
         *
         * Quem edita aqui edita porque a da Omie não servia — quase sempre
         * porque não existia, e a Reconhece não aceita produto sem descrição.
         * Reescrever a cada importação apagaria esse trabalho em silêncio, e o
         * sintoma só apareceria na próxima publicação recusada.
         */
        if (Product::where('omie_code', $codigo)->whereNotNull('description_edited_at')->exists()) {
            unset($atributos['description']);
        }

        $product = Product::updateOrCreate(['omie_code' => $codigo], $atributos);

        // Estrutura BOM: mapa componente => quantidade
        $components = $remote['components'] ?? [];
        $names = $remote['component_names'] ?? [];
        if ($components !== []) {
            $sync = [];
            $this->stack[] = (string) $product->omie_code;
            try {
                foreach ($components as $componentCode => $quantity) {
                    $component = $this->mirrorComponent(
                        (string) $componentCode,
                        $names[$componentCode] ?? null,
                        $account,
                    );
                    $sync[$component->id] = ['quantity' => $quantity];
                }
            } finally {
                array_pop($this->stack);
            }
            $product->components()->sync($sync);
        }

        return $product;
    }

    /**
     * As características como a Omie manda (`cNomeCaract`, `cConteudo`), no
     * formato do catálogo: [{nome, conteudo}], sem as vazias.
     *
     * @return array<int, array{nome: string, conteudo: string}>
     */
    private static function caracteristicas(mixed $lista): array
    {
        return collect(is_array($lista) ? $lista : [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'nome' => trim((string) ($item['cNomeCaract'] ?? $item['nome'] ?? '')),
                'conteudo' => trim((string) ($item['cConteudo'] ?? $item['conteudo'] ?? '')),
            ])
            ->filter(fn (array $item) => $item['conteudo'] !== '')
            ->values()
            ->all();
    }

    /**
     * Espelha UM componente da estrutura, descendo nele quando também é kit.
     *
     * A descida é o que dá profundidade à árvore, e é também o que custa
     * chamada — por isso três freios, nesta ordem:
     *
     *   1. componente já conferido antes (`structure_synced_at`) sai do banco
     *      sem tocar na Omie: o custo é pago uma vez por produto, não uma vez
     *      por Projeto que o usa;
     *   2. ciclo (kit que se contém) e teto de profundidade cortam a recursão;
     *   3. falha na consulta (produto fora do cadastro, throttle) degrada para
     *      folha em vez de derrubar o espelho do kit inteiro — meia estrutura
     *      é melhor que Projeto nenhum, e o `welog:omie:sync-kit-structure`
     *      conserta depois.
     */
    private function mirrorComponent(string $code, ?string $name, ?string $account): Product
    {
        $local = Product::where('omie_code', $code)->first();
        if ($local !== null && $local->structure_synced_at !== null) {
            return $local;
        }
        $podeDescer = count($this->stack) < self::MAX_DEPTH
            && ! in_array($code, $this->stack, true);
        if ($podeDescer) {
            try {
                $espelhado = $this->handle($code, $account);
            } catch (Throwable $e) {
                Log::channel('integrations')->warning('Componente de kit não pôde ser espelhado; entra como item simples.', [
                    'componente' => $code,
                    'dentro_de' => end($this->stack) ?: null,
                    'exception' => $e->getMessage(),
                ]);
                $espelhado = null;
            }
            // A resposta precisa ser do produto que foi pedido. Consulta que
            // devolve outro código (cadastro torto, retorno genérico do ERP)
            // trocaria o componente do kit por outra coisa — e um kit montado
            // com a peça errada é pior que um kit sem estrutura.
            if ($espelhado !== null && (string) $espelhado->omie_code === $code) {
                return $espelhado;
            }
        }

        return $local ?? Product::firstOrCreate(
            ['omie_code' => $code],
            ['name' => $name ?? $code],
        );
    }
}
