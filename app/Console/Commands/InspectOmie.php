<?php

namespace App\Console\Commands;

use App\Contracts\Integrations\OmieGateway;
use App\Services\FluxoA\OmieProjectMarker;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnóstico do mapeamento de campos contra a Omie REAL.
 *
 * O gateway foi escrito a partir da documentação pública e validado só contra
 * o driver `fake`. Este comando faz uma chamada de leitura por recurso e
 * confere, campo a campo, se o que o código espera existe na resposta de
 * verdade — é o jeito de achar divergências antes que virem dado errado no
 * banco (foi assim que se descobriu que o pedido não traz o bloco `cliente`).
 *
 * Somente leitura. Passa pelo throttle normal do gateway.
 */
class InspectOmie extends Command
{
    protected $signature = 'welog:omie:inspect
                            {--produto= : Código de um produto para inspecionar}
                            {--pedido= : Código de um pedido para inspecionar}
                            {--raw : Mostra o JSON completo do primeiro registro de cada recurso}';

    protected $description = 'Confere o mapeamento de campos do gateway contra a resposta real da Omie';

    public function handle(OmieGateway $omie): int
    {
        $driver = config('integrations.omie.driver');
        $this->line("Driver: <options=bold>{$driver}</>");

        if ($driver !== 'api') {
            $this->warn('Em `fake` este diagnóstico não vale nada — ele existe para comparar com a API real.');
        }

        $this->newLine();

        // Pedindo um recurso específico, só ele roda: varrer produtos e
        // estoque junto poluía a resposta e ainda gastava o rate limit da
        // Omie à toa (o REDUNDANT de 60s vinha daí).
        $soPedido = $this->option('pedido') !== null;
        $soProduto = $this->option('produto') !== null;
        $tudo = ! $soPedido && ! $soProduto;

        $ok = true;
        if ($tudo || $soPedido) {
            $ok = $this->inspecionarPedidos($omie) && $ok;
        }
        if ($tudo || $soProduto) {
            $ok = $this->inspecionarProdutos($omie) && $ok;
            $ok = $this->inspecionarEstoque($omie) && $ok;
        }

        $this->newLine();

        if ($ok) {
            $this->info('Todos os campos esperados foram encontrados.');

            return self::SUCCESS;
        }

        $this->error('Há campos ausentes — o código que depende deles vai gravar dado vazio ou errado.');
        $this->line('Ajuste o mapeamento em app/Integrations/Omie/OmieApiGateway.php e nos services que consomem.');

        return self::FAILURE;
    }

    private function inspecionarPedidos(OmieGateway $omie): bool
    {
        $this->line('<options=bold>── Pedidos de venda (ListarPedidos / ConsultarPedido)</>');

        $codigo = $this->option('pedido');

        // Com --pedido não há por que listar: a listagem é só para descobrir
        // um pedido qualquer quando nenhum foi indicado.
        if ($codigo === null) {
            try {
                $lista = $omie->listOrders(now()->subDays(30));
            } catch (Throwable $e) {
                return $this->falha($e);
            }

            $this->line('  páginas: '.($lista['total_pages'] ?? '?').'  ·  registros nesta página: '.count($lista['items']));

            $primeiro = $lista['items'][0] ?? null;
            $codigo = $primeiro['cabecalho']['codigo_pedido'] ?? $primeiro['omie_order_id'] ?? null;
        }

        if ($codigo === null) {
            $this->warn('  Nenhum pedido nos últimos 30 dias — nada para conferir.');

            return true;
        }

        try {
            $pedido = $omie->getOrder((string) $codigo);
        } catch (Throwable $e) {
            return $this->falha($e);
        }

        if ($pedido === null) {
            $this->warn("  Pedido {$codigo} não encontrado.");

            return true;
        }

        $this->dump($pedido);

        $ok = $this->conferir($pedido, [
            'cabecalho.numero_pedido' => 'nome e número do Projeto',
            'cabecalho.codigo_cliente' => 'vínculo com a empresa cliente',
            'cabecalho.data_previsao' => 'prazo de entrega do Projeto',
            'infoCadastro.dInc' => 'data da compra (ordered_at do Projeto)',
            'det' => 'itens/kits do Projeto',
        ]);

        $ok = $this->conferirProjetoDoPedido($pedido) && $ok;

        // `numero_pedido_cliente` é digitado por pedido — ausência é dado, não
        // defeito. A linha existe para confirmar o CAMINHO do campo quando ele
        // vier preenchido (é dele que sai o client_order_number do Projeto).
        $numeroCliente = data_get($pedido, 'informacoes_adicionais.numero_pedido_cliente');
        $this->line($numeroCliente !== null && $numeroCliente !== ''
            ? "  <fg=green>✓</> informacoes_adicionais.numero_pedido_cliente = {$this->resumo($numeroCliente)}"
            : '  <fg=yellow>·</> informacoes_adicionais.numero_pedido_cliente vazio neste pedido (campo opcional da Omie)');

        // O bloco `cliente` normalmente NÃO vem no ConsultarPedido; por isso o
        // gateway consulta o cadastro pelo código. Confirmamos que dá certo.
        if (($pedido['cliente']['cnpj'] ?? null) === null) {
            $this->line('  <fg=yellow>·</> pedido não traz `cliente` (esperado) — buscando o cadastro pelo código…');

            $cliente = $omie->getCustomer((string) ($pedido['cabecalho']['codigo_cliente'] ?? ''));

            if ($cliente === null || ($cliente['document'] ?? null) === null) {
                $this->line('  <fg=red>✗</> ConsultarCliente não devolveu CNPJ — a empresa do Projeto ficará sem documento');
                $ok = false;
            } else {
                $this->line("  <fg=green>✓</> cliente resolvido: {$cliente['name']} ({$cliente['document']})");
            }
        }

        return $ok;
    }

