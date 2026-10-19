<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\WebhookEvent;
use App\Services\FluxoA\OmieProjectMarker;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Varredura dos Projetos já criados.
 *
 * Existe porque uma sequência de defeitos deixou rastro em dados que já
 * estavam no banco: a fila ficou dias sem processar (o worker não conectava),
 * a reconciliação caiu 243 vezes no teto de consumo da Omie, e a estrutura dos
 * kits nunca foi lida porque o `ListarProdutos` não a devolve. Consertar o
 * código não conserta o que já entrou torto — e sem uma varredura não dá para
 * afirmar que arrumou.
 *
 * É SÓ LEITURA: aponta onde olhar e diz o que roda para corrigir cada caso.
 */
class AuditProjects extends Command
{
    protected $signature = 'welog:projects:audit {--ids : Lista os ids de cada achado}';

    protected $description = 'Varre os Projetos criados e aponta o que ficou inconsistente';

    public function handle(): int
    {
        $total = Project::count();

        $emUso = Product::whereHas('projects');

        $this->newLine();
        $this->line("<options=bold>{$total} Projetos</> · ".(clone $emUso)->count()
            .' produtos em uso · '.Company::count().' Empresas');
        // Contagem informativa, fora da tabela: kit com estrutura é o estado
        // BOM, e listá-lo entre as pendências marcaria o certo como problema.
        $this->line('  '.(clone $emUso)->has('components')->count().' kit(s) com estrutura conhecida · '
            .(clone $emUso)->whereNotNull('structure_synced_at')->doesntHave('components')->count()
            .' produto(s) simples confirmados');
        $this->newLine();

        $achados = $this->achados();
        $linhas = [];
        $problemas = 0;

        foreach ($achados as $achado) {
            $quantidade = $achado['query']()->count();
            $problemas += $quantidade > 0 ? 1 : 0;

            $linhas[] = [
                $quantidade > 0 ? '⚠' : '✓',
                $achado['titulo'],
                $quantidade,
                $quantidade > 0 ? $achado['acao'] : '—',
            ];
        }

        $this->table(['', 'Verificação', 'Qtde', 'O que fazer'], $linhas);

        // Não é contagem, é configuração — e sem ela TODO pedido de venda da
        // conta vira Projeto WeLog. Fica em destaque para não passar em branco.
        $marker = app(OmieProjectMarker::class);

        if (! $marker->configured()) {
            $this->newLine();
            $this->error('OMIE_PROJECT_MARKER não configurado.');
            $this->line('  Sem ele, todo Pedido de Venda da conta Omie vira Projeto WeLog —');
            $this->line('  foram 532 pedidos só nos últimos 5 dias.');
            $this->line('  Prefira o cadastro de Projetos da Omie, que é campo escolhido numa');
            $this->line('  lista em vez de texto digitado:');
            $this->line('    php artisan welog:omie:projetos   → mostra os códigos da conta');
            $this->line('    OMIE_PROJECT_CODES=7407717220     → "Venda"');
            $problemas++;
        } elseif ($marker->criterio() === 'observacao') {
            // Configurado, mas pelo critério frágil: não é pendência de igual
            // gravidade, então avisa sem contar como problema.
            $this->newLine();
            $this->warn('O filtro em vigor é por TEXTO da observação do pedido.');
            $this->line('  Ele erra quando alguém digita diferente ou esquece de escrever.');
            $this->line('  O cadastro de Projetos da Omie é mais firme: `welog:omie:projetos`.');
        }

        if ($this->option('ids')) {
            $this->listarIds($achados);
        }

        if ($problemas === 0) {
            $this->info('Nada pendente.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment("{$problemas} verificação(ões) com pendência. Rode com --ids para ver os registros.");

        return self::SUCCESS;
    }

    /**
     * Cada verificação carrega a ação que a resolve: relatório que só aponta
     * problema empurra o trabalho de descobrir o "e agora?" para quem lê.
     *
     * @return array<int, array{titulo: string, acao: string, query: callable(): Builder}>
     */
    private function achados(): array
    {
        return [
            [
                'titulo' => 'Produto sem estrutura conferida na Omie',
                'acao' => 'welog:omie:sync-kit-structure',
                // O alvo é o produto, não o Projeto: é ele que carrega o BOM.
                // E a pergunta é "foi conferido?", não "tem componente?" — a
                // maioria do catálogo é produto simples e responderia "não"
                // para sempre.
                'query' => fn () => Product::whereHas('projects')->whereNull('structure_synced_at'),
            ],
            [
                'titulo' => 'Projeto sem kit vinculado',
                'acao' => 'conferir o pedido na Omie',
                'query' => fn () => Project::doesntHave('products'),
            ],
            [
                'titulo' => 'Projeto sem card no Trello',
                'acao' => 'conferir o mapeamento de listas',
                'query' => fn () => Project::whereNull('trello_card_id'),
            ],
            [
                'titulo' => 'Projeto sem prazo de entrega',
                'acao' => 'preencher a data na Omie',
                'query' => fn () => Project::whereNull('delivery_deadline'),
            ],
            [
                'titulo' => 'Projeto com quantidade zerada',
                'acao' => 'conferir os itens do pedido',
                'query' => fn () => Project::where('quantity', '<=', 0),
            ],
            [
                'titulo' => 'Estágio fora da esteira atual',
                'acao' => 'reexecutar a migração de estágios',
                'query' => fn () => Project::whereNotIn('pipeline_status', Project::PIPELINE_STATUSES),
            ],
            [
                // A regra nova é dupla trava; um Projeto liberado que não está
                // em estoque veio de antes dela ou regrediu depois de liberar.
                'titulo' => 'Liberado antes de "Em Estoque"',
                'acao' => 'conferir o estágio no Trello',
                'query' => fn () => Project::whereNotNull('released_at')
                    ->whereIn('pipeline_status', Project::PRE_STOCK_STATUSES),
            ],
            [
                'titulo' => 'Empresa sem CNPJ real (provisória)',
                'acao' => 'completar o cadastro da Empresa',
                'query' => fn () => Company::where('cnpj', 'like', 'omie-%')->orWhere('cnpj', 'sem-documento'),
            ],
            [
                'titulo' => 'Empresa sem Admin Cliente',
                'acao' => 'cadastrar pela tela de Empresas',
                'query' => fn () => Company::whereDoesntHave('users', fn ($q) => $q->where('role', 'admin_client')),
            ],
            [
                'titulo' => 'Webhook parado na fila',
                'acao' => 'conferir worker e reprocessar',
                'query' => fn () => WebhookEvent::where('status', 'received'),
            ],
        ];
    }

    /** @param array<int, array{titulo: string, acao: string, query: callable(): Builder}> $achados */
    private function listarIds(array $achados): void
    {
        foreach ($achados as $achado) {
            $ids = $achado['query']()->limit(50)->pluck('id');

            if ($ids->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->line("<options=bold>{$achado['titulo']}</>");
            $this->line('  ids: '.$ids->implode(', ').($ids->count() === 50 ? ' … (primeiros 50)' : ''));
        }
    }
}
