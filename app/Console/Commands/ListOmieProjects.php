<?php

namespace App\Console\Commands;

use App\Contracts\Integrations\OmieGateway;
use App\Services\FluxoA\OmieProjectMarker;
use App\Support\Projects\ProjectTemplate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cadastro de Projetos da Omie — código, nome e qual deles vira Projeto WeLog.
 *
 * Existe porque o filtro de `OMIE_PROJECT_CODES` é um número opaco: sem esta
 * tela, "7407717220" no `.env` é folclore que ninguém confere e ninguém sabe
 * atualizar quando a Santa Origem criar um projeto novo.
 *
 * Somente leitura. Passa pelo throttle normal do gateway.
 */
class ListOmieProjects extends Command
{
    protected $signature = 'welog:omie:projetos {--todos : Inclui os projetos inativos}';

    protected $description = 'Lista o cadastro de Projetos da Omie e mostra quais viram Projeto WeLog';

    public function handle(OmieGateway $omie, OmieProjectMarker $marker): int
    {
        $driver = config('integrations.omie.driver');
        $this->line("Driver: <options=bold>{$driver}</>");

        if ($driver !== 'api') {
            $this->warn('Em `fake` a lista é fixture — não reflete a conta real.');
        }

        try {
            $projetos = $this->todasAsPaginas($omie);
        } catch (Throwable $e) {
            $this->error('Falha ao consultar a Omie: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($projetos === []) {
            $this->warn('A conta não tem nenhum Projeto cadastrado.');

            return self::SUCCESS;
        }

        $configurados = $marker->projectCodes();

        $this->newLine();
        $this->table(
            // O template entra na tabela porque é a outra metade da
            // configuração: aceitar o projeto e dar a ele o card certo são a
            // mesma decisão, e conferir as duas em telas separadas é como um
            // projeto aceito nasce com o card errado sem ninguém notar.
            ['Código', 'Nome', 'Situação', 'Vira Projeto WeLog?', 'Template do card'],
            array_map(fn (array $p) => [
                $p['codigo'],
                $p['nome'],
                $p['inativo'] ? 'inativo' : 'ativo',
                $this->veredito($p['codigo'], $configurados),
                ProjectTemplate::forOmieProjectCode($p['codigo'])?->label() ?? '<fg=gray>—</>',
            ], $projetos),
        );

        $this->newLine();
        $this->explicar($configurados, $marker);

        return self::SUCCESS;
    }

    /** @return array<int, array{codigo: string, nome: string, inativo: bool}> */
    private function todasAsPaginas(OmieGateway $omie): array
    {
        $projetos = [];
        $pagina = 1;

        do {
            $lote = $omie->listProjects($pagina);
            $projetos = array_merge($projetos, $lote['items']);
            $paginas = (int) ($lote['total_pages'] ?? 1);
            $pagina++;
        } while ($pagina <= $paginas);

        if (! $this->option('todos')) {
            $projetos = array_values(array_filter($projetos, fn (array $p) => ! $p['inativo']));
        }

        usort($projetos, fn (array $a, array $b) => strcasecmp($a['nome'], $b['nome']));

        return $projetos;
    }

    /** @param array<int, string> $configurados */
    private function veredito(string $codigo, array $configurados): string
    {
        if ($configurados === []) {
            return '— (filtro desligado)';
        }

        return in_array($codigo, $configurados, true) ? 'SIM' : 'não';
    }

    /** @param array<int, string> $configurados */
    private function explicar(array $configurados, OmieProjectMarker $marker): void
    {
        if ($configurados !== []) {
            $this->info('Filtro ativo por código de projeto: '.implode(', ', $configurados));

            return;
        }

        if ($marker->termos() !== []) {
            $this->warn('O filtro em vigor ainda é por TEXTO da observação: '.implode(', ', $marker->termos()));
            $this->line('  Texto livre erra por acento, caixa e esquecimento. Prefira o código:');
        } else {
            $this->warn('NENHUM filtro configurado — todo pedido de venda da conta vira Projeto WeLog.');
        }

        $this->line('  Copie o código da linha "Venda" para o .env e recarregue a configuração:');
        $this->line('    <options=bold>OMIE_PROJECT_CODES=7407717220</>');
        $this->line('    php artisan config:cache');
    }
}
