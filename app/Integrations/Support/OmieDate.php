<?php

namespace App\Integrations\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Log;

/**
 * Datas vindas da Omie.
 *
 * A API real usa `dd/mm/aaaa`, formato que `Carbon::parse()` interpreta como
 * `mm/dd/aaaa`: `05/12/2026` viraria 12 de maio em vez de 5 de dezembro, e
 * `25/12/2026` lançaria exceção derrubando o webhook inteiro. O gateway `fake`
 * usa ISO, então nada disso aparecia em teste.
 *
 * Aqui os dois formatos são aceitos explicitamente e o que não for
 * reconhecido vira `null` com log — um prazo de entrega ilegível não pode
 * impedir a criação do Projeto.
 */
class OmieDate
{
    /** Formatos aceitos, em ordem: pt-BR da API real, depois ISO do fake. */
    private const FORMATS = ['d/m/Y', 'Y-m-d', 'd/m/Y H:i:s', 'Y-m-d H:i:s'];

    /**
     * Formata uma data para os filtros da Omie (`dd/mm/aaaa`) **no fuso do
     * negócio**. Formatar o `now()` em UTC faria as consultas entre 21h e 0h
     * BRT pedirem o dia seguinte.
     */
    public static function forFilter(CarbonInterface $date): string
    {
        return $date->copy()
            ->setTimezone(config('integrations.business_timezone'))
            ->format('d/m/Y');
    }

    public static function parse(?string $value, string $context = ''): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (self::FORMATS as $format) {
            try {
                // createFromFormat lança InvalidFormatException no que não
                // casa — e aceita entradas parciais no que casa parcialmente,
                // daí a conferência de round-trip.
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($date !== false && $date->format($format) === $value) {
                return $date->startOfDay();
            }
        }

        Log::channel('integrations')->warning('Data da Omie em formato não reconhecido.', [
            'value' => $value,
            'context' => $context,
        ]);

        return null;
    }
}
