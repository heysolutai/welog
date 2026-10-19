<?php

namespace App\Services\FluxoA;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use Illuminate\Support\Facades\Log;

/**
 * Fluxo A, etapa 7: a Omie sinaliza movimentação/disponibilidade de estoque.
 * Confirma a posição via API e marca o(s) Projeto(s) com estoque disponível
 * quando TODOS os itens do pedido têm saldo suficiente — o que habilita a
 * liberação do resgate pelo operador WeGift.
 */
class HandleOmieStockSignal
{
    public function __construct(
        private readonly OmieAccounts $accounts,
        /** Gateway fixo, para quem monta o serviço à mão (testes). */
        private readonly ?OmieGateway $omie = null,
    ) {}

    /**
     * @param  array<int, string>  $productCodes  códigos tocados pelo evento;
     *                                            vazio = reavaliar todos os projetos pendentes
     */
    public function handle(array $productCodes = [], ?string $account = null): void
    {
        // Saldo tambem e por CNPJ. Com o sinal vindo de uma conta, so os
        // Projetos daquela conta sao reavaliados: consultar o codigo de um
        // CNPJ na chave do outro devolve estoque zero e travaria a liberacao.
        $projects = Project::query()
            ->when($account !== null, fn ($q) => $q->where(
                // Projeto gravado antes do multi-CNPJ tem conta nula: ele
                // pertence à conta padrão, senão sairia da esteira sozinho.
                fn ($sub) => $sub->where('omie_account', $account)
                    ->when(
                        $account === $this->accounts->defaultName(),
                        fn ($p) => $p->orWhereNull('omie_account'),
                    )
            ))
            // Projeto JÁ com estoque também entra: a variação de saldo na Omie
            // vale nos dois sentidos, e sem reavaliar quem está "Em Estoque" o
            // kit podia sumir do ERP com o portal seguindo aberto. Só
            // "Concluído" fica de fora — entrega terminada não se reabre.
            ->where('pipeline_status', '!=', Project::STATUS_DONE)
            ->when($productCodes !== [], fn ($q) => $q->whereHas(
                'products',
                fn ($p) => $p->whereIn('omie_code', $productCodes),
            ))
            ->with('products')
            ->get();

        if ($projects->isEmpty()) {
            return;
        }

        // Uma consulta por execução, não por projeto: dez Projetos do mesmo kit
        // geravam dez chamadas idênticas à Omie (que limita o consumo).
        $codes = $projects
            ->flatMap(fn (Project $project) => $project->products->pluck('omie_code'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            return;
        }

        $positions = ($this->omie ?? $this->accounts->gateway($account))->getStockPositions($codes);

        $this->mirrorCatalogStock($positions);

        foreach ($projects as $project) {
            $this->evaluateProject($project, $positions);
        }
    }

    /**
     * Reavalia o estoque de UM Projeto sob demanda.
     *
     * O caso é a mudança manual de estágio para "Em Estoque": o operador
     * acabou de afirmar que o kit existe, e o espelho local
     * (products.stock_qty) precisa ficar fresco na hora — é ele que a
     * liberação (ReleaseProject) consulta para abrir o consumo de saldo.
     *
     * Mesmo relatório EM LOTE do sinal, numa chamada só: consultar kit a kit
     * faz a Omie punir a conta por consumo redundante.
     */
    public function refreshProject(Project $project): void
    {
        $project->loadMissing('products');

        $codes = $project->products
            ->pluck('omie_code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            return;
        }

        // A consulta sai da conta (CNPJ) do próprio Projeto: o saldo de um
        // código na chave do outro CNPJ é sempre zero.
        $positions = ($this->omie ?? $this->accounts->gateway($project->omie_account))
            ->getStockPositions($codes);

        $this->mirrorCatalogStock($positions);

        // Mesma régua do sinal: reavalia nos dois sentidos (promove com saldo,
        // tira do estoque sem saldo), exceto o Projeto concluído.
        if ($project->pipeline_status !== Project::STATUS_DONE) {
            $this->evaluateProject($project, $positions);
        }
    }

    /** Espelha o último saldo conhecido no catálogo local. */
    private function mirrorCatalogStock(array $positions): void
    {
        foreach ($positions as $code => $qty) {
            Product::where('omie_code', $code)->update([
                'stock_qty' => $qty,
                'stock_synced_at' => now(),
            ]);
        }
    }

    /** @param array<string, float> $positions */
    private function evaluateProject(Project $project, array $positions): void
    {
        if ($project->products->isEmpty()) {
            return;
        }

        $faltando = $this->itensSemSaldo($project, $positions);

        if ($faltando === []) {
            // O sinal de estoque é o que move o Projeto para "Em Estoque" — é
            // aqui que o Trello entrega o bastão. Só promove quem ainda está
            // ANTES do estoque: Projeto em Envio ou Concluído não é puxado
            // para trás na esteira por uma reavaliação de saldo.
            $project->update([
                'stock_available_at' => now(),
                'pipeline_status' => $project->isBeforeStock()
                    ? Project::STATUS_IN_STOCK
                    : $project->pipeline_status,
            ]);

            // O saldo do Projeto nasce aqui, uma vez: daqui em diante quem
            // manda nele é a WeLog, e o número da Omie vira referência. O
            // método ignora item já carregado, então reavaliação de saldo não
            // desfaz as baixas dos resgates.
            $project->carregarEstoqueDoProjeto();

            Log::info('Projeto com estoque disponível — liberação habilitada', [
                'project_id' => $project->id,
            ]);

            return;
        }

        // Saldo NÃO cobre mais o que falta entregar. Quem ainda não tinha a
        // marca de estoque continua onde estava, e não há o que registrar.
        if ($project->stock_available_at === null) {
            return;
        }

        $this->regredir($project, $faltando);
    }

    /**
     * Itens do pedido cujo saldo na Omie não cobre o que AINDA FALTA entregar.
     *
     * O desconto do que já saiu é o que separa consumo de sumiço: numa
     * campanha de 100 kits com 60 resgatados, o saldo cai para 40 por causa
     * das próprias baixas — comparar com os 100 do pedido faria todo Projeto
     * bem-sucedido parecer desabastecido no meio do caminho. O que importa é
     * haver kit para quem ainda não resgatou.
     *
     * @param  array<string, float>  $positions
     * @return array<int, array{codigo: string, nome: string, falta: float, saldo: float}>
     */
    private function itensSemSaldo(Project $project, array $positions): array
    {
        $baixados = Redemption::query()
            ->where('project_id', $project->id)
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            // Só o que já virou saída na Omie: entre o resgate e o job de
            // baixa o saldo de lá ainda está cheio, e descontar antes da hora
            // contaria a mesma unidade duas vezes.
            ->whereNotNull('stock_pushed_at')
            ->selectRaw('product_id, SUM(COALESCE(quantity, 1)) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $faltando = [];

        foreach ($project->products as $product) {
            $restante = (float) $product->pivot->quantity - (float) ($baixados[$product->id] ?? 0);

            if ($restante <= 0) {
                continue;
            }

            $saldo = (float) ($positions[$product->omie_code] ?? 0);

            if ($saldo < $restante) {
                $faltando[] = [
                    'codigo' => (string) $product->omie_code,
                    'nome' => (string) $product->name,
                    'falta' => $restante,
                    'saldo' => $saldo,
                ];
            }
        }

        return $faltando;
    }

    /**
     * Tira o Projeto do estoque quando o saldo sumiu na Omie.
     *
     * A variação de estoque é da Omie, nos DOIS sentidos: se o kit saiu do
     * estoque por fora da plataforma (transferido, faturado, quebrado), manter
     * o Projeto em "Em Estoque" deixaria o portal aberto para um resgate que
     * não tem como ser entregue. Voltar para Montagem fecha o portal sozinho
     * (`isInStock`), que é o mesmo efeito da regressão pelo Trello.
     *
     * A liberação (`released_at`) NÃO é desfeita: ela é história, e refazê-la
     * quando o saldo voltar dispararia o e-mail de resgate para todo mundo de
     * novo. Assim que a Omie repuser, o Projeto volta para "Em Estoque" pelo
     * caminho normal e a campanha retoma de onde parou.
     *
     * @param  array<int, array{codigo: string, nome: string, falta: float, saldo: float}>  $faltando
     */
    private function regredir(Project $project, array $faltando): void
    {
        // "Concluído" é fim de linha: entrega terminada não volta para a
        // fábrica porque o saldo de hoje está baixo.
        if (! in_array($project->pipeline_status, Project::STOCKED_STATUSES, true)) {
            return;
        }

        $project->update([
            'stock_available_at' => null,
            'pipeline_status' => Project::STATUS_ASSEMBLY,
        ]);

        Log::channel('integrations')->warning('Projeto saiu do estoque: saldo na Omie não cobre o que falta entregar.', [
            'project_id' => $project->id,
            'omie_order_number' => $project->omie_order_number,
            'itens' => $faltando,
        ]);
    }
}
