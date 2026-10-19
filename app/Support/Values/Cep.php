<?php

namespace App\Support\Values;

use InvalidArgumentException;

/**
 * CEP do endereço de entrega.
 *
 * Quatro portas, três regras — e a mais frouxa é a que mais importa:
 * `PortalController::redeem` aceitava `['required', 'string']`, ou seja
 * qualquer coisa, no CEP do endereço para onde o presente é DESPACHADO. Um CEP
 * inválido ali vira uma prepostagem recusada pelos Correios (ou um objeto
 * postado para lugar nenhum) depois do resgate confirmado, com a pessoa
 * esperando.
 *
 * Não há dígito verificador em CEP: são 8 dígitos e pronto. A validação real de
 * existência é a consulta do `CepGateway`, que a tela de resgate já faz para
 * preencher o endereço — este tipo garante a FORMA, e a consulta garante a
 * realidade. São camadas diferentes, e ter a forma garantida é o que impede a
 * consulta de ser chamada com lixo.
 */
final class Cep implements Valor
{
    private function __construct(public readonly string $digitos) {}

    public static function de(string $entrada): static
    {
        $digitos = preg_replace('/\D/', '', $entrada) ?? '';

        if (strlen($digitos) !== 8) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        // 00000000 é o que sai de um campo preenchido para passar da validação.
        if ($digitos === '00000000') {
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
        return 'Informe um CEP válido, com 8 dígitos.';
    }

    public function equals(?self $outro): bool
    {
        return $outro !== null && $this->digitos === $outro->digitos;
    }

    public function formatado(): string
    {
        return preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $this->digitos);
    }

    public function __toString(): string
    {
        return $this->digitos;
    }
}