    /**
     * Onde o pedido carrega o código do Projeto da Omie.
     *
     * É o campo que decide se o pedido vira Projeto WeLog, então errar o
     * caminho não dá erro nenhum: simplesmente NENHUM pedido passaria no filtro
     * e a esteira pararia em silêncio. `informacoes_adicionais.codProj` é o que
     * a documentação indica; este bloco é o que confirma contra a conta real, e
     * varre o pedido inteiro quando não acha, para dizer onde o campo está de
     * fato em vez de só reprovar.
     */
    private function conferirProjetoDoPedido(array $pedido): bool
    {
        $marker = app(OmieProjectMarker::class);
        $codigo = $marker->projectCodeOf($pedido);

        if ($codigo !== '') {
            $this->line("  <fg=green>✓</> informacoes_adicionais.codProj = <options=bold>{$codigo}</> (classificação do pedido)");

            return true;
        }

        $achados = $this->procurarChave($pedido, 'proj');

        if ($achados === []) {
            $this->line('  <fg=yellow>·</> este pedido não tem projeto classificado (codProj vazio ou 0)');
            $this->line('    Isso é dado, não defeito — mas rode de novo com --pedido=<um pedido do projeto "Venda">');
            $this->line('    antes de ligar OMIE_PROJECT_CODES, ou o filtro barraria tudo.');

            return true;
        }

        $this->line('  <fg=red>✗</> `informacoes_adicionais.codProj` não existe neste pedido.');
        $this->line('    Campos com "proj" no nome encontrados na resposta real:');

        foreach ($achados as $caminho => $valor) {
            $this->line("      {$caminho} = ".json_encode($valor));
        }

        $this->line('    Ajuste `OmieProjectMarker::projectCodeOf()` para o caminho correto.');

        return false;
    }

    /**
     * Varredura recursiva por chave — só roda quando o caminho esperado falha,
     * para transformar "não achei" em "está aqui".
     *
     * @return array<string, mixed> caminho pontilhado => valor
     */
    private function procurarChave(array $dados, string $agulha, string $prefixo = ''): array
    {
        $achados = [];

        foreach ($dados as $chave => $valor) {
            $caminho = $prefixo === '' ? (string) $chave : "{$prefixo}.{$chave}";

            if (is_array($valor)) {
                $achados += $this->procurarChave($valor, $agulha, $caminho);

                continue;
            }

            if (str_contains(mb_strtolower((string) $chave), $agulha)) {
                $achados[$caminho] = $valor;
            }
        }

        return $achados;
    }

