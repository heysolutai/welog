<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Texto no formato que a importação da Flash (Bridge Link) aceita: só letras
 * sem acento, números e espaço.
 *
 * O suporte da Bridge Link apontou a pontuação como o que derrubava a
 * importação com HTTP 500. A resposta deles é um arquivo `import_padrao_….txt`:
 * o lote vira texto num sistema antigo, e acento, vírgula, ponto, barra, "nº"
 * ou "º" quebram a linha do arquivo. Endereço de resgate tem tudo isso ("R.
 * das Flores, 12", "Apto 3º", "S/N", "São Luiz").
 *
 * "Cidade Satélite São Luiz" vira "Cidade Satelite Sao Luiz"; "R. Ipê, 12 -
 * fundos" vira "R Ipe 12 fundos"; "S/N" vira "SN".
 */
final class TextoFlash
{
    public static function limpar(?string $texto): ?string
    {
        // Ordinais à mão: o `Str::ascii` padrão (inglês) não garante "nº" → "no".
        $limpo = Str::ascii(strtr((string) $texto, ['º' => 'o', 'ª' => 'a', '°' => 'o']));
        // Pontuação vira espaço, e não some: "Qd.58" fica "Qd 58", não "Qd58".
        $limpo = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $limpo);
        $limpo = trim((string) preg_replace('/\s+/', ' ', (string) $limpo));

        // "S/N" e "s.n." são o "sem número" de sempre.
        if (preg_match('/^s ?n$/i', $limpo) === 1) {
            return 'SN';
        }

        return $limpo === '' ? null : $limpo;
    }
}
