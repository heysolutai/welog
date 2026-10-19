<?php

namespace App\Console\Commands;

use App\Integrations\Omie\OmieAccounts;
use App\Models\Project;
use App\Models\WebhookEvent;
use App\Services\FluxoA\OmieProjectMarker;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mostra as contas (CNPJs) da Omie configuradas: chave, URL do webhook e se a
 * credencial responde. É a conferência depois de mexer no `.env`.
 */
class OmieAccountsStatus extends Command
{
    protected $signature = 'welog:omie:contas {--testar : Faz uma chamada de leitura em cada conta}';

    protected $description = 'Lista as contas Omie configuradas, a URL de webhook de cada uma e o que já chegou por elas';

    public function handle(OmieAccounts $accounts): int
    {
        $this->line('Driver: <options=bold>'.config('integrations.omie.driver').'</>');
        $this->line($accounts->isMultiAccount()
            ? 'Contas configuradas: <options=bold>'.implode(', ', $accounts->names()).'</>'
            : 'Conta única (sem OMIE_ACCOUNTS): <options=bold>'.$accounts->defaultName().'</>');
        $this->newLine();

        $linhas = [];

        foreach ($accounts->names() as $conta) {
            $cred = $accounts->credentials($conta);
            $token = (string) ($cred['webhook_token'] ?? '');

            // O código de "Venda" é por conta: cada CNPJ tem o próprio cadastro
            // de Projetos na Omie. Mostrar aqui evita filtro cruzado silencioso.
            $codigos = app(OmieProjectMarker::class)->projectCodes($conta);

            $linhas[] = [
                $accounts->label($conta).($conta === $accounts->defaultName() ? ' (padrão)' : ''),
                $conta,
                $this->mascarar((string) ($cred['app_key'] ?? '')),
                $codigos !== [] ? implode(', ', $codigos) : '<fg=yellow>sem filtro</>',
                $token === '' ? '<fg=red>sem token</>' : url('/webhooks/omie/'.$token),
                WebhookEvent::where('source', WebhookEvent::SOURCE_OMIE)
                    ->where('omie_account', $conta)->count(),
                Project::where('omie_account', $conta)->count(),
            ];
        }

        $this->table(
            ['Conta', 'chave', 'app_key', 'Projeto Venda', 'URL do webhook', 'Eventos', 'Projetos'],
            $linhas,
        );

        // Projetos anteriores ao multi-CNPJ ficaram sem conta: pertencem à padrão.
        $semConta = Project::whereNull('omie_account')->whereNotNull('omie_order_id')->count();
        if ($semConta > 0) {
            $this->line("  {$semConta} Projeto(s) sem conta gravada (anteriores ao multi-CNPJ): tratados como '"
                .$accounts->defaultName()."'.");
        }

        if ($this->option('testar')) {
            $this->newLine();
            $this->line('<options=bold>Testando as credenciais (uma leitura por conta)</>');

            foreach ($accounts->names() as $conta) {
                try {
                    $r = $accounts->gateway($conta)->listOrders(now()->subDays(1));
                    $this->info('  ✓ '.$conta.': respondeu ('.count($r['items'] ?? []).' pedido(s) no último dia)');
                } catch (Throwable $e) {
                    $this->error('  ✗ '.$conta.': '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }

    private function mascarar(string $valor): string
    {
        if ($valor === '') {
            return '<fg=red>vazia</>';
        }

        return strlen($valor) <= 8 ? str_repeat('*', strlen($valor)) : substr($valor, 0, 4).'...'.substr($valor, -4);
    }
}
