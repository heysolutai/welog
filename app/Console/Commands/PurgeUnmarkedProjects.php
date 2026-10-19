<?php

namespace App\Console\Commands;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use App\Services\FluxoA\OmieProjectMarker;
use Illuminate\Console\Command;
use Throwable;

/**
 * Identifica (e opcionalmente remove) os Projetos criados antes de existir a
 * marcação de Projeto WeLog.
 *
 * A conta da Omie atende a operação inteira da Santa Origem: sem filtro, todo
 * Pedido de Venda virou Projeto. O banco chegou a ~1600 Projetos onde deveria
 * haver dezenas.
 *
 * Consulta a observação de cada pedido na Omie, um a um, porque é o único
 * jeito de saber quem é quem depois do fato. Por isso é PAGINADO e pausado:
 * varrer 1600 pedidos de uma vez é exatamente o consumo que já levou a Omie a
 * bloquear a conta por 30 minutos.
 *
 * Por padrão NÃO apaga nada — só relata. Remover Projeto arrasta Usuários,
 * resgates e cards do Trello junto, e essa decisão não pode ser efeito
 * colateral de um comando de diagnóstico.
 */
class PurgeUnmarkedProjects extends Command
{
    protected $signature = 'welog:projects:purge-unmarked
        {--limit=50 : Quantos Projetos conferir nesta execução}
        {--sleep=1500 : Pausa entre consultas, em milissegundos}
        {--delete : Remove de fato os Projetos sem marcação (padrão: só relata)}';

    protected $description = 'Confere na Omie quais Projetos não têm a marcação WeLog e relata (ou remove)';

    public function handle(OmieGateway $omie, OmieProjectMarker $marker): int
    {
        if (! $marker->configured()) {
            $this->error('OMIE_PROJECT_MARKER não configurado — sem ele não há como separar o que é Projeto WeLog.');

            return self::FAILURE;
        }

        $projetos = Project::whereNotNull('omie_order_id')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($projetos->isEmpty()) {
            $this->info('Nenhum Projeto com pedido Omie para conferir.');

            return self::SUCCESS;
        }

        $this->line("Conferindo {$projetos->count()} Projeto(s) na Omie…");
        $barra = $this->output->createProgressBar($projetos->count());

        $semMarcacao = [];
        $comMarcacao = 0;

        foreach ($projetos as $projeto) {
            try {
                $pedido = $omie->getOrder($projeto->omie_order_id);
            } catch (Throwable $e) {
                $barra->finish();
                $this->newLine(2);
                $this->error('Interrompido: '.$e->getMessage());
                $this->line('Rode de novo mais tarde — nada foi removido nesta execução.');

                return self::FAILURE;
            }

            // Pedido que sumiu da Omie não é candidato a remoção automática:
            // pode ser erro de consulta, e apagar por ausência é irreversível.
            $pedido !== null && ! $marker->matches($pedido)
                ? $semMarcacao[] = $projeto
                : $comMarcacao++;

            $barra->advance();
            usleep((int) $this->option('sleep') * 1000);
        }

        $barra->finish();
        $this->newLine(2);

        $this->info(count($semMarcacao).' sem marcação · '.$comMarcacao.' legítimos (ou não conferidos).');

        if ($semMarcacao === []) {
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'Projeto', 'Pedido Omie', 'Usuários', 'Resgates'],
            collect($semMarcacao)->take(20)->map(fn (Project $p) => [
                $p->id,
                mb_substr($p->name, 0, 40),
                $p->omie_order_number,
                $p->entitlements()->count(),
                $p->redemptions()->count(),
            ])->all(),
        );

        if (! $this->option('delete')) {
            $this->newLine();
            $this->comment('Nada foi removido. Repita com --delete para apagar os listados acima.');

            return self::SUCCESS;
        }

        return $this->remover($semMarcacao);
    }

    /** @param array<int, Project> $projetos */
    private function remover(array $projetos): int
    {
        // Projeto com resgate não é lixo de integração: alguém já usou. Some
        // do lote de remoção mesmo com --delete, e é reportado.
        $comUso = array_filter($projetos, fn (Project $p) => $p->redemptions()->exists());
        $removiveis = array_filter($projetos, fn (Project $p) => ! $p->redemptions()->exists());

        if ($comUso !== []) {
            $this->warn(count($comUso).' Projeto(s) têm resgate e NÃO serão removidos: '
                .implode(', ', array_map(fn (Project $p) => $p->id, $comUso)));
        }

        if ($removiveis === [] || ! $this->confirm(count($removiveis).' Projeto(s) serão removidos. Confirma?', false)) {
            $this->line('Nada removido.');

            return self::SUCCESS;
        }

        foreach ($removiveis as $projeto) {
            $projeto->entitlements()->delete();
            $projeto->products()->detach();
            $projeto->delete();
        }

        $this->info(count($removiveis).' Projeto(s) removidos.');

        return self::SUCCESS;
    }
}
