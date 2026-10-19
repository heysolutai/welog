<?php

namespace App\Support\Values;

use InvalidArgumentException;

/**
 * CPF do cliente final.
 *
 * Das três portas que recebiam CPF, DUAS não validavam nada — o cadastro
 * individual (`ProjectController`) e o em lote (`BatchRegisterEndCustomers`)
 * aceitavam `['nullable', 'string']`, ou seja, qualquer coisa. Só a importação
 * do Fluxo B conferia `digits:11`, e nenhuma das três conferia o dígito
 * verificador.
 *
 * O custo disso não é estético: o CPF vai para o cadastro de cliente na Omie
 * (`ProcessAppnozRow`, campo `cnpj_cpf`) e para a emissão de nota fiscal. Um
 * CPF errado só aparece quando a NF é recusada — depois do resgate confirmado,
 * com a pessoa esperando o presente.
 *
 * É opcional no cadastro do Fluxo A e obrigatório no B; por isso a validação
 * mora aqui e a obrigatoriedade continua sendo `required`/`nullable` em cada
 * porta. São perguntas diferentes: "é um CPF?" e "precisa ter?".
 */
final class Cpf implements Valor
{
    private function __construct(public readonly string $digitos) {}

    public static function de(string $entrada): static
    {
        $digitos = preg_replace('/\D/', '', $entrada) ?? '';

        if (strlen($digitos) !== 11) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        // 111.111.111-11 e irmãos passam no módulo 11 e não existem.
        if (preg_match('/^(\d)\1{10}$/', $digitos)) {
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
        return 'Informe um CPF válido, com 11 dígitos.';
    }

    public function equals(?self $outro): bool
    {
        return $outro !== null && $this->digitos === $outro->digitos;
    }

    public function formatado(): string
    {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $this->digitos);
    }

    public function __toString(): string
    {
        return $this->digitos;
    }

    /**
     * Módulo 11 com pesos decrescentes — a variante do CPF é diferente da do
     * CNPJ, e é por isso que os dois algoritmos não são compartilhados.
     */
    private static function digitosVerificadoresConferem(string $digitos): bool
    {
        foreach ([9, 10] as $posicao) {
            if ((int) $digitos[$posicao] !== self::digitoVerificador($digitos, $posicao)) {
                return false;
            }
        }

        return true;
    }

    private static function digitoVerificador(string $digitos, int $posicao): int
    {
        $soma = 0;
        $peso = $posicao + 1;

        for ($i = 0; $i < $posicao; $i++) {
            $soma += (int) $digitos[$i] * $peso--;
        }

        $resto = ($soma * 10) % 11;

        return $resto === 10 ? 0 : $resto;
    }
}
