<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * Diagnóstico de "recebi webhook e não virou nada".
 *
 * A cadeia tem quatro elos, e cada um falha de um jeito diferente. Este
 * comando percorre os quatro e diz em qual parou, em vez de despejar a tabela
 * e deixar a leitura por conta de quem está com pressa:
 *
 *   1. A Omie chama?            → webhook_events vazio = não chega aqui
 *   2. O worker consome?        → status `received` acumulando = fila parada
 *   3. O job conclui?           → status `failed` = erro no processamento
 *   4. O pedido vira Projeto?   → processado sem Projeto = filtro ou tópico
 */
class WebhookStatus extends Command
{
    protected $signature = 'welog:webhooks:status
                            {--source= : Filtra por origem (omie|trello)}
                            {--falhas : Mostra o erro completo dos eventos com falha}';

    protected $description = 'Diagnostica a cadeia de webhooks: recepção, fila, processamento e efeito';

    public function handle(): int
    {
        $source = $this->option('source');
        $base = fn () => WebhookEvent::query()->when($source, fn ($q) => $q->where('source', $source));

        $total = $base()->count();

        $this->line('');
        $this->line('<options=bold>1. Recepção</> — a origem está chamando a URL?');

        if ($total === 0) {
            $this->error('   Nenhum webhook registrado'.($source ? " para '{$source}'" : '').'.');
            $this->line('   O evento é gravado ANTES de qualquer processamento, então tabela vazia');
            $this->line('   significa que a requisição não chegou (ou foi recusada no token).');
            $this->newLine();
            $this->line('   Confira:');
            $this->line('   · a URL registrada no painel da Omie inclui o token do .env?');
            $this->line('     '.rtrim(config('app.url'), '/').'/webhooks/omie/{OMIE_WEBHOOK_TOKEN}');
            $this->line('   · OMIE_WEBHOOK_TOKEN está preenchido? '.(config('integrations.omie.webhook_token') ? 'sim' : 'NÃO — está vazio'));
            $this->line('   · o domínio é alcançável de fora e responde em HTTPS?');
            $this->line('   · nos logs do nginx há POST em /webhooks/omie? (403 = token divergente)');

            return self::FAILURE;
        }

        $porOrigem = $base()->selectRaw('source, count(*) as t')->groupBy('source')->pluck('t', 'source');
        $this->info("   {$total} evento(s) recebido(s): ".$porOrigem->map(fn ($t, $s) => "{$s}={$t}")->implode(', '));
        $ultimo = $base()->latest()->first();
        $this->line('   último em '.$ultimo->created_at->format('d/m/Y H:i:s').' ('.$ultimo->created_at->diffForHumans().')');

        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>2. Fila</> — o worker está consumindo?');

        $pendentes = $base()->where('status', WebhookEvent::STATUS_RECEIVED)->count();

        if ($pendentes > 0) {
            $this->warn("   {$pendentes} evento(s) parado(s) em 'received'.");
            $this->line('   O evento foi gravado mas o job não rodou. Quase sempre é o worker.');
            $this->line('   Confira: docker compose -f docker-compose.prod.yml ps worker');
            $this->line('            php artisan queue:failed');
        } else {
            $this->info('   Nenhum evento parado na fila.');
        }

        try {
            $this->line('   fila `integrations`: '.Queue::size('integrations').' job(s) aguardando');
        } catch (\Throwable $e) {
            $this->line('   (não foi possível ler o tamanho da fila: '.$e->getMessage().')');
        }

        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>3. Processamento</> — o job concluiu?');

        $falhos = $base()->where('status', WebhookEvent::STATUS_FAILED)->count();
        $ok = $base()->where('status', WebhookEvent::STATUS_PROCESSED)->count();

        $this->line("   processados: {$ok} · com falha: {$falhos}");

        if ($falhos > 0) {
            $this->newLine();
            $this->warn('   Últimas falhas:');

            $base()->where('status', WebhookEvent::STATUS_FAILED)
                ->latest()
                ->limit($this->option('falhas') ? 20 : 5)
                ->get()
                ->each(function (WebhookEvent $e) {
                    $erro = $this->option('falhas') ? $e->error : str($e->error)->limit(120);
                    $this->line("   #{$e->id} [{$e->topic}] {$e->created_at->format('d/m H:i')}");
                    $this->line("      {$erro}");
                });
        }

        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>4. Efeito</> — o pedido virou Projeto?');

        $pedidos = $base()->where('topic', 'like', 'VendaProduto.%')->count();
        $projetos = Project::whereNotNull('omie_order_id')->count();

        $this->line("   webhooks de pedido: {$pedidos} · Projetos com origem Omie: {$projetos}");

        if ($pedidos > 0 && $projetos === 0) {
            $this->error('   Chegou pedido e nenhum Projeto foi criado.');
            $this->line('   Causas prováveis, em ordem:');
            $this->line("   · o pedido tem marcador 'Importação' (Importações Parceiros não gera Projeto, é esperado)");
            $this->line('   · o ConsultarPedido da Omie devolveu vazio para aquele código');
            $this->line('   · o driver está em `fake` e o pedido real não existe no simulador');
            $this->line('     OMIE_DRIVER atual: <options=bold>'.config('integrations.omie.driver').'</>');
            $this->line('   · veja storage/logs/integrations-*.log e laravel.log');
        }

        // Tópicos, para revelar assinatura errada no painel da Omie
        $this->newLine();
        $this->line('<options=bold>Tópicos recebidos</>');
        $this->table(
            ['Tópico', 'Total', 'Processados', 'Falhas'],
            $base()->selectRaw('topic, count(*) as total')
                ->selectRaw("sum(case when status = 'processed' then 1 else 0 end) as ok")
                ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as erro")
                ->groupBy('topic')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => [$r->topic, $r->total, $r->ok, $r->erro])
                ->all(),
        );

        return $falhos > 0 || $pendentes > 0 ? self::FAILURE : self::SUCCESS;
    }
}
