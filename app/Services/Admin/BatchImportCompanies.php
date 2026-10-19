<?php

namespace App\Services\Admin;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Services\Support\SpreadsheetReader;
use Illuminate\Support\Facades\Validator;

/**
 * Cadastro de empresas cliente em lote, pelo mesmo caminho do lote de clientes
 * finais: template Excel/CSV com colunas pré-definidas.
 *
 * A planilha é UPSERT pelo CNPJ, e não só criação: a maioria das empresas já
 * existe — nasceu do webhook de pedido da Omie com razão social e CNPJ e mais
 * nada. Recusar essas linhas transformaria a importação em algo inútil
 * justamente para o caso que ela existe para resolver.
 *
 * Célula vazia não apaga o que já está gravado. Quem monta a planilha traz as
 * colunas que tem em mãos; o resto vem em branco por falta de dado, não por
 * pedido de exclusão.
 */
class BatchImportCompanies
{
    public const TEMPLATE_COLUMNS = [
        'razao_social', 'nome_fantasia', 'cnpj', 'codigo_omie',
        'nome_contato', 'email_contato', 'telefone',
        'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf',
    ];

    /** Coluna da planilha → campo do cadastro. `razao_social` e `cnpj` ficam de fora: têm tratamento próprio. */
    private const FIELDS = [
        'nome_fantasia' => 'trade_name',
        'codigo_omie' => 'omie_customer_code',
        'nome_contato' => 'contact_name',
        'email_contato' => 'contact_email',
        'telefone' => 'contact_phone',
        'cep' => 'zip',
        'logradouro' => 'street',
        'numero' => 'number',
        'complemento' => 'complement',
        'bairro' => 'district',
        'cidade' => 'city',
        'uf' => 'state',
    ];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * @return array{created: int, updated: int, errors: array<int, array{row: int, message: string}>}
     */
    public function handle(string $filePath): array
    {
        $rows = $this->reader->read($filePath);

        if ($rows === []) {
            throw new DomainException('A planilha está vazia ou não segue o template.');
        }

        $created = 0;
        $updated = 0;
        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // 1 = cabeçalho
            $data = $this->normalize($row);

            $validator = $this->validator($data);

            if ($validator->fails()) {
                $errors[] = ['row' => $rowNumber, 'message' => implode(' ', $validator->errors()->all())];

                continue;
            }

            // Dois lançamentos do mesmo CNPJ no mesmo arquivo: o segundo
            // sobrescreveria o primeiro em silêncio, e quem enviou não teria
            // como saber qual dos dois valeu.
            if (isset($seen[$data['cnpj']])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => "Este CNPJ já aparece na linha {$seen[$data['cnpj']]} desta planilha.",
                ];

                continue;
            }

            $seen[$data['cnpj']] = $rowNumber;

            $company = Company::where('cnpj', $data['cnpj'])->first();

            if ($company === null) {
                Company::create($this->attributes($data) + ['cnpj' => $data['cnpj']]);
                $created++;

                continue;
            }

            $company->update($this->attributes($data));
            $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * Coluna ausente na planilha vira `null` — sem isso, um arquivo com menos
     * colunas que o template estouraria em índice indefinido em vez de dizer
     * qual campo obrigatório faltou.
     *
     * @param  array<string, string>  $row
     * @return array<string, string|null>
     */
    private function normalize(array $row): array
    {
        $data = [];

        foreach (self::TEMPLATE_COLUMNS as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            $data[$column] = $value === '' ? null : $value;
        }

        // CNPJ e CEP só com dígitos: a mesma empresa com e sem pontuação
        // entraria duas vezes, e a coluna do banco tem tamanho fixo.
        $data['cnpj'] = $data['cnpj'] === null ? null : preg_replace('/\D/', '', $data['cnpj']);
        $data['cep'] = $data['cep'] === null ? null : preg_replace('/\D/', '', $data['cep']);
        $data['uf'] = $data['uf'] === null ? null : mb_strtoupper($data['uf']);
        $data['email_contato'] = $data['email_contato'] === null ? null : mb_strtolower($data['email_contato']);

        return $data;
    }

    /** @param  array<string, string|null>  $data */
    private function validator(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, [
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['required', 'digits:14'],
            'codigo_omie' => ['nullable', 'string', 'max:255'],

            'nome_contato' => ['nullable', 'string', 'max:255'],
            'email_contato' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:30'],

            'cep' => ['nullable', 'digits:8'],
            'logradouro' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:255'],
            'cidade' => ['nullable', 'string', 'max:255'],
            'uf' => ['nullable', 'string', 'size:2'],
        ], [
            'cnpj.digits' => 'O CNPJ deve ter 14 dígitos.',
            'cep.digits' => 'O CEP deve ter 8 dígitos.',
        ], [
            'razao_social' => 'razão social',
            'nome_fantasia' => 'nome fantasia',
            'codigo_omie' => 'código Omie',
            'nome_contato' => 'nome do contato',
            'email_contato' => 'e-mail de contato',
            'cep' => 'CEP',
            'uf' => 'UF',
        ]);
    }

    /**
     * @param  array<string, string|null>  $data
     * @return array<string, string>
     */
    private function attributes(array $data): array
    {
        $attributes = ['name' => $data['razao_social']];

        foreach (self::FIELDS as $column => $field) {
            if ($data[$column] !== null) {
                $attributes[$field] = $data[$column];
            }
        }

        return $attributes;
    }
}
