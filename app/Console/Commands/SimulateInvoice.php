<?php

namespace App\Console\Commands;

use App\Models\AppnozImportRow;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Simula a Omie faturando pedidos do Fluxo B — para testar sem emitir nota.
 *
 *   php artisan welog:nf:simular --importacao=12
 *   php artisan welog:nf:simular --pedido=67518
 *   php artisan welog:nf:simular 301 302
 *
 * Nota oficial tem custo, e sem nota o pedido fica parado em "pedido criado":
 * o rastreio nunca seria exercitado em homologação. Aqui a linha recebe
 * exatamente o que o SyncOmieInvoices gravaria ao achar a nota na Omie —
 * chave, data de emissão, status `invoiced` — e mais a marca `nf_simulated`.
 * Dali em diante o rastreio segue com `welog:flash:rastrear`.
 *
 * Só roda com WELOG_SIMULATED_INVOICES=true. A Flash real não conhece a nota
 * simulada, então o rastreio dela só avança com FLASH_DRIVER=fake. O pedido
 * na Omie continua aberto: cancele-o lá ao fim do teste.
 */
class SimulateInvoice extends Command
{
    protected $signature = 'welog:nf:simular
                            {linhas?* : IDs das linhas da importação}
                            {--importacao= : Simula a NF de todas as linhas elegíveis desta importação}
                            {--pedido= : Número do pedido na Omie (o que aparece na tela)}';

    protected $description = 'Marca pedidos do Fluxo B como faturados com NF fictícia (homologação)';

    public function handle(): int
    {
        if (! config('integrations.simulated_invoices')) {
            $this->error('NF simulada desligada. Ligue com WELOG_SIMULATED_INVOICES=true no .env (só em homologação).');

            return self::FAILURE;
        }

        $linhas = (array) $this->argument('linhas');
        $importacao = $this->option('importacao');
        $pedido = $this->option('pedido');

        if ($linhas === [] && ! $importacao && ! $pedido) {
            $this->error('Informe as linhas, --importacao=ID ou --pedido=NUMERO.');

            return self::FAILURE;
        }

        $alvo = AppnozImportRow::query()
            ->when($linhas !== [], fn (Builder $q) => $q->whereIn('id', $linhas))
            ->when($importacao, fn (Builder $q) => $q->where('appnoz_import_id', $importacao))
            ->when($pedido, fn (Builder $q) => $q->where('omie_order_number', $pedido))
            ->orderBy('id')
            ->get();

        if ($alvo->isEmpty()) {
            $this->error('Nenhuma linha encontrada.');

            return self::FAILURE;
        }

        $simuladas = 0;

        foreach ($alvo as $row) {
            // Só quem a Omie faturaria: pedido criado e ainda sem nota.
            if ($row->status !== AppnozImportRow::STATUS_ORDER_CREATED || $row->nf_key !== null) {
                $this->line("  <fg=yellow>pulada</> linha {$row->id} — status {$row->status}".($row->nf_key ? ', já tem NF' : ''));

                continue;
            }

            $row->update([
                'nf_key' => $row->simulatedNfKey(),
                'nf_simulated' => true,
                'nf_issued_at' => now(),
                'status' => AppnozImportRow::STATUS_INVOICED,
            ]);

            $simuladas++;
            $this->line("  <fg=green>faturada</> linha {$row->id} — pedido {$row->omie_order_number} — NF {$row->nf_key}");
        }

        $this->newLine();
        $this->info("{$simuladas} NF(s) simulada(s).");

        if ($simuladas > 0) {
            $this->line('Próximo passo: php artisan welog:flash:rastrear'
                .(config('integrations.flash.driver') === 'fake' ? '' : '  (com FLASH_DRIVER=fake — a Flash real não conhece nota simulada)'));
        }

        return self::SUCCESS;
    }
}