    private function inspecionarProdutos(OmieGateway $omie): bool
    {
        $this->newLine();
        $this->line('<options=bold>── Produtos (ListarProdutos / ConsultarProduto)</>');

        try {
            $lista = $omie->listProducts();
        } catch (Throwable $e) {
            return $this->falha($e);
        }

        $this->line('  páginas: '.$lista['total_pages'].'  ·  registros nesta página: '.count($lista['items']));

        $produto = $lista['items'][0] ?? null;

        if ($this->option('produto')) {
            try {
                $produto = $omie->findProductByCode($this->option('produto'));
            } catch (Throwable $e) {
                return $this->falha($e);
            }
        }

        if ($produto === null) {
            $this->warn('  Nenhum produto retornado.');

            return true;
        }

        $this->dump($produto);

        // MirrorOmieProduct::mirror aceita os dois nomes; basta um existir.
        return $this->conferirAlternativas($produto, [
            'código' => ['omie_code', 'codigo'],
            'nome' => ['name', 'descricao'],
        ]);
    }

    private function inspecionarEstoque(OmieGateway $omie): bool
    {
        $this->newLine();
        $this->line('<options=bold>── Estoque (PosicaoEstoque)</>');

        $codigo = $this->option('produto');

        if ($codigo === null) {
            try {
                $codigo = $omie->listProducts()['items'][0]['codigo']
                    ?? $omie->listProducts()['items'][0]['omie_code']
                    ?? null;
            } catch (Throwable $e) {
                return $this->falha($e);
            }
        }

        if ($codigo === null) {
            $this->warn('  Sem produto para consultar.');

            return true;
        }

        try {
            $posicoes = $omie->getStockPositions([(string) $codigo]);
        } catch (Throwable $e) {
            return $this->falha($e);
        }

        $saldo = $posicoes[$codigo] ?? null;

        if ($saldo === null) {
            $this->line("  <fg=red>✗</> sem saldo devolvido para {$codigo}");

            return false;
        }

        $this->line("  <fg=green>✓</> saldo de {$codigo}: {$saldo}");

        // Saldo 0 pode ser real, mas com o mapeamento errado o padrão também é 0
        if ((float) $saldo === 0.0) {
            $this->line('  <fg=yellow>!</> saldo zero — confirme na Omie se é real; o valor padrão em caso de campo ausente também é 0');
        }

        return true;
    }

    /** @param array<string, string> $campos caminho => para que serve */
    private function conferir(array $dados, array $campos): bool
    {
        $ok = true;

        foreach ($campos as $caminho => $uso) {
            $valor = data_get($dados, $caminho);
            $existe = $valor !== null && $valor !== '' && $valor !== [];

            $ok = $existe && $ok;
            $marca = $existe ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $amostra = $existe ? ' = '.$this->resumo($valor) : " (AUSENTE — {$uso})";

            $this->line("  {$marca} {$caminho}{$amostra}");
        }

        return $ok;
    }

    /** @param array<string, array<int, string>> $grupos rótulo => nomes aceitos */
    private function conferirAlternativas(array $dados, array $grupos): bool
    {
        $ok = true;

        foreach ($grupos as $rotulo => $chaves) {
            $encontrada = null;

            foreach ($chaves as $chave) {
                if (($dados[$chave] ?? null) !== null && $dados[$chave] !== '') {
                    $encontrada = $chave;
                    break;
                }
            }

            if ($encontrada === null) {
                $this->line("  <fg=red>✗</> {$rotulo}: nenhum de [".implode(', ', $chaves).'] presente');
                $ok = false;
            } else {
                $this->line("  <fg=green>✓</> {$rotulo}: `{$encontrada}` = ".$this->resumo($dados[$encontrada]));
            }
        }

        return $ok;
    }

    private function dump(array $registro): void
    {
        if (! $this->option('raw')) {
            $this->line('  chaves: '.implode(', ', array_keys($registro)));

            return;
        }

        $this->line(json_encode($registro, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function resumo(mixed $valor): string
    {
        if (is_array($valor)) {
            return count($valor).' item(ns)';
        }

        return str($valor)->limit(60)->toString();
    }

    private function falha(Throwable $e): bool
    {
        $this->line('  <fg=red>✗</> chamada falhou: '.$e->getMessage());

        return false;
    }
}
