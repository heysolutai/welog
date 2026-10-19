<?php

namespace App\Support;

/**
 * Nome de pessoa com a primeira letra de cada nome em maiúscula:
 * "MARIA DA SILVA" e "maria da silva" viram "Maria da Silva".
 *
 * A planilha do Cliente chega do jeito que o RH exportou — tudo maiúsculo,
 * tudo minúsculo, misturado — e o nome vai para o e-mail ("Olá, MARIA") e
 * para a etiqueta da entrega. As partículas (da, de, do, das, dos, e) ficam
 * minúsculas, menos no começo; nome composto com hífen ou apóstrofo
 * (Ana-Paula, D'Ávila) ganha maiúscula em cada parte.
 */
final class NomeProprio
{
    private const PARTICULAS = ['da', 'das', 'de', 'do', 'dos', 'e'];

    /** Numeral romano de sufixo (Pedro II, João III) continua maiúsculo. */
    private const ROMANOS = ['ii', 'iii', 'iv', 'vi', 'vii', 'viii'];

    public static function formatar(string $nome): string
    {
        $palavras = preg_split('/\s+/u', mb_strtolower(trim($nome)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($palavras as $i => $palavra) {
            if ($i > 0 && in_array($palavra, self::PARTICULAS, true)) {
                continue;
            }

            if ($i > 0 && in_array($palavra, self::ROMANOS, true)) {
                $palavras[$i] = mb_strtoupper($palavra);

                continue;
            }

            // Maiúscula no começo e depois de hífen ou apóstrofo.
            $palavras[$i] = preg_replace_callback(
                "/(^|[-'’])(\p{L})/u",
                fn (array $m) => $m[1].mb_strtoupper($m[2]),
                $palavra,
            );
        }

        return implode(' ', $palavras);
    }
}
