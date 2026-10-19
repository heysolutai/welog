<?php

namespace App\Rules;

use App\Support\Values\Valor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ponte entre o validador do Laravel e os objetos de valor.
 *
 * Uma regra para os quatro tipos, e não quatro regras quase iguais — a
 * duplicação é exatamente o que estes objetos vieram resolver, então repeti-la
 * aqui seria trocar três validações divergentes por quatro.
 *
 * Uso:
 *
 *     'cnpj'  => ['required', new ValorValido(Cnpj::class)],
 *     'cpf'   => ['nullable', new ValorValido(Cpf::class)],
 *     'email' => ['required', new ValorValido(Email::class)],
 *
 * A regra só responde "é um X válido?". Se o campo é obrigatório ou não continua
 * sendo `required`/`nullable` — são perguntas diferentes, e misturá-las faria o
 * mesmo tipo precisar de duas versões.
 */
final class ValorValido implements ValidationRule
{
    /** @param class-string<Valor> $tipo */
    public function __construct(private readonly string $tipo) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Vazio é assunto do `required`/`nullable`, não desta regra. Sem esta
        // saída, um campo opcional em branco seria recusado como "inválido".
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || $this->tipo::tentar($value) === null) {
            $fail($this->tipo::mensagemDeErro());
        }
    }
}
