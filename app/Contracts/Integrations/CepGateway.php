<?php

namespace App\Contracts\Integrations;

/**
 * Consulta de endereço por CEP.
 *
 * Serve para não fazer o operador (nem o Usuário, no portal) digitar
 * logradouro, bairro, cidade e UF à mão — e para reduzir erro de digitação em
 * endereço de entrega, que custa reentrega.
 */
interface CepGateway
{
    /**
     * Endereço do CEP, ou null quando não existe.
     *
     * Indisponibilidade do provedor NÃO é null: propaga exceção, para a tela
     * poder dizer "serviço fora, preencha à mão" em vez de "CEP inexistente".
     *
     * @return array{zip: string, street: ?string, district: ?string, city: ?string, state: ?string}|null
     */
    public function lookup(string $cep): ?array;
}
