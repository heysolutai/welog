<?php

namespace App\Services\FluxoB;

use App\Exceptions\DomainException;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * O miolo das Importações Parceiros: transformar linhas cruas em resgates.
 *
 * Ele existe porque agora há DUAS portas de entrada para o mesmo material —
 * o relatório que o operador salva no diretório e o webhook que o parceiro
 * chama (ver SuvinilWebhookController). O que muda entre elas é só de onde as
 * linhas vêm; validar, bloquear e processar tem de ser idêntico, senão o
 * mesmo dado entra pela planilha e é recusado pelo webhook, ou o contrário —
 * e nesse ponto ninguém mais consegue explicar por que.
 *
 * A separação entre `abrir()` e `processar()` não é enfeite: o registro da
 * importação precisa existir ANTES da leitura, para que um arquivo ilegível
 * deixe uma linha `failed` na tela em vez de sumir sem rastro.
 */
class IngestPartnerRows
{
    public function __construct(
        private readonly ProcessAppnozRow $processRow,
        private readonly PartnerRowVocabulary $vocabulario,
    ) {}

    /** Abre o registro da importação. `$hash` é a trava contra reenvio. */
    public function abrir(string $reference, string $hash, string $source, ?User $importedBy = null): AppnozImport
    {
        if (AppnozImport::where('file_hash', $hash)->exists()) {
            throw new DomainException('Este arquivo já foi importado anteriormente.');
        }

        return AppnozImport::create([
            'filename' => $reference,
            'file_hash' => $hash,
            'source' => $source,
            'imported_by' => $importedBy?->id,
        ]);
    }

    /**
     * Valida e processa cada linha.
     *
     * `$primeiraLinha` é o número que o operador vê: numa planilha a primeira
     * linha de dados é a 2 (a 1 é o cabeçalho); num webhook é a 1, porque não
     * existe cabeçalho nenhum. Errar isso manda a pessoa procurar o problema
     * na linha errada.
     */
    public function processar(AppnozImport $import, iterable $rows, int $primeiraLinha = 2): void
    {
        $numero = $primeiraLinha;

        foreach ($rows as $data) {
            $row = $this->criarLinha($import, $numero++, $this->vocabulario->traduzir((array) $data));

            // Linhas estruturalmente válidas seguem para o processamento
            if ($row !== null && $row->status === AppnozImportRow::STATUS_PENDING) {
                $this->processRow->handle($row);
            }
        }

        $import->refreshCounters();
    }

    /**
     * @return AppnozImportRow|null null quando a solicitação já entrou antes
     */
    private function criarLinha(AppnozImport $import, int $rowNumber, array $data): ?AppnozImportRow
    {
        /*
         * Retentativa do mesmo lote: a linha já existe NESTA importação e é ela
         * que segue. Sem isto a trava do "ID Solicitação" logo abaixo a tomaria
         * por repetida, e o resgate que parou no meio (Omie ocupada) nunca
         * viraria pedido.
         */
        $existente = AppnozImportRow::query()
            ->where('appnoz_import_id', $import->id)
            ->where('row_number', $rowNumber)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $externalId = $this->vocabulario->identificador($data);

        /*
         * A mesma solicitação em dois relatórios não vira dois brindes.
         *
         * É o caso comum da operação, não o excepcional: o relatório da manhã
         * traz a solicitação ainda "solicitado", e o da tarde a traz de novo,
         * junto com as novas. A trava do lote não pega isso — os dois arquivos
         * são diferentes. A do "ID Solicitação" pega.
         *
         * A linha repetida é descartada em silêncio de propósito: ela não é um
         * problema que alguém precise resolver, e uma linha bloqueada por
         * lote inflaria o contador de bloqueios até ninguém mais olhar para
         * ele.
         */
        if ($externalId !== null && AppnozImportRow::where('external_id', $externalId)->exists()) {
            return null;
        }

        $row = new AppnozImportRow([
            'appnoz_import_id' => $import->id,
            'row_number' => $rowNumber,
            'external_id' => $externalId,
            'status' => AppnozImportRow::STATUS_PENDING,
            'cpf' => preg_replace('/\D/', '', $data['cpf'] ?? '') ?: null,
            'customer_name' => $data['nome'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['telefone'] ?? null,
            'product_code' => $data['codigo_produto'] ?? null,
            'product_name' => $data['produto'] ?? null,
            'quantity' => max(1, (int) ($data['quantidade'] ?? 1)),
            'zip' => preg_replace('/\D/', '', $data['cep'] ?? '') ?: null,
            'street' => $data['endereco'] ?? null,
            'number' => $data['numero'] ?? null,
            'complement' => $data['complemento'] ?: null,
            'district' => $data['bairro'] ?? null,
            'city' => $data['cidade'] ?? null,
            'state' => strtoupper($data['uf'] ?? '') ?: null,
        ]);

        // Validação estrutural (módulo 3): campos obrigatórios e formatos
        $validator = Validator::make($row->toArray(), [
            'cpf' => ['required', 'digits:11'],
            'customer_name' => ['required', 'string'],
            'product_code' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'zip' => ['required', 'digits:8'],
            'street' => ['required', 'string'],
            'number' => ['required', 'string'],
            'district' => ['required', 'string'],
            'city' => ['required', 'string'],
            'state' => ['required', 'size:2'],
        ], [], [
            'cpf' => 'CPF', 'customer_name' => 'nome', 'product_code' => 'código do produto',
            'quantity' => 'quantidade', 'zip' => 'CEP', 'street' => 'endereço',
            'number' => 'número', 'district' => 'bairro', 'city' => 'cidade', 'state' => 'UF',
        ]);

        if ($validator->fails()) {
            $row->status = AppnozImportRow::STATUS_BLOCKED_STRUCTURAL;
            $row->block_reason = implode(' ', $validator->errors()->all());
        }

        $row->save();

        return $row;
    }
}
