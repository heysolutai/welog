<?php

namespace App\Integrations\Cep;

use App\Contracts\Integrations\CepGateway;

/**
 * Simulação da consulta de CEP (driver 'fake') — dev e testes sem rede.
 *
 * Alguns CEPs conhecidos, e um formato sintético para qualquer outro válido,
 * de modo que a tela possa ser exercitada com qualquer entrada. CEP terminado
 * em `000` é tratado como inexistente, para dar como testar esse caminho.
 */
class CepFakeGateway implements CepGateway
{
    private const CONHECIDOS = [
        '80010000' => ['street' => 'Rua XV de Novembro', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR'],
        '01310100' => ['street' => 'Avenida Paulista', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP'],
        '20031170' => ['street' => 'Avenida Rio Branco', 'district' => 'Centro', 'city' => 'Rio de Janeiro', 'state' => 'RJ'],
    ];

    public function lookup(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen((string) $cep) !== 8) {
            return null;
        }

        if (isset(self::CONHECIDOS[$cep])) {
            return ['zip' => $cep] + self::CONHECIDOS[$cep];
        }

        // Caminho "CEP inexistente", para a tela poder tratá-lo
        if (str_ends_with($cep, '000')) {
            return null;
        }

        return [
            'zip' => $cep,
            'street' => 'Rua Fictícia '.substr($cep, 0, 3),
            'district' => 'Bairro Simulado',
            'city' => 'Cidade Simulada',
            'state' => 'PR',
        ];
    }
}
