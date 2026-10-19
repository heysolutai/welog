<?php

namespace App\Jobs\FluxoA;

use App\Models\EntitlementImport;
use App\Models\EntitlementImportFailure;
use App\Services\FluxoA\BatchRegisterEndCustomers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Um lote da planilha, fora da requisição.
 *
 * A importação inteira rodava no upload, uma volta por linha, e cada volta
 * gasta um bcrypt para o token de convite. Nove mil linhas são uns doze
 * minutos numa requisição que o nginx corta aos cinco: metade entrava, o
 * operador via 504 e ninguém sabia onde tinha parado.
 *
 * Os lotes são despachados de uma vez, no upload, com as linhas já lidas: a
 * leitura da planilha é barata, quem custa é o cadastro. Cada job carrega a
 * sua fatia e não precisa reabrir o arquivo — reler nove mil linhas quarenta e
 * cinco vezes seria trocar um gargalo por outro.
 */
class ImportEntitlementRows implements ShouldQueue
{
    use Queueable;

    /**
     * Cinquenta linhas por job.
     *
     * Cada linha custa um bcrypt (~60ms) mais as escritas: cinquenta cabem em
     * uns quatro segundos, bem dentro do `--timeout=120` do worker, e o
     * progresso na tela anda de forma visível em vez de saltar de cem em cem.
     */
    public const LOTE = 50;

    public int $tries = 2;

    public int $timeout = 110;

    /**
     * Os itens escolhidos na tela, iguais para todas as linhas. Propriedade
     * comum, e não promovida, para ter valor padrão: um job que já estava na
     * fila antes dela existir volta da fila sem ela, e cai em "todos os itens".
     *
     * @var array{garantidos: array<int, int>, opcoes: array<int, int>}|null
     */
    public ?array $itens = null;

    /**
     * @param  array<int, array<string, string>>  $rows  as linhas desta fatia
     * @param  int  $primeiraLinha  número da linha da planilha da primeira delas
     */
    public function __construct(
        public int $importId,
        public array $rows,
        public int $primeiraLinha,
        ?array $itens = null,
    ) {
        $this->itens = $itens;
        $this->onQueue('imports');
    }

    public function handle(BatchRegisterEndCustomers $batch): void
    {
        $import = EntitlementImport::with('project', 'uploader')->find($this->importId);

        // Importação cancelada (ou encerrada à mão): os lotes que ainda
        // estavam na fila não cadastram mais ninguém.
        if ($import === null || $import->finished_at !== null) {
            return;
        }

        $resultado = $batch->processarLinhas(
            $import->project,
            $this->rows,
            $import->uploader,
            $this->primeiraLinha,
            $this->itens,
        );

        self::registrar($import, $resultado, count($this->rows));
    }

    /**
     * O que um lote fez, somado ao que já havia.
     *
     * Em transação com a linha travada: quarenta e cinco jobs terminando ao
     * mesmo tempo, lendo e somando sem trava, perderiam contagens uns dos
     * outros — e o total nunca alcançaria o da planilha, deixando a barra
     * parada em 97% para sempre.
     *
     * Público porque a planilha pequena, cadastrada na própria requisição,
     * passa por aqui também: é o que deixa as recusadas dela disponíveis para
     * baixar, como as da fila.
     *
     * @param  array{created: int, admins: int, ignored?: int, errors: array<int, array{row: int, message: string, data?: array<string, mixed>}>}  $resultado
     */
    public static function registrar(EntitlementImport $import, array $resultado, int $linhas): void
    {
        DB::transaction(function () use ($import, $resultado, $linhas) {
            $travado = EntitlementImport::lockForUpdate()->find($import->id);

            // Na tela, só a mensagem; os dados da linha vão para `failures`.
            $mensagens = array_map(
                fn (array $e) => ['row' => $e['row'], 'message' => $e['message']],
                $resultado['errors'],
            );

            $erros = array_slice(
                array_merge($travado->errors ?? [], $mensagens),
                0,
                EntitlementImport::MAX_ERROS,
            );

            foreach (array_chunk($resultado['errors'], 500) as $fatia) {
                $agora = now();

                EntitlementImportFailure::insert(array_map(fn (array $e) => [
                    'entitlement_import_id' => $travado->id,
                    'linha' => $e['row'],
                    'mensagem' => $e['message'],
                    // Célula com byte fora do UTF-8 (CSV salvo em Latin-1) não
                    // pode derrubar o registro do lote inteiro.
                    'dados' => json_encode($e['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ], $fatia));
            }

            $processadas = $travado->processed + $linhas;

            $travado->update([
                'processed' => $processadas,
                'created' => $travado->created + $resultado['created'],
                'admins' => $travado->admins + $resultado['admins'],
                'ignored' => $travado->ignored + ($resultado['ignored'] ?? 0),
                'failed' => $travado->failed + count($resultado['errors']),
                'errors' => $erros,
                // O último lote a fechar a conta é quem encerra. Sem carimbo, a
                // tela ficaria anunciando "importando" para sempre. Encerrada
                // antes (cancelada no meio de um lote), fica encerrada.
                'finished_at' => $travado->finished_at ?? ($processadas >= $travado->total ? now() : null),
            ]);
        });
    }

    public function failed(Throwable $e): void
    {
        /*
         * Job que esgotou as tentativas não pode deixar o contador parado: a
         * tela mostraria uma importação eternamente em andamento. As linhas
         * dele entram como processadas e o motivo vai para a lista de erros.
         */
        $import = EntitlementImport::find($this->importId);

        if ($import === null) {
            return;
        }

        // Cada linha do bloco vira uma recusada, com os dados: assim elas
        // entram na planilha das recusadas e podem ser enviadas de novo.
        // Quem já tinha entrado antes da falha é pulado no reenvio (já está
        // no Projeto).
        $motivo = 'Falha ao processar o bloco desta linha: '.$e->getMessage();

        $recusadas = [];

        foreach (array_values($this->rows) as $i => $row) {
            $dados = array_diff_key($row, [BatchRegisterEndCustomers::REPETIDA_DA_LINHA => true]);

            if (implode('', array_map(fn ($v) => trim((string) $v), $dados)) !== '') {
                $recusadas[] = ['row' => $this->primeiraLinha + $i, 'message' => $motivo, 'data' => $dados];
            }
        }

        self::registrar($import, ['created' => 0, 'admins' => 0, 'errors' => $recusadas], count($this->rows));

        Log::error('Lote da importação de Colaboradores falhou.', [
            'import_id' => $this->importId,
            'primeira_linha' => $this->primeiraLinha,
            'erro' => $e->getMessage(),
        ]);
    }
}
