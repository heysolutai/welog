<?php

namespace App\Console\Commands;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use App\Services\FluxoA\OmieProjectMarker;
use App\Services\Projects\ProjectPurger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Apaga os Projetos WeLog cujo pedido na Omie NÃO é do projeto configurado
 * (`OMIE_PROJECT_CODES` — "Venda"), deixando só o que deveria ter entrado.
 *
 * É o par do `welog:omie:sync-projects`: o sync CRIA o que falta e nunca
 * apaga (roda sozinho de hora em hora — um filtro mal configurado num deploy
 * não pode varrer o banco em silêncio). Este comando é a exclusão, separada e
 * manual, para limpar o que entrou antes de existir filtro.
 *
 * ── Uma consulta em lote, não uma por Projeto ────────────────────────────────
 *
 * O `ListarPedidos` já traz `informacoes_adicionais.codProj` de cada pedido.
 * Uma varredura paginada (~90 chamadas para 90 dias) classifica TODOS os
 * Projetos de uma vez. A alternativa — `ConsultarPedido` por Projeto, como o
 * `purge-unmarked` faz — custaria uma chamada por linha (1.700+), que é o
 * consumo que já levou a Omie a bloquear a conta.
 *
 * ── O que FICA, sempre ───────────────────────────────────────────────────────
 *
 *   · Projeto do projeto Omie configurado (é o objetivo).
 *   · Projeto sem `omie_order_id` (criado à mão; a Omie não sabe dele).
 *   · Projeto cujo pedido NÃO apareceu na listagem (fora da janela de datas
 *     ou sumiu da Omie) — não classificado não é "não é Venda"; apagar por
 *     ausência é irreversível. Aumente `--dias` para cobri-los.
 *   · Projeto com resgate, a menos que `--incluir-usados`: alguém já usou;
 *     apagar isso não é limpeza de integração, é perda de histórico.
 *
 * Por padrão é SIMULAÇÃO. `--execute` apaga; em produção ainda pede
 * confirmação (`--force` pula, obrigatório sem TTY).
 */
class PurgeNonVendaProjects extends Command
{
    use ConfirmableTrait;

    protected $signature = 'welog:projects:purge-non-venda
        {--desde= : Data inicial da listagem na Omie (YYYY-MM-DD). Padrão: --dias atrás}
        {--dias=365 : Janela em dias quando --desde não é informado}
        {--incluir-usados : Apaga também Projetos que já têm resgate}
        {--execute : Executa de verdade; sem isto é apenas simulação}
        {--force : Pula a confirmação de produção (obrigatório com docker exec -T, que não tem TTY)}';

    protected $description = 'Apaga os Projetos cujo pedido Omie não é do projeto configurado (OMIE_PROJECT_CODES)';

