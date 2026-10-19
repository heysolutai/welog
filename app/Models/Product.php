<?php

namespace App\Models;

use App\Support\OpcaoDeVariacao;
use App\Support\Variacoes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'omie_code', 'name', 'description', 'description_edited_at', 'image_url', 'image_path',
        'reconhece_product_type', 'reconhece_department',
        'is_kit', 'active', 'stock_qty', 'stock_synced_at', 'structure_synced_at',
        'caracteristicas',
    ];

    protected function casts(): array
    {
        return [
            'is_kit' => 'boolean',
            'active' => 'boolean',
            'stock_qty' => 'decimal:2',
            'stock_synced_at' => 'datetime',
            'structure_synced_at' => 'datetime',
            'description_edited_at' => 'datetime',
            'caracteristicas' => 'array',
        ];
    }

    /** Fotos máximas na galeria de um produto. */
    public const MAX_FOTOS = 10;

    /** A galeria enviada pela WeGift, na ordem: a primeira é a capa. */
    public function photos(): HasMany
    {
        return $this->hasMany(ProductPhoto::class)->orderBy('position')->orderBy('id');
    }

    /**
     * As fotos que a tela mostra, na ordem: a galeria enviada pela WeGift ou,
     * sem ela, a imagem espelhada da Omie. A enviada vence porque quem subiu
     * fez isso justamente por a do ERP não servir (ou não existir).
     *
     * Num tamanho de grade, use as do item principal (`principais`): os
     * tamanhos não têm fotos próprias.
     *
     * @return array<int, string>
     */
    public function fotos(): array
    {
        $galeria = $this->photos->map(fn (ProductPhoto $f) => $f->url)->all();

        if ($galeria !== []) {
            return $galeria;
        }

        return filled($this->image_url) ? [$this->image_url] : [];
    }

    /**
     * A galeria deste item principal, para as telas da WeGift enviarem,
     * ordenarem e removerem fotos.
     *
     * Pai de memória (a Omie tem só os tamanhos) não tem id: o envio vai pelo
     * código, e a primeira foto o cadastra.
     *
     * @return array{product_id: ?int, grade_code: ?string, name: string, omie_code: ?string, fotos: array<int, array{id: int, url: string}>, imagem_omie: ?string}
     */
    public function galeriaParaTela(): array
    {
        return [
            'product_id' => $this->exists ? $this->id : null,
            'grade_code' => $this->exists ? null : $this->omie_code,
            'name' => (string) $this->name,
            'omie_code' => $this->omie_code,
            'fotos' => $this->photos->map(fn (ProductPhoto $f) => ['id' => $f->id, 'url' => $f->url])->values()->all(),
            // A imagem da Omie, que vale enquanto não há foto enviada.
            'imagem_omie' => $this->image_url ?: null,
        ];
    }

    /** A capa: a primeira das `fotos()`. */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->fotos()[0] ?? null);
    }

    /**
     * O item correspondente no catálogo do parceiro, quando existe.
     *
     * `hasOne` porque hoje só há a Reconhece. Com um segundo parceiro isto
     * vira `hasMany` e quem consome escolhe de qual origem quer falar.
     */
    public function partnerSku(): HasOne
    {
        return $this->hasOne(PartnerSku::class);
    }

    /** Componentes da estrutura BOM (quando este produto é um kit). */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'kit_items', 'kit_product_id', 'component_product_id')
            ->withPivot('quantity');
    }

    /**
     * Kits que CONTÊM este produto — inverso de `components()`.
     *
     * É por aqui que a varredura de estrutura alcança o sub-kit: ele não está
     * ligado a Projeto nenhum, só ao kit que o carrega.
     */
    public function kits(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'kit_items', 'component_product_id', 'kit_product_id')
            ->withPivot('quantity');
    }

    /**
     * As opções de cor/tamanho deste produto pai.
     *
     * O pedido traz só o pai ("Jaqueta" × 20) e o saldo é dele; cada
     * colaborador escolhe uma destas opções. Duas origens, nesta ordem:
     *
     *  1. a grade da própria Omie — os produtos PRD11361_1, _2, _3 do pai
     *     PRD11361, com cor/tamanho lidos do fim do nome. É a fonte da
     *     verdade quando existe: é o cadastro de quem expede;
     *  2. o catálogo do parceiro — SKUs da Reconhece com `parent_code` igual
     *     ao código do pai. O SKU avulso (`sku` = parent code) é o próprio
     *     pai, não uma opção.
     *
     * Vazio = produto sem variação, resgatado como sempre.
     *
     * @return \Illuminate\Support\Collection<int, OpcaoDeVariacao>
     */
    public function opcoesDeVariacao(): \Illuminate\Support\Collection
    {
        return self::opcoesDeVariacaoEmLote([$this])[mb_strtolower((string) $this->omie_code)] ?? collect();
    }

    /**
     * As opções de cor/tamanho de vários produtos de uma vez (ver
     * opcoesDeVariacao), com um número fixo de consultas.
     *
     * O portal pedia as opções presente a presente: uma busca na grade, uma
     * no catálogo do parceiro e uma de fotos por tamanho — dezenas de
     * consultas por tela, e mil pessoas abrindo o portal ao mesmo tempo
     * multiplicavam isso. Aqui são três, com um presente ou com nove:
     *
     *  1. a grade da Omie de todos: os `<código>_<n>` ativos, com as fotos.
     *     Filho cujo nome não diz cor nem tamanho fica de fora — não há o que
     *     mostrar no seletor;
     *  2. o catálogo do parceiro dos que não têm grade. O SKU avulso (`sku` =
     *     parent code) é o próprio pai, não uma opção.
     *
     * @param  iterable<Product|null>  $produtos
     * @return array<string, \Illuminate\Support\Collection<int, OpcaoDeVariacao>> código em minúsculas => opções
     */
    public static function opcoesDeVariacaoEmLote(iterable $produtos): array
    {
        $pais = collect($produtos)
            ->filter(fn (?self $p) => $p !== null && filled($p->omie_code))
            ->keyBy(fn (self $p) => mb_strtolower((string) $p->omie_code));

        if ($pais->isEmpty()) {
            return [];
        }

        $filhos = self::query()
            ->where('active', true)
            ->where(function ($q) use ($pais) {
                foreach ($pais->keys() as $codigo) {
                    $q->orWhereRaw('LOWER(omie_code) LIKE ?', [$codigo.'%']);
                }
            })
            ->with('photos')
            ->get();

        $opcoes = [];

        foreach ($pais as $codigo => $pai) {
            $padrao = '/^'.preg_quote($codigo, '/').'_\d+$/u';

            $opcoes[$codigo] = $filhos
                ->filter(fn (self $filho) => preg_match($padrao, mb_strtolower((string) $filho->omie_code)) === 1)
                ->map(function (self $filho) use ($pai) {
                    [$cor, $tamanho] = $pai->variacaoDoFilho($filho);

                    return $cor === null && $tamanho === null ? null : new OpcaoDeVariacao(
                        $filho->id, $filho->omie_code, $cor, $tamanho, $filho->photo_url, OpcaoDeVariacao::ORIGEM_OMIE,
                    );
                })
                ->filter()
                ->values();
        }

        $semGrade = array_keys(array_filter($opcoes, fn ($lista) => $lista->isEmpty()));

        if ($semGrade !== []) {
            $doParceiro = PartnerSku::query()
                ->whereIn(DB::raw('LOWER(parent_code)'), $semGrade)
                ->where('enabled', true)
                ->where(fn ($q) => $q->whereNotNull('color')->orWhereNotNull('size'))
                ->get()
                ->groupBy(fn (PartnerSku $s) => mb_strtolower((string) $s->parent_code));

            foreach ($semGrade as $codigo) {
                $opcoes[$codigo] = ($doParceiro[$codigo] ?? collect())
                    ->reject(fn (PartnerSku $s) => mb_strtolower((string) $s->sku) === $codigo)
                    ->map(fn (PartnerSku $s) => new OpcaoDeVariacao(
                        $s->id, $s->sku, $s->color, $s->size, $s->images[0] ?? null, OpcaoDeVariacao::ORIGEM_PARCEIRO,
                    ))
                    ->values();
            }
        }

        return array_map(
            fn ($lista) => $lista
                ->sortBy(fn (OpcaoDeVariacao $o) => [mb_strtolower((string) $o->color), ...Variacoes::ordemDoTamanho($o->size)])
                ->values(),
            $opcoes,
        );
    }

    /**
     * Cor e tamanho de um filho da grade deste produto, lidos do nome.
     *
     *  1. O nome do pai mais a variação no fim ("… forro interno G" → "G").
     *  2. A variação em outro lugar do nome: "Jaqueta corta vento G Unissex +
     *     Necessaire", do pai "Jaqueta corta vento unissex + Necessaire". O
     *     tamanho é a palavra que o filho tem e o pai não. Pegar a última
     *     palavra, como antes, dava "Necessaire" como tamanho.
     *     Vale também para o pai só de memória (grade sem o pai cadastrado),
     *     cujo nome é a parte comum dos filhos ("Jaqueta corta vento").
     *  3. Sem tamanho nenhum no nome: a cor, lida do fim.
     *
     * Antes de tudo isso, as características do filho na Omie ("Tamanho Corta
     * Vento: PP"), quando existem. O nome é o plano B.
     *
     * @return array{0: ?string, 1: ?string} [cor, tamanho]
     */
    public function variacaoDoFilho(self $filho): array
    {
        // A característica da Omie, quando o filho tem: ela diz o tamanho e a
        // cor com todas as letras, sem depender de como o nome foi escrito.
        $daOmie = $filho->variacaoDasCaracteristicas();

        if ($daOmie !== null) {
            return $daOmie;
        }

        $nomeDoPai = mb_strtolower(trim((string) $this->name));
        $nome = trim((string) $filho->name);

        $comPrefixo = $nomeDoPai !== '' && str_starts_with(mb_strtolower($nome), $nomeDoPai);
        $doFim = $comPrefixo ? Variacoes::doSufixo(mb_substr($nome, mb_strlen($nomeDoPai))) : null;

        // Tamanho no fim: o caso comum. Sem tamanho no fim ("… G Unissex +
        // Necessaire"), ele ainda pode estar no meio: segue para a comparação.
        if ($doFim !== null && $doFim[1] !== null) {
            return $doFim;
        }

        $limpa = fn (string $palavra) => trim($palavra, " \t,.;:()");
        $chave = fn (string $palavra) => Str::ascii(mb_strtolower($limpa($palavra)));
        $palavras = fn (string $texto) => preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $doPai = array_flip(array_map($chave, $palavras($nomeDoPai)));

        // As palavras do filho que o pai não tem, na ordem do nome.
        $novas = array_values(array_filter(
            array_map($limpa, $palavras($nome)),
            fn (string $palavra) => $palavra !== '' && ! isset($doPai[$chave($palavra)]),
        ));

        $posicao = null;

        foreach ($novas as $i => $palavra) {
            if (Variacoes::eTamanho($palavra)) {
                $posicao = $i;
            }
        }

        if ($posicao !== null) {
            // A cor só quando o resto é curto e é uma cor de verdade: "Azul G"
            // dá Azul; "Unissex G" não dá "Unissex" como cor. Nome reescrito
            // inteiro ("… com capuz, fechamento com zíper… M") fica só com o
            // tamanho.
            $resto = implode(' ', array_diff_key($novas, [$posicao => true]));
            $cor = count($novas) <= 3 && $resto !== '' && Variacoes::hexDaCor($resto) !== null ? $resto : null;

            return [$cor, Variacoes::normalizarTamanho($novas[$posicao])];
        }

        // Sem tamanho em lugar nenhum: a variação é só de cor ("Caneca Azul
        // Marinho"), lida do fim como sempre.
        return $doFim ?? Variacoes::doSufixo((string) Str::afterLast($nome, ' '));
    }

    /**
     * Cor e tamanho pelas características da Omie: a que tem "tamanho" no
     * nome dá o tamanho ("Tamanho Corta Vento: PP"), a que tem "cor", a cor.
     * Null quando o produto não tem nenhuma das duas.
     *
     * @return array{0: ?string, 1: ?string}|null [cor, tamanho]
     */
    public function variacaoDasCaracteristicas(): ?array
    {
        $cor = null;
        $tamanho = null;

        foreach ((array) $this->caracteristicas as $caracteristica) {
            $nome = Str::ascii(mb_strtolower(trim((string) ($caracteristica['nome'] ?? ''))));
            $conteudo = trim((string) ($caracteristica['conteudo'] ?? ''));

            if ($conteudo === '') {
                continue;
            }

            if ($tamanho === null && preg_match('/\btam(anho)?\b/', $nome) === 1) {
                $tamanho = Variacoes::normalizarTamanho($conteudo);
            } elseif ($cor === null && preg_match('/\bcor(es)?\b/', $nome) === 1) {
                $cor = $conteudo;
            }
        }

        return $cor === null && $tamanho === null ? null : [$cor, $tamanho];
    }

    /**
     * O pai na grade da Omie de cada produto que é variação dela
     * (PRD11363_5 → PRD11363). Produto sem pai cadastrado fica de fora.
     *
     * É o que deixa o pedido que veio com os TAMANHOS como itens (5 Colete P,
     * 10 Colete M…) aparecer como um presente só, "Colete", com a escolha do
     * tamanho — em vez de sete brindes que a pessoa receberia todos.
     *
     * @param  iterable<Product>  $produtos
     * @return array<int, Product> id do filho => produto pai
     */
    public static function paisDaGrade(iterable $produtos): array
    {
        $codigoDoPai = [];
        $codigoOriginal = [];
        $nomes = [];

        foreach ($produtos as $produto) {
            if ($produto !== null && preg_match('/^(.+)_\d+$/u', (string) $produto->omie_code, $m) === 1) {
                $base = mb_strtolower($m[1]);
                $codigoDoPai[$produto->id] = $base;
                $codigoOriginal[$base] ??= $m[1];
                $nomes[$base][] = (string) $produto->name;
            }
        }

        if ($codigoDoPai === []) {
            return [];
        }

        $pais = self::query()
            ->whereIn(DB::raw('LOWER(omie_code)'), array_keys($codigoOriginal))
            ->get()
            ->keyBy(fn (self $p) => mb_strtolower((string) $p->omie_code));

        /*
         * Sem o pai cadastrado — a Omie pode ter só os tamanhos (Colete G,
         * Colete M…) — os irmãos continuam sendo uma grade: dois ou mais com
         * o mesmo código base. O pai vira um produto só de memória, nunca
         * gravado, com o nome que os irmãos têm em comum ("Colete").
         */
        foreach ($nomes as $base => $nomesDosIrmaos) {
            if (! $pais->has($base) && count($nomesDosIrmaos) > 1) {
                $pais->put($base, new self([
                    'omie_code' => $codigoOriginal[$base],
                    'name' => self::nomeEmComum($nomesDosIrmaos) ?: $codigoOriginal[$base],
                ]));
            }
        }

        $resultado = [];

        foreach ($codigoDoPai as $id => $codigo) {
            if ($pais->has($codigo)) {
                $resultado[$id] = $pais->get($codigo);
            }
        }

        return $resultado;
    }

    /**
     * O começo de nome que os irmãos da grade dividem, palavra a palavra:
     * "Colete G", "Colete PP" → "Colete". Sem o rótulo que sobra no fim
     * ("Camiseta Consentimento - Tamanho G" → "Camiseta Consentimento").
     *
     * @param  array<int, string>  $nomes
     */
    private static function nomeEmComum(array $nomes): string
    {
        $palavras = array_map(fn (string $n) => preg_split('/\s+/u', trim($n)) ?: [], array_values($nomes));
        $comum = [];

        foreach ($palavras[0] as $i => $palavra) {
            foreach ($palavras as $outras) {
                if (mb_strtolower($outras[$i] ?? '') !== mb_strtolower($palavra)) {
                    break 2;
                }
            }

            $comum[] = $palavra;
        }

        // Tira do fim o que é rótulo ou separador, não nome do produto.
        while ($comum !== [] && in_array(mb_strtolower(trim(end($comum), " -–:,")), ['', 'tamanho', 'tam', 'tam.', 'cor'], true)) {
            array_pop($comum);
        }

        return trim(implode(' ', $comum), " -–:,");
    }

    /**
     * Como um filho da grade aparece para quem escolhe: "M", "Preto · G".
     *
     * @return array{rotulo: string, ordem: array<int, mixed>}
     */
    public function rotuloNaGrade(self $filho): array
    {
        [$cor, $tamanho] = $this->variacaoDoFilho($filho);
        $rotulo = implode(' · ', array_filter([$cor, $tamanho]));

        return [
            'rotulo' => $rotulo !== '' ? $rotulo : (string) $filho->name,
            'ordem' => [mb_strtolower((string) $cor), ...Variacoes::ordemDoTamanho($tamanho)],
        ];
    }

    /**
     * Os produtos que são variação da grade de outro (`<código do pai>_<n>`),
     * com o código do pai de cada um.
     *
     * Só conta como filho quem tem o pai cadastrado: "ABC_2" sem um "ABC" é
     * só um código com sublinhado, e continua sendo um produto como outro.
     *
     * @return array<int, string> id do filho => código do pai, em minúsculas
     */
    public static function filhosDeGrade(): array
    {
        $pais = [];

        foreach (self::query()->whereRaw("omie_code LIKE ? ESCAPE '!'", ['%!_%'])->pluck('omie_code', 'id') as $id => $codigo) {
            if (preg_match('/^(.+)_\d+$/u', mb_strtolower((string) $codigo), $m) === 1) {
                $pais[$id] = $m[1];
            }
        }

        if ($pais === []) {
            return [];
        }

        $cadastrados = self::query()
            ->whereIn(DB::raw('LOWER(omie_code)'), array_values(array_unique($pais)))
            ->pluck('omie_code')
            ->map(fn ($codigo) => mb_strtolower((string) $codigo))
            ->flip();

        return array_filter($pais, fn (string $pai) => isset($cadastrados[$pai]));
    }

    /**
     * Grades cujos tamanhos estão no catálogo e o pai não: dois ou mais
     * `<base>_<n>` sem um `<base>` (a Omie pode ter só os tamanhos).
     *
     * Na lista de Produtos o grupo ocupa a linha do primeiro tamanho — o
     * "representante" —, com o nome que os irmãos têm em comum. Assim a
     * paginação continua contando uma linha por grupo.
     *
     * @return array<string, array{codigo: string, nome: string, ids: array<int, int>, representante: int}>
     */
    public static function gradesSemPai(): array
    {
        $grupos = [];

        foreach (self::query()->whereRaw("omie_code LIKE ? ESCAPE '!'", ['%!_%'])->get(['id', 'omie_code', 'name']) as $p) {
            if (preg_match('/^(.+)_(\d+)$/u', (string) $p->omie_code, $m) === 1) {
                $grupos[mb_strtolower($m[1])][] = ['id' => $p->id, 'codigo' => $m[1], 'n' => (int) $m[2], 'nome' => (string) $p->name];
            }
        }

        if ($grupos === []) {
            return [];
        }

        $comPai = self::query()
            ->whereIn(DB::raw('LOWER(omie_code)'), array_keys($grupos))
            ->pluck('omie_code')
            ->map(fn ($codigo) => mb_strtolower((string) $codigo))
            ->flip();

        $resultado = [];

        foreach ($grupos as $base => $irmaos) {
            if (isset($comPai[$base]) || count($irmaos) < 2) {
                continue;
            }

            usort($irmaos, fn (array $a, array $b) => $a['n'] <=> $b['n']);

            $resultado[$base] = [
                'codigo' => $irmaos[0]['codigo'],
                'nome' => self::nomeEmComum(array_column($irmaos, 'nome')) ?: $irmaos[0]['codigo'],
                'ids' => array_column($irmaos, 'id'),
                'representante' => $irmaos[0]['id'],
            ];
        }

        return $resultado;
    }

    /**
     * O item principal de cada produto: o dono das fotos.
     *
     * Tamanho de grade usa as fotos do pai, e só as dele: os tamanhos não têm
     * galeria própria. Sem o pai cadastrado (a Omie tem só os tamanhos), o pai
     * é de memória e não tem foto nenhuma até alguém enviar a primeira, o que
     * o cadastra (`materializarPaiDaGrade`). Produto fora de grade é o próprio
     * item principal.
     *
     * Diferente de `paisDaGrade`, que agrupa só os irmãos da lista recebida,
     * aqui a grade é a do CATÁLOGO: um pedido com um tamanho só continua sendo
     * de um produto com tamanhos, e a foto continua sendo a do pai.
     *
     * @param  iterable<Product>  $produtos
     * @return array<int, Product> id do produto => item principal
     */
    public static function principais(iterable $produtos): array
    {
        $lista = collect($produtos)->filter()->values();
        $pais = self::paisDaGrade($lista);
        $resultado = [];
        // Uma busca de irmãos por grade, não por tamanho: os sete tamanhos do
        // Colete sem pai cadastrado eram sete buscas iguais no catálogo.
        $deMemoria = [];

        foreach ($lista as $produto) {
            $resultado[$produto->id] = $pais[$produto->id]
                ?? self::paiDeMemoria($produto, $deMemoria)
                ?? $produto;
        }

        return $resultado;
    }

    /** Tamanho de uma grade: as fotos dele moram no pai, não aqui. */
    public function eTamanhoDeGrade(): bool
    {
        return self::principais([$this])[$this->id]->isNot($this);
    }

    /**
     * Cadastra o pai que só existia de memória, para ele receber fotos.
     *
     * A Omie pode ter só os tamanhos (Colete G, Colete M…). O pai nasce com o
     * código base e o nome em comum dos irmãos, como a tela já o mostrava; se
     * um dia a Omie cadastrar o pai, a sincronização encontra este pelo código.
     */
    public static function materializarPaiDaGrade(string $codigo): ?self
    {
        $codigo = trim($codigo);
        $existente = self::whereRaw('LOWER(omie_code) = ?', [mb_strtolower($codigo)])->first();

        if ($existente !== null) {
            return $existente;
        }

        $irmaos = self::irmaosNoCatalogo($codigo);

        if ($irmaos->isEmpty()) {
            return null;
        }

        return self::create([
            'omie_code' => $codigo,
            'name' => self::nomeEmComum($irmaos->pluck('name')->all()) ?: $codigo,
            'active' => true,
        ]);
    }

    /**
     * O pai de memória de um tamanho cujo pai não está cadastrado, se ele tem
     * irmãos.
     *
     * @param  array<string, ?self>  $memoria  código base => pai já resolvido nesta chamada
     */
    private static function paiDeMemoria(self $produto, array &$memoria = []): ?self
    {
        if (preg_match('/^(.+)_\d+$/u', (string) $produto->omie_code, $m) !== 1) {
            return null;
        }

        $base = mb_strtolower($m[1]);

        if (! array_key_exists($base, $memoria)) {
            $irmaos = self::irmaosNoCatalogo($m[1]);

            // Sozinho no catálogo, "ABC_1" é só um código com sublinhado.
            $memoria[$base] = $irmaos->count() < 2 ? null : new self([
                'omie_code' => $m[1],
                'name' => self::nomeEmComum($irmaos->pluck('name')->all()) ?: $m[1],
            ]);
        }

        return $memoria[$base];
    }

    /** Os `<base>_<n>` do catálogo. */
    private static function irmaosNoCatalogo(string $base): \Illuminate\Support\Collection
    {
        $padrao = '/^'.preg_quote(mb_strtolower($base), '/').'_\d+$/u';

        return self::query()
            ->whereRaw('LOWER(omie_code) LIKE ?', [mb_strtolower($base).'%'])
            ->get(['id', 'omie_code', 'name'])
            ->filter(fn (self $p) => preg_match($padrao, mb_strtolower((string) $p->omie_code)) === 1)
            ->values();
    }

    /** Projetos que contratam este produto — inverso de `Project::products()`. */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_products')->withPivot('quantity');
    }
}
