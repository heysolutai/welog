<?php

namespace App\Console\Commands;

use App\Exceptions\DomainException;
use App\Models\AppnozImport;
use App\Services\FluxoB\ImportAppnozFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fluxo B, etapa 4: consome os relatórios appnoz que o operador WeGift salvou
 * nos diretórios de entrada. Arquivos processados são movidos para o diretório
 * de histórico, com a data no nome.
 *
 * Os diretórios são PARAMETRIZADOS (`integrations.appnoz.inbox_directories`),
 * não fixos: o Anexo I fala em "diretório específico previamente definido", e
 * previamente definido é acordo entre pessoas — uma linha de produto nova ou um
 * caminho renomeado não podem exigir deploy.
 */
class ConsumeAppnozDirectory extends Command
{
    protected $signature = 'welog:consume-appnoz-directory
        {--dir=* : Varre apenas estes diretórios, ignorando a parametrização}';

    protected $description = 'Varre os diretórios ativos e importa os relatórios de resgate appnoz pendentes (Importações Parceiros)';

    public function handle(ImportAppnozFile $import): int
    {
        $disk = Storage::disk('local');
        $processados = (string) config('integrations.appnoz.processed_directory', 'appnoz-processed');
        $disk->makeDirectory($processados);

        $diretorios = $this->diretorios();

        if ($diretorios === []) {
            $this->warn('Nenhum diretório ativo parametrizado (APPNOZ_INBOX_DIRS).');

            return self::SUCCESS;
        }

        $encontrados = 0;

        foreach ($diretorios as $diretorio) {
            $disk->makeDirectory($diretorio);

            $arquivos = array_filter(
                $disk->files($diretorio),
                fn (string $f) => preg_match('/\.(xlsx|csv)$/i', $f),
            );

            if ($arquivos === []) {
                $this->line("{$diretorio}: nada pendente.");

                continue;
            }

            $encontrados += count($arquivos);

            foreach ($arquivos as $arquivo) {
                $this->info("Importando {$arquivo}…");

                try {
                    $resultado = $import->handle($disk->path($arquivo), AppnozImport::SOURCE_DIRECTORY);
                    $this->info("→ {$resultado->rows_created} pedidos criados, {$resultado->rows_blocked} bloqueados.");
                } catch (DomainException $e) {
                    // Arquivo repetido ou ilegível não pode parar a varredura:
                    // as outras pastas seguem, e o arquivo sai do caminho para
                    // não ser relido amanhã.
                    $this->warn("→ Ignorado: {$e->getMessage()}");
                }

                $disk->move(
                    $arquivo,
                    $processados.'/'.now()->format('Ymd-His').'-'.basename($arquivo),
                );
            }
        }

        $this->info("Varredura concluída: {$encontrados} arquivo(s) em ".count($diretorios).' diretório(s).');

        return self::SUCCESS;
    }

    /**
     * `--dir` existe para reprocessar uma pasta específica sem mexer no
     * ambiente — o agendamento sempre usa a parametrização.
     *
     * @return array<int, string>
     */
    private function diretorios(): array
    {
        $informados = array_filter(array_map('trim', (array) $this->option('dir')));

        if ($informados !== []) {
            return array_values($informados);
        }

        return (array) config('integrations.appnoz.inbox_directories', []);
    }
}
