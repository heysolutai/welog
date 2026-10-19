<?php

namespace App\Support\Values;

use InvalidArgumentException;

/**
 * Telefone brasileiro do Colaborador, sempre gravado como `+5541999990000`.
 *
 * A planilha chega do jeito que cada RH escreve: "(41) 99999-0000",
 * "41999990000", "55 41 99999-0000", "+55 (41) 9 9999-0000", "041 99999-0000"
 * e até com código de operadora ("0 15 41 99999-0000"). Gravado cru, cada linha
 * ficava num formato — e quem usa o número depois (WhatsApp, transportadora)
 * não sabe qual esperar.
 *
 * A normalização:
 *  - tira tudo que não é dígito: espaço, parênteses, hífen, ponto, o próprio +;
 *  - tira o zero de discagem (0 41…, 00 55…) e, depois dele, o código de
 *    operadora (0 15 41…, 0 21 41…);
 *  - com 10 ou 11 dígitos (DDD + número), acrescenta o 55;
 *  - com 12 ou 13 começando por 55, o país já veio.
 *
 * Só números do Brasil: é para lá que o presente vai.
 */
final class Telefone implements Valor
{
    private function __construct(public readonly string $e164) {}

    public static function de(string $entrada): static
    {
        $digitos = preg_replace('/\D/', '', $entrada) ?? '';

        // "+1 415…" ou "00 1 415…" é de outro país, não DDD 14 de Bauru.
        $internacional = str_starts_with(ltrim($entrada), '+') || str_starts_with($digitos, '00');

        $discagem = str_starts_with($digitos, '0');
        $digitos = ltrim($digitos, '0');

        if ($internacional && ! str_starts_with($digitos, '55')) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        $comPais = in_array(strlen($digitos), [12, 13], true) && str_starts_with($digitos, '55');

        // "0 15 41 99999-0000": depois do zero vem a operadora, não o país.
        if ($discagem && ! $comPais && in_array(strlen($digitos), [12, 13], true)) {
            $digitos = substr($digitos, 2);
        }

        if (in_array(strlen($digitos), [10, 11], true)) {
            $digitos = '55'.$digitos;
        }

        // 55 + DDD (11 a 99) + 8 dígitos do fixo ou 9 do celular.
        if (! preg_match('/^55[1-9]\d\d{8,9}$/', $digitos)) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        return new self('+'.$digitos);
    }

    public static function tentar(?string $entrada): ?static
    {
        if ($entrada === null || trim($entrada) === '') {
            return null;
        }

        try {
            return self::de($entrada);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function doBanco(string $valor): static
    {
        return new self($valor);
    }

    public static function mensagemDeErro(): string
    {
        return 'Informe um telefone com DDD, como +5541999990000.';
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
