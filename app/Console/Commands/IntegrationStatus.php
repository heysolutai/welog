<?php

namespace App\Console\Commands;

use App\Integrations\Support\ApiCircuitBreaker;
use Illuminate\Console\Command;

/**
 * Estado das integrações: quem está pausado, por quê e por quanto tempo.
 *
 * Existe porque "não cria Projeto nenhum" tem causas muito diferentes — chave
 * suspensa na Omie, fila sem worker, filtro de projeto — e sem um lugar que
 * responda isso a investigação começa adivinhando.
 */
class IntegrationStatus extends Command
{
    protected $signature = 'welog:integracoes:status
        {--retomar= : Fecha a pausa da integração informada (omie|trello) e volta a chamar}';

    protected $description = 'Mostra (ou retoma) integrações pausadas por recusa do provedor';

    private const INTEGRACOES = ['omie', 'trello', 'correios', 'flash'];

    public function handle(): int
    {
        if ($retomar = $this->option('retomar')) {
            ApiCircuitBreaker::close($retomar);
            $this->info("Integração {$retomar} retomada: as chamadas voltam a sair.");
            $this->line('  Se o provedor ainda estiver recusando, ela pausa de novo sozinha.');

            return self::SUCCESS;
        }

        $linhas = [];

        foreach (self::INTEGRACOES as $integracao) {
            $faltam = ApiCircuitBreaker::remaining($integracao);

            $linhas[] = [
                $integracao,
                $faltam === null ? '<fg=green>ativa</>' : '<fg=red>pausada</>',
                $faltam === null ? '—' : $faltam.'s',
            ];
        }

        $this->table(['Integração', 'Situação', 'Volta em'], $linhas);

        $pausadas = array_filter(self::INTEGRACOES, fn ($i) => ApiCircuitBreaker::remaining($i) !== null);

        if ($pausadas === []) {
            $this->line('Nenhuma integração pausada.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Pausa = o provedor recusou o acesso e a plataforma parou de insistir.');
        $this->line('  Insistir prolongaria o bloqueio do outro lado, então isto é proposital.');
        $this->line('  Resolva a causa no painel do provedor (chave/aplicativo suspenso) e, se');
        $this->line('  quiser voltar antes do prazo: <options=bold>php artisan welog:integracoes:status --retomar=omie</>');

        return self::SUCCESS;
    }
}
