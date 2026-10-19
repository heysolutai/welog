<?php

namespace Tests\Unit;

use App\Support\NomeProprio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NomeProprioTest extends TestCase
{
    public static function nomes(): array
    {
        return [
            'tudo maiúsculo' => ['MARIA DA SILVA', 'Maria da Silva'],
            'tudo minúsculo' => ['joão dos santos', 'João dos Santos'],
            'misturado' => ['aNA pAULA de SOUZA', 'Ana Paula de Souza'],
            'acentos' => ['ÉRICA CONCEIÇÃO', 'Érica Conceição'],
            'espaços sobrando' => ['  pedro   e   paulo  ', 'Pedro e Paulo'],
            'hífen' => ['ANA-PAULA LIMA', 'Ana-Paula Lima'],
            'apóstrofo' => ["JOANA D'ÁVILA", "Joana D'Ávila"],
            'partícula no começo' => ['DA SILVA', 'Da Silva'],
            'numeral romano' => ['PEDRO II', 'Pedro II'],
        ];
    }

    #[DataProvider('nomes')]
    public function test_primeira_letra_de_cada_nome_em_maiuscula(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, NomeProprio::formatar($entrada));
    }
}
