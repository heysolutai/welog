<?php

namespace App\Support\Values;

use InvalidArgumentException;

/**
 * E-mail — e este não existe pela validação, existe pela NORMALIZAÇÃO.
 *
 * As nove portas que recebem e-mail já validavam com `'email'`. O defeito era
 * outro: a ESCRITA gravava em minúsculas (`strtolower` em `UserController`,
 * `CompanyController` e `RegisterEndCustomer`) e a LEITURA comparava o valor
 * cru — e o Postgres distingue maiúsculas.
 *
 * O resultado era um beco sem saída silencioso: quem digitava
 * `Marina@empresa.com.br` no login recebia "Credenciais inválidas" para sempre.
 * Ao pedir "esqueci minha senha", recebia a resposta neutra ("se o e-mail
 * estiver cadastrado, enviamos"), que por segurança não revela nada — então a
 * pessoa não tinha como descobrir que o problema era a maiúscula.
 *
 * O mesmo descompasso quebrava a unicidade: `unique:users,email` rodava sobre o
 * valor cru, ANTES do `strtolower`. Cadastrar `JOAO@x.com` com `joao@x.com`
 * existente passava na validação e estourava no índice do banco — 500 em vez de
 * "e-mail já cadastrado".
 *
 * Com o tipo, normalizar deixa de ser algo que cada porta lembra de fazer.
 */
final class Email implements Valor
{
    private function __construct(public readonly string $endereco) {}

    public static function de(string $entrada): static
    {
        $normalizado = self::normalizar($entrada);

        if (! filter_var($normalizado, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        // A coluna é `string` (255) e o índice é único: acima disso o banco
        // trunca ou recusa, e a unicidade passa a comparar prefixos.
        if (strlen($normalizado) > 255) {
            throw new InvalidArgumentException(self::mensagemDeErro());
        }

        return new self($normalizado);
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
        return 'Informe um e-mail válido.';
    }

    /**
     * A forma canônica: sem espaço nas pontas, tudo em minúsculas.
     *
     * Exposta como estática porque o LOGIN precisa dela sem construir o objeto
     * — ali o e-mail pode ser inválido (é uma tentativa de acesso, não um
     * cadastro) e mesmo assim tem de ser comparado na mesma forma em que foi
     * gravado.
     */
    public static function normalizar(string $entrada): string
    {
        return mb_strtolower(trim($entrada));
    }

    public function equals(?self $outro): bool
    {
        return $outro !== null && $this->endereco === $outro->endereco;
    }

    /** Só o domínio — útil para agrupar por empresa sem expor a pessoa. */
    public function dominio(): string
    {
        return substr(strrchr($this->endereco, '@') ?: '@', 1);
    }

    public function __toString(): string
    {
        return $this->endereco;
    }
}
