<?php

namespace App\Support\Values;

use Stringable;

/**
 * Contrato dos objetos de valor do sistema.
 *
 * Todos seguem a mesma forma, e ela não é estética: é o que permite UMA regra
 * de validação (`App\Rules\ValorValido`) servir aos quatro tipos, em vez de
 * quatro regras quase iguais — que é justamente o problema que estes objetos
 * vieram resolver.
 *
 * A forma é:
 *
 *   ::de()       entrada do usuário. Lança se for inválido.
 *   ::tentar()   idem, mas devolve null — para lote, que precisa relatar as
 *                linhas ruins sem abortar as boas.
 *   ::doBanco()  hidratação. CONFIA no que já está gravado.
 *   (string)     como o banco guarda.
 *
 * Sobre `doBanco()`: a validação de verdade acontece na ESCRITA. Revalidar na
 * leitura faria telas estourarem por causa de linhas antigas, gravadas quando
 * cada porta validava de um jeito — e quebrar a tela não conserta dado velho.
 * Limpar o que já está lá é migração, não responsabilidade da leitura.
 */
interface Valor extends Stringable
{
    /** @throws \InvalidArgumentException */
    public static function de(string $entrada): static;

    public static function tentar(?string $entrada): ?static;

    /** Confia: o dado já passou pela porta quando foi gravado. */
    public static function doBanco(string $valor): static;

    /** Mensagem exibida quando a validação recusa. */
    public static function mensagemDeErro(): string;
}