    public function handle(OmieGateway $omie, OmieProjectMarker $marker, ProjectPurger $purger): int
    {
        if ($marker->projectCodes() === []) {
            $this->error('OMIE_PROJECT_CODES vazio — sem ele não há como saber o que é Venda.');
            $this->line('  Descubra o código com <options=bold>php artisan welog:omie:projetos</> e defina no .env.');

            return self::FAILURE;
        }

        $this->line('Driver Omie: <options=bold>'.config('integrations.omie.driver').'</>');
        $this->line('Mantendo pedidos dos projetos Omie: <options=bold>'.implode(', ', $marker->projectCodes()).'</>');

        try {
            $since = $this->option('desde')
                ? Carbon::createFromFormat('Y-m-d', (string) $this->option('desde'))->startOfDay()
                : now()->subDays((int) $this->option('dias'))->startOfDay();
        } catch (Throwable) {
            $this->error('--desde precisa estar no formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        $this->line('Listando pedidos na Omie desde <options=bold>'.$since->format('d/m/Y').'</>…');

        try {
            $listagem = $this->listar($omie, $since);
        } catch (Throwable $e) {
            $this->error('Falha ao consultar a Omie: '.$e->getMessage());
            $this->line('  Nada foi apagado. Se for bloqueio por consumo ("Aguarde N segundos"), espere e rode de novo.');

            return self::FAILURE;
        }

        $this->line('  '.$listagem->count().' pedido(s) listado(s).');

        // ── Classificação ───────────────────────────────────────────────────
        $projetos = Project::orderBy('id')->get(['id', 'name', 'omie_order_id', 'omie_order_number']);

        $manter = [];
        $apagar = [];
        $motivos = ['venda' => 0, 'manual' => 0, 'nao_listado' => 0, 'usado' => 0];

        foreach ($projetos as $projeto) {
            if ($projeto->omie_order_id === null) {
                $manter[] = $projeto->id;
                $motivos['manual']++;

                continue;
            }

            $pedido = $listagem->get((string) $projeto->omie_order_id);

            if ($pedido === null) {
                $manter[] = $projeto->id;
                $motivos['nao_listado']++;

                continue;
            }

            if ($marker->matches($pedido)) {
                $manter[] = $projeto->id;
                $motivos['venda']++;

                continue;
            }

            if (! $this->option('incluir-usados') && $projeto->redemptions()->exists()) {
                $manter[] = $projeto->id;
                $motivos['usado']++;

                continue;
            }

            $apagar[] = $projeto;
        }

        $this->newLine();
        $this->table(['Ficam', 'Qtde'], [
            ['Do projeto Omie configurado (Venda)', $motivos['venda']],
            ['Sem pedido Omie (criados à mão)', $motivos['manual']],
            ['Pedido não apareceu na listagem (fora da janela / sumiu)', $motivos['nao_listado']],
            ['Fora do Venda, mas com resgate (use --incluir-usados)', $motivos['usado']],
        ]);

        if ($apagar === []) {
            $this->info('Nenhum Projeto fora do filtro para apagar.');

            return self::SUCCESS;
        }

        if ($motivos['nao_listado'] > 0) {
            $this->warn($motivos['nao_listado'].' Projeto(s) não puderam ser classificados — aumente --dias para cobri-los.');
        }

        // Nome e pedido: confirmar exclusão irreversível olhando só para ids
        // é confirmar no escuro. Amostra, não a lista inteira de 1.600 linhas.
        $this->newLine();
        $this->line('Serão apagados <options=bold>'.count($apagar).'</> Projeto(s). Amostra:');
        foreach (array_slice($apagar, 0, 15) as $p) {
            $cod = $marker->projectCodeOf($listagem->get((string) $p->omie_order_id)) ?: '(sem projeto)';
            $this->line("  · #{$p->id}  pedido {$p->omie_order_number}  projeto Omie {$cod}  {$p->name}");
        }
        if (count($apagar) > 15) {
            $this->line('  … e mais '.(count($apagar) - 15).'.');
        }

        $inventario = $purger->inventariar($manter);

        $this->newLine();
        $this->table(['Será apagado', 'Qtde'], [
            ['Projetos', $inventario['projetos']],
            ['  └ autorizações de resgate', $inventario['entitlements']],
            ['  └ resgates', $inventario['resgates']],
            ['     └ eventos de rastreio', $inventario['rastreios']],
            ['Usuários cliente final órfãos', $inventario['usuarios']],
            ['Empresas sem Projeto e sem usuário', $inventario['empresas']],
        ]);

        if (! $this->option('execute')) {
            $this->newLine();
            $this->warn('SIMULAÇÃO — nada foi apagado.');
            $this->line('  Confira o backup antes: <options=bold>php artisan welog:backup:status</>');
            $this->line('  Para executar de verdade, repita com <options=bold>--execute</>.');

            return self::SUCCESS;
        }

        // Duas travas separadas, como no `welog:projects:purge`: `--execute`
        // liga a exclusão; `--force` pula a confirmação de produção.
        if (! $this->confirmToProceed('Isto apaga dados de forma IRREVERSÍVEL')) {
            return self::FAILURE;
        }

        $apagados = $purger->apagar($manter);

        $this->newLine();
        $this->info('Concluído.');
        $this->table(['Tabela', 'Apagados'], array_map(
            fn ($chave, $valor) => [$chave, $valor],
            array_keys($apagados),
            $apagados,
        ));

        if ($inventario['empresas_com_usuario'] > 0) {
            $this->newLine();
            $this->warn($inventario['empresas_com_usuario'].' empresa(s) sem Projeto NÃO foram apagadas: ainda têm usuário vinculado.');
        }

        return self::SUCCESS;
    }

    /**
     * Todas as páginas do `ListarPedidos`, indexadas por `codigo_pedido`
     * (o `omie_order_id` do Projeto).
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function listar(OmieGateway $omie, Carbon $since): Collection
    {
        $pedidos = collect();
        $page = 1;

        do {
            $result = $omie->listOrders($since, $page);

            foreach ($result['items'] as $order) {
                $id = (string) ($order['omie_order_id'] ?? $order['cabecalho']['codigo_pedido'] ?? '');

                if ($id !== '') {
                    // Só o que o marcador lê. O pedido inteiro (itens, cliente,
                    // impostos) vezes milhares de linhas estoura a memória.
                    $pedidos->put($id, [
                        'cabecalho' => ['numero_pedido' => $order['cabecalho']['numero_pedido'] ?? null],
                        'informacoes_adicionais' => ['codProj' => $order['informacoes_adicionais']['codProj'] ?? null],
                        'observacoes' => ['obs_venda' => $order['observacoes']['obs_venda'] ?? null],
                    ]);
                }
            }

            $page++;
        } while ($page <= ($result['total_pages'] ?? 1));

        return $pedidos;
    }
}
