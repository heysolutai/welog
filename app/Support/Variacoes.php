<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Vocabulário de cor e tamanho das variações de produto.
 *
 * As opções em si vêm da grade da Omie ou do catálogo do parceiro (ver
 * Product::opcoesDeVariacao). Daqui saem a ordem dos tamanhos como numa loja,
 * a cor da bolinha no portal e a leitura de cor/tamanho do nome da variação.
 */
class Variacoes
{
    /**
     * Ordem de vitrine. Tamanho fora da lista vai para o fim, na ordem natural
     * (numérico antes de texto) — "36" antes de "38", nunca "38" antes de "P".
     */
    private const TAMANHOS = [
        'RN', 'PP', 'XP', 'P', 'M', 'G', 'GG', 'XG', 'EG', 'G1', 'XGG', 'EGG', 'G2', 'XXG', 'EXG', 'G3', 'G4', 'G5', 'U', 'UNICO',
    ];

    /** Cores comuns em brinde corporativo → cor da bolinha. Chave sem acento, minúscula. */
    private const CORES = [
        'azul marinho' => '#1f2a44',
        'azul royal' => '#2849b8',
        'azul claro' => '#8ec5f0',
        'azul bebe' => '#b9d9f2',
        'verde militar' => '#4b5320',
        'verde bandeira' => '#00843d',
        'verde musgo' => '#5a6b2f',
        'verde agua' => '#a8e0d1',
        'verde limao' => '#a3d65c',
        'cinza mescla' => '#a7a9ac',
        'cinza chumbo' => '#4a4d52',
        'off white' => '#f4f1ea',
        'rosa bebe' => '#f6c9d4',
        'preto' => '#111111',
        'preta' => '#111111',
        'branco' => '#ffffff',
        'branca' => '#ffffff',
        'vermelha' => '#d62828',
        'amarela' => '#f4c430',
        'roxa' => '#6b3fa0',
        'dourada' => '#c9a227',
        'prateada' => '#c0c0c0',
        'azul' => '#2563eb',
        'vermelho' => '#d62828',
        'verde' => '#2e9e4f',
        'amarelo' => '#f4c430',
        'laranja' => '#f07c1f',
        'rosa' => '#ec6fa0',
        'pink' => '#e6007e',
        'roxo' => '#6b3fa0',
        'lilas' => '#b49bd6',
        'cinza' => '#8a8d91',
        'grafite' => '#3c3f44',
        'chumbo' => '#4a4d52',
        'marrom' => '#6b4226',
        'bege' => '#d9c4a0',
        'caqui' => '#b5a47a',
        'nude' => '#e3bc9a',
        'vinho' => '#6d1a36',
        'bordo' => '#7a1f2b',
        'mostarda' => '#d4a017',
        'turquesa' => '#2bb5b8',
        'dourado' => '#c9a227',
        'prata' => '#c0c0c0',
        'natural' => '#e9dfc9',
        'cru' => '#eee4cf',
    ];

    /** Tamanho na forma de vitrine: maiúsculo, sem acento ("Único" → "UNICO" só para comparar). */
    public static function normalizarTamanho(?string $tamanho): ?string
    {
        $t = trim((string) $tamanho);

        return $t === '' ? null : mb_strtoupper($t);
    }

    /** Chave de ordenação de um tamanho. */
    public static function ordemDoTamanho(?string $tamanho): array
    {
        $t = Str::ascii(mb_strtoupper(trim((string) $tamanho)));
        $pos = array_search($t, self::TAMANHOS, true);

        if ($pos !== false) {
            return [0, $pos, ''];
        }

        if (is_numeric($t)) {
            return [1, (float) $t, ''];
        }

        return [2, 0, $t];
    }

    /** "G", "GG", "38": é um tamanho de vitrine? */
    public static function eTamanho(?string $texto): bool
    {
        $t = Str::ascii(mb_strtoupper(trim((string) $texto)));

        return in_array($t, self::TAMANHOS, true) || preg_match('/^\d{1,3}$/', $t) === 1;
    }

    /**
     * Cor e tamanho escritos no fim do nome de uma variação da grade da Omie.
     *
     * A Omie nomeia o filho como o pai mais a variação ("… forro interno G",
     * "Camiseta Preta GG"). O tamanho é a última palavra, quando é tamanho; o
     * que sobra é a cor. "Tamanho"/"Cor" escritos por extenso são só rótulo.
     *
     * @return array{0: ?string, 1: ?string} [cor, tamanho]
     */
    public static function doSufixo(string $sufixo): array
    {
        $partes = preg_split('/[\s\/\-–,]+/u', trim($sufixo), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $partes = array_values(array_filter(
            $partes,
            fn (string $p) => ! in_array(self::chave($p), ['tamanho', 'tam', 'tam.', 'cor'], true),
        ));

        $tamanho = $partes !== [] && self::eTamanho(end($partes))
            ? self::normalizarTamanho(array_pop($partes))
            : null;

        $cor = trim(implode(' ', $partes));

        return [$cor === '' ? null : $cor, $tamanho];
    }

    /** A cor da bolinha: a informada, ou a do dicionário pelo nome, ou null. */
    public static function hexDaCor(?string $cor, ?string $hexInformado = null): ?string
    {
        if (filled($hexInformado) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $hexInformado)) {
            return strtolower($hexInformado);
        }

        $chave = self::chave($cor);

        return $chave === '' ? null : (self::CORES[$chave] ?? null);
    }


    private static function chave(?string $texto): string
    {
        return trim(mb_strtolower(Str::ascii((string) $texto)));
    }
}
