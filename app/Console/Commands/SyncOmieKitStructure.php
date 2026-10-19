<?php

namespace App\Console\Commands;

use App\Integrations\Omie\OmieApiException;
use App\Integrations\Support\ApiThrottleException;
use App\Models\Product;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Preenche a estrutura (BOM) dos produtos já cadastrados.
 *
 * O `ListarProdutos` da Omie — que alimenta o `welog:omie:sync-catalog` — NÃO
 * devolve `componentes_kit`; só o `ConsultarProduto`, um por código. Por isso
 * o catálogo importado em lote nasce sem estrutura nenhuma, e um kit aparece
 * na tela do Projeto como item solto, lado a lado com o próprio conteúdo.
 *
 * O espelho passou a ler a estrutura, mas só age quando o produto é espelhado
 * de novo. Quem já estava no banco fica como estava — este comando é o
 * empurrão para esses.
 *
 * É retomável: produto já conferido é pulado, então rodar duas vezes não custa
 * chamada nem estraga nada.
 *
 * Os padrões são conservadores de propósito — 40 por vez, 1,5s entre consultas.
 * Medido na conta real: ~100 consultas seguidas ao `ConsultarProduto` bastam
 * para a Omie acionar o freio, e insistir depois disso escala para bloqueio da
 * conta por meia hora. Vale mais rodar cinco vezes ao longo do dia do que
 * derrubar a integração uma vez.
 */
class SyncOmieKitStructure extends Command
{
    protected $signature = 'welog:omie:sync-kit-structure
        {--code=* : Códigos específicos, para consertar um kit sem varrer o resto}
        {--limit=40 : Quantos produtos processar nesta execução}
        {--all : Percorre o catálogo inteiro, não só os produtos usados em Projetos}
        {--sleep=1500 : Pausa entre consultas, em milissegundos}';

    protected $description = 'Consulta a Omie produto a produto e preenche a estrutura dos kits já cadastrados';

    public function handle(MirrorOmieProduct $mirror): int
    {
        if (config('integrations.omie.driver') !== 'api') {
            $this->warn('OMIE_DRIVER está em `'.config('integrations.omie.driver').'` — os dados virão do gateway simulado.');
        }

        $produtos = $this->pendentes();

        if ($produtos->isEmpty()) {
            $this->info('Nada a fazer: todos os produtos do recorte já têm estrutura.');

            return self::SUCCESS;
        }

        $this->line("Consultando {$produtos->count()} produto(s) na Omie…");
        $barra = $this->output->createProgressBar($produtos->count());

        $kits = 0;
        $simples = 0;

        foreach ($produtos as $produto) {
            try {
                $espelhado = $mirror->handle($produto->omie_code);
            } catch (ApiThrottleException|Throwable $e) {
                $barra->finish();
                $this->newLine(2);

                // O CÓDIGO do erro vem na exceção; a mensagem é só o texto que
                // a Omie escreveu. Procurar o código dentro do texto funcionava
                // por acidente, enquanto a mensagem carregava o corpo cru da
                // resposta — e parou de funcionar quando ela passou a trazer só
                // o `faultstring`, que é o que o operador precisa ler.
                $instrucao = $this->instrucaoDoFreio(
                    $e->getMessage().' '.($e instanceof OmieApiException ? (string) $e->faultCode : ''),
                );

                $this->error('Interrompido: '.($instrucao ?? $e->getMessage()));
                $this->line("Conferidos até aqui: {$kits} kit(s), {$simples} simples — tudo gravado.");

                return self::FAILURE;
            }

            $encontrou = $espelhado?->components()->count() ?? 0;
            $encontrou > 0 ? $kits++ : $simples++;

            $barra->advance();

            // A Omie bloqueia o endpoint por rajada ("Consumo redundante"), e o
            // castigo vale para a conta inteira — inclusive para a fila.
            usleep((int) $this->option('sleep') * 1000);
        }

        $barra->finish();
        $this->newLine(2);
        $this->info("{$kits} kit(s) com estrutura preenchida · {$simples} produto(s) simples.");

        return self::SUCCESS;
    }

    /**
     * Traduz o freio da Omie em instrução para quem está no terminal.
     *
     * NÃO retenta, e isso é deliberado: a Omie tem dois níveis de freio, e
     * insistir no primeiro leva ao segundo.
     *
     *   REDUNDANT (HTTP 500) ....... aviso, ~60s, endpoint
     *   MISUSE_API_PROCESS (425) ... bloqueio da CONTA, ~30min, tudo
     *
     * Uma versão anterior deste comando esperava os segundos pedidos e tentava
     * de novo. Parecia gentileza; na prática é bater na porta durante o castigo,
     * e foi assim que uma varredura de 148 produtos derrubou a integração
     * inteira por meia hora — inclusive os webhooks de produção. Parar cedo e
     * retomar depois custa um comando a mais e não custa a operação.
     */
    private function instrucaoDoFreio(string $mensagem): ?string
    {
        if (str_contains($mensagem, 'MISUSE_API_PROCESS')) {
            $segundos = preg_match('/em (\d+) segundo/', $mensagem, $m) ? (int) $m[1] : 1800;
            $minutos = (int) ceil($segundos / 60);

            return "A Omie BLOQUEOU A CONTA por consumo indevido — ~{$minutos} min. "
                .'Nesse período a integração inteira para (webhook, reconciliação, estoque). '
                .'Espere passar antes de rodar qualquer coisa contra a Omie.';
        }

        if (str_contains($mensagem, 'REDUNDANT')) {
            $segundos = preg_match('/Aguarde (\d+) segundo/', $mensagem, $m) ? (int) $m[1] : 60;

            return "Teto de consumo atingido. Espere {$segundos}s e rode de novo — "
                .'ele continua de onde parou. NÃO insista antes disso: insistir vira bloqueio de conta.';
        }

        return null;
    }

    /**
     * Produtos sem estrutura conhecida. Por padrão só os que aparecem em algum
     * Projeto — é o recorte que aparece nas telas e cabe numa execução.
     *
     * @return Collection<int, Product>
     */
    private function pendentes()
    {
        $codigos = $this->option('code');

        // Códigos explícitos são reconsultados mesmo já tendo estrutura: quem
        // digita o código quer forçar a releitura daquele kit.
        if ($codigos !== []) {
            return Product::whereIn('omie_code', $codigos)->orderBy('id')->get();
        }

        // `structure_synced_at`, não `doesntHave('components')`: a maioria do
        // catálogo é produto simples, e "não tem componente" é resposta, não
        // pendência. Filtrar pela ausência de componentes faria o comando
        // reconsultar os mesmos códigos para sempre.
        return Product::query()
            ->whereNull('structure_synced_at')
            // Sem `--all`, o alvo é o que aparece na tela de algum Projeto: o
            // item do pedido E os componentes dele, em qualquer nível. O
            // componente entra porque pode ser SUB-KIT espelhado como folha —
            // sem ele, só o `--all` alcançaria, varrendo o catálogo inteiro
            // (1800 produtos) para achar meia dúzia.
            ->when(! $this->option('all'), fn ($q) => $q->where(
                fn ($sub) => $sub->whereHas('projects')
                    ->orWhereHas('kits', fn ($k) => $k->whereHas('projects'))
                    ->orWhereHas('kits.kits', fn ($k) => $k->whereHas('projects'))
            ))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();
    }
}
