<?php

namespace App\Console\Commands;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Models\Project;
use App\Services\FluxoA\OmieProjectMarker;
use Illuminate\Console\Command;
use Throwable;

/**
 * Responde UMA pergunta: este pedido é do projeto Venda ou não?
 *
 *   php artisan welog:omie:pedido 67518
 *
 * Aceita o número do pedido (o que aparece na lista de Projetos) ou o id
 * interno da Omie. Faz uma única chamada (ConsultarPedido) e, se a Omie
 * devolver o bloqueio de consulta repetida (REDUNDANT, "aguarde N segundos"),
 * espera sozinho e tenta de novo, em vez de despejar o erro na tela.
 *
 * O `welog:omie:inspect` continua existindo para depurar MAPEAMENTO de campos;
 * este aqui é a consulta do dia a dia.
 */
class CheckOmieOrder extends Command
{
    protected $signature = 'welog:omie:pedido
                            {pedido : Número do pedido (ex.: 67518) ou id interno da Omie}
                            {--conta= : Conta (CNPJ) da Omie. Padrão: a do Projeto, ou a primeira configurada}';

    protected $description = 'Consulta um pedido na Omie e diz se ele é do projeto Venda (codProj)';

    public function handle(OmieProjectMarker $marker, OmieAccounts $accounts): int
    {
        if (config('integrations.omie.driver') !== 'api') {
            $this->error('OMIE_DRIVER precisa ser "api" para consultar a Omie de verdade.');

            return self::FAILURE;
        }

        $entrada = trim((string) $this->argument('pedido'));

        // O número que a lista mostra (67518) é o numero_pedido; o ConsultarPedido
        // usa o id interno. Quando o pedido já virou Projeto WeLog, o id está no
        // banco; senão, tentamos a entrada como id mesmo.
        $projetoLocal = Project::where('omie_order_number', $entrada)->first();
        $id = $projetoLocal?->omie_order_id ?? $entrada;

        /*
         * Qual CNPJ consultar: o que foi pedido, o do Projeto já conhecido, ou
         * a conta padrão. Consultar na conta errada devolve "não encontrado",
         * então a conta usada vai impressa na saída.
         */
        try {
            $conta = $accounts->resolveName($this->option('conta') ?: $projetoLocal?->omie_account);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $omie = $accounts->gateway($conta);
        $pedido = $this->consultar($omie, $id);

        if ($pedido === null) {
            $this->error("Pedido {$entrada} não encontrado na conta {$accounts->label($conta)} (consultei pelo id {$id}).");
            $this->line('  Se o pedido é de OUTRO CNPJ, repita com --conta=<'.implode('|', $accounts->names()).'>.');
            $this->line('  Se você digitou o NÚMERO do pedido e ele ainda não existe no WeLog, use o id interno da Omie.');

            return self::FAILURE;
        }

        $numero = $pedido['cabecalho']['numero_pedido'] ?? $entrada;
        $codigo = $marker->projectCodeOf($pedido);
        $configurados = $marker->projectCodes($conta);

        $this->newLine();
        $this->line("Pedido <options=bold>{$numero}</> (id Omie {$id})");
        $this->line('  Conta consultada: <options=bold>'.$accounts->label($conta)."</> ({$conta})");
        $this->line('  Projeto no pedido (codProj): '.($codigo !== '' ? "<options=bold>{$codigo}</>" : '<fg=yellow>sem classificação</>'));
        $this->line('  Projeto(s) Venda desta conta: '.($configurados !== [] ? implode(', ', $configurados) : '<fg=yellow>nenhum configurado</>'));

        $this->newLine();

        if (! $marker->configured($conta)) {
            $this->warn('Sem código de Venda configurado para esta conta, o WeLog aceita qualquer pedido dela.');
            $this->line('  Configure OMIE_'.strtoupper($conta).'_PROJECT_CODES (ou o OMIE_PROJECT_CODES global).');

            return self::SUCCESS;
        }

        if ($marker->matches($pedido, $conta)) {
            $this->info('✓ É VENDA: este pedido pertence ao projeto configurado e vira Projeto no WeLog.');
        } else {
            $this->error('✗ NÃO é Venda: '.($codigo === ''
                ? 'o pedido está sem projeto classificado na Omie.'
                : "o pedido está no projeto {$codigo}, que não é o configurado."));
            $this->line('  A classificação se corrige na própria Omie (campo Projeto do pedido). O WeLog só lê.');
        }

        // Situação local, para fechar o quadro completo.
        $projeto = Project::where('omie_order_number', (string) $numero)
            ->orWhere('omie_order_id', (string) $id)->first();
        $this->line($projeto
            ? "  No WeLog: Projeto #{$projeto->id} ({$projeto->name})."
            : '  No WeLog: este pedido não virou Projeto (ainda).');

        return self::SUCCESS;
    }

    /**
     * ConsultarPedido com tolerância ao REDUNDANT: a Omie recusa a mesma
     * consulta repetida em menos de 60s e manda esperar. Esperamos.
     */
    private function consultar(OmieGateway $omie, string $id): ?array
    {
        foreach ([1, 2] as $tentativa) {
            try {
                return $omie->getOrder($id);
            } catch (Throwable $e) {
                if ($tentativa === 2 || ! preg_match('/Aguarde (\d+) segundos/i', $e->getMessage(), $m)) {
                    throw $e;
                }

                $espera = min((int) $m[1] + 2, 90);
                $this->line("  A Omie bloqueou a consulta repetida. Aguardando {$espera}s para tentar de novo...");
                sleep($espera);
            }
        }

        return null;
    }
}
