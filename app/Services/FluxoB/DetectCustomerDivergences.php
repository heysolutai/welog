<?php

namespace App\Services\FluxoB;

use App\Models\AppnozImportRow;

/**
 * Compara o cadastro do cliente na Omie com o que veio na planilha do parceiro.
 *
 * Só roda para cliente que JÁ EXISTE: ausente é criado a partir da planilha, e
 * aí não há dois lados para divergir.
 *
 * Não bloqueia nada. O Anexo I diz que produto ausente é "a única condição
 * bloqueante do fluxo" — divergência de endereço não pode segurar um pedido que
 * a Omie aceita. Ela vira alerta, e o operador decide qual dos dois lados vale.
 */
class DetectCustomerDivergences
{
    /** Campo da linha => campo do cliente normalizado da Omie. */
    private const COMPARAVEIS = [
        'customer_name' => ['name', 'nome'],
        'email' => ['contact_email', 'e-mail'],
        'phone' => ['contact_phone', 'telefone'],
        'zip' => ['zip', 'CEP'],
        'street' => ['street', 'endereço'],
        'number' => ['number', 'número'],
        'district' => ['district', 'bairro'],
        'city' => ['city', 'cidade'],
        'state' => ['state', 'UF'],
    ];

    /**
     * @param  array<string, ?string>  $customer  cliente normalizado da Omie
     * @return array<int, array{campo: string, rotulo: string, na_planilha: ?string, na_omie: ?string}>
     */
    public function handle(AppnozImportRow $row, array $customer): array
    {
        $divergencias = [];

        foreach (self::COMPARAVEIS as $campoLinha => [$campoOmie, $rotulo]) {
            $daPlanilha = $this->normalizar($campoLinha, $row->{$campoLinha});
            $daOmie = $this->normalizar($campoLinha, $customer[$campoOmie] ?? null);

            // Campo vazio de um dos lados não é divergência: é dado que o
            // parceiro não mandou (ou que a Omie nunca teve). Tratar ausência
            // como conflito encheria o painel de ruído e ensinaria a ignorá-lo.
            if ($daPlanilha === null || $daOmie === null || $daPlanilha === $daOmie) {
                continue;
            }

            $divergencias[] = [
                'campo' => $campoLinha,
                'rotulo' => $rotulo,
                'na_planilha' => $row->{$campoLinha},
                'na_omie' => $customer[$campoOmie],
            ];
        }

        return $divergencias;
    }

    /**
     * Compara o que a pessoa consideraria "o mesmo dado".
     *
     * CEP e telefone só pelos dígitos (`80010-000` e `80010000` são iguais),
     * texto sem acento, caixa nem espaço dobrado — senão "São Paulo" e "SAO
     * PAULO" viram conflito, e um painel cheio de falso positivo não é usado.
     */
    private function normalizar(string $campo, ?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        if (in_array($campo, ['zip', 'phone'], true)) {
            return preg_replace('/\D/', '', $valor) ?: null;
        }

        $valor = mb_strtolower($valor);
        $valor = strtr($valor, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c',
        ]);

        return preg_replace('/\s+/', ' ', $valor);
    }
}
