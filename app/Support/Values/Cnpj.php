<?php

namespace App\Support\Values;

use InvalidArgumentException;

/**
 * CNPJ — o documento como TIPO, não como string.
 *
 * Existe porque o CNPJ é a identidade da empresa cliente (a coluna é `unique`,
 * e o `firstOrCreate` do onboarding manual busca por ela), e mesmo assim era
 * validado de três formas diferentes: `max:20` no formulário, `digits:14` na
 * importação em lote e NENHUMA no fallback manual de Projeto — que aceitava
 * "a definir", normalizava para string vazia e fundia duas empresas cliente na
 * mesma linha de `companies`. O escopo por `company_id` continuava correto; o
 * vazamento acontecia a montante, com o dado já fundido.
 *
 * Depois que este objeto existe, ele é válido. Não há caminho para construir um
 * CNPJ inválido, então não há checagem espalhada — não porque alguém lembrou de
 * validar em todo lugar, mas porque o inválido não consegue ser criado.
 *
 * O dígito verificador não existia em nenhuma das três portas, e não é
 * coincidência: ninguém implementa módulo 11 em triplicata. Com um lugar só,
 * passou a valer a pena.
 */
final class Cnpj implements Valor
{
    /**
     * Construtor PRIVADO — é o que fecha a porta dos fundos. Sem ele, alguém
     * escreve `new Cnpj('a definir')` daqui a seis meses e a garantia acaba ali.
     */
    private function __construct(public readonly string $digitos) {}

    public static function de(string $entrada): static
    {
        $digitos = preg_replace('/\D/', '', $entrada) ?? '';

        if (strlen($digitos) !== 14) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        // Sequência repetida passa no cálculo do dígito verificador, mas não
        // existe. 00.000.000/0000-00 é o caso comum: sai de um campo preenchido
        // com zeros para "destravar" o formulário.
        if (preg_match('/^(\d)\1{13}$/', $digitos)) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        if (! self::digitosVerificadoresConferem($digitos)) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        return new self($digitos);
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
        return 'Informe um CNPJ válido, com 14 dígitos.';
    }

    /**
     * Igualdade por VALOR. Dois objetos diferentes com os mesmos dígitos são o
     * mesmo CNPJ — `===` compararia instâncias e diria "não", que é a resposta
     * errada e um bug chato de achar.
     */
    public function equals(?self $outro): bool
    {
        return $outro !== null && $this->digitos === $outro->digitos;
    }

    /** Como a pessoa lê. */
    public function formatado(): string
    {
        return preg_replace(
            '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/',
            '$1.$2.$3/$4-$5',
            $this->digitos,
        );
    }

    /** Como o banco guarda: 14 dígitos, sem máscara. */
    public function __toString(): string
    {
        return $this->digitos;
    }

    private static function digitosVerificadoresConferem(string $digitos): bool
    {
        foreach ([12, 13] as $posicao) {
            if ((int) $digitos[$posicao] !== self::digitoVerificador($digitos, $posicao)) {
                return false;
            }
        }

        return true;
    }

    private static function digitoVerificador(string $digitos, int $posicao): int
    {
        $soma = 0;
        $peso = 2;

        // Da direita para a esquerda, com pesos de 2 a 9 ciclando.
        for ($i = $posicao - 1; $i >= 0; $i--) {
            $soma += (int) $digitos[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
