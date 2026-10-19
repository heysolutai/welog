<?php

namespace App\Console\Commands;

use App\Integrations\Omie\OmieAccounts;
use App\Jobs\Integrations\EvaluateOmieStock;
use App\Jobs\Integrations\ProcessOmieWebhook;
use App\Jobs\Integrations\ProcessTrelloWebhook;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Injeta um webhook no sistema como se o provedor tivesse chamado — para
 * testar o caminho inteiro sem depender de a Omie (ou o Trello) disparar.
 *
 *   php artisan welog:webhooks:replay --arquivo=evento.json
 *   cat evento.json | php artisan welog:webhooks:replay
 *   php artisan welog:webhooks:replay --evento=30949    (reenvia um recebido)
 *
 * Por que um comando e não uma rota de teste sem token: o token na URL É a
 * credencial do webhook da Omie. Uma rota aberta "só para teste" aceitaria
 * evento forjado de qualquer um na internet — e apagar um Projeto é um dos
 * tópicos aceitos. Aqui a credencial é o acesso ao servidor, que quem testa
 * já tem.
 *
 * O evento gravado é idêntico ao que a rota gravaria (mesmo `external_event_id`
 * por `messageId`, mesmo roteamento por tópico), então o replay exercita o
 * caminho real, incluindo a idempotência.
 */
class ReplayWebhook extends Command
{
    protected $signature = 'welog:webhooks:replay
        {--arquivo= : Caminho do JSON do evento; sem ele, lê da entrada padrão}
        {--evento= : Id de um evento JÁ recebido, para reprocessar em vez de criar}
        {--conta= : Conta Omie (CNPJ) do evento; padrão, a conta única}
        {--fila : Enfileira em vez de processar agora — usa o worker, como em produção}';

    protected $description = 'Reenvia um webhook (Omie/Trello) pelo mesmo caminho do provedor, para teste';

    public function handle(OmieAccounts $accounts): int
    {
        $event = $this->option('evento')
            ? $this->eventoExistente()
            : $this->eventoNovo($accounts);

        if ($event === null) {
            return self::FAILURE;
        }

        $this->line("Evento <options=bold>#{$event->id}</> · {$event->source} · {$event->topic}");

        /*
         * Evento já decidido (`processed`/`ignored`) é reaberto antes de rodar
         * de novo — o job sai logo no começo quando encontra um desfecho já
         * registrado, para não refazer trabalho numa reentrega do provedor.
         */
        if (in_array($event->status, [WebhookEvent::STATUS_PROCESSED, WebhookEvent::STATUS_IGNORED], true)) {
            $event->update(['status' => WebhookEvent::STATUS_RECEIVED, 'processed_at' => null, 'error' => null]);
            $this->line('  Este evento já tinha desfecho; reabri para processar de novo.');
        }

        $this->line('  Processando'.($this->option('fila') ? ' (na fila)' : ' agora').'…');

        try {
            $this->despachar($event);
        } catch (\Throwable $e) {
            // Processado na hora (sem --fila), a exceção do job chega aqui.
            // Cuspir a pilha inteira esconderia a única linha que interessa:
            // a resposta do provedor.
            $this->newLine();
            $this->error('O processamento falhou: '.$e->getMessage());
            $this->line('  O evento ficou gravado; corrija a causa e rode de novo com --evento='.$event->id);

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Resultado:');
        $fresco = $event->fresh();
        $this->line('  status: <options=bold>'.$fresco->status.'</>');

        if ($fresco->error !== null) {
            $this->line('  motivo: '.$fresco->error);
        }

        if ($this->option('fila')) {
            $this->line('  (enfileirado; confira o desfecho no Log de Webhooks)');
        }

        return self::SUCCESS;
    }

    private function eventoExistente(): ?WebhookEvent
    {
        $event = WebhookEvent::find($this->option('evento'));

        if ($event === null) {
            $this->error('Evento '.$this->option('evento').' não existe.');
        }

        return $event;
    }

    private function eventoNovo(OmieAccounts $accounts): ?WebhookEvent
    {
        $json = $this->option('arquivo')
            ? @file_get_contents($this->option('arquivo'))
            : stream_get_contents(STDIN);

        if ($json === false || trim((string) $json) === '') {
            $this->error('Sem JSON: informe --arquivo=... ou mande o conteúdo pela entrada padrão.');

            return null;
        }

        $payload = json_decode((string) $json, true);

        if (! is_array($payload)) {
            $this->error('JSON inválido: '.json_last_error_msg());

            return null;
        }

        $topic = (string) ($payload['topic'] ?? $payload['action']['type'] ?? 'unknown');

        // Trello manda `action`; Omie manda `topic` no primeiro nível.
        $source = isset($payload['action'])
            ? WebhookEvent::SOURCE_TRELLO
            : WebhookEvent::SOURCE_OMIE;

        /*
         * O `messageId` do provedor é a chave de idempotência. Reenviar o MESMO
         * arquivo duas vezes cairia no evento antigo e não testaria nada — daí
         * o sufixo: cada replay é um evento novo, e a idempotência de verdade
         * continua sendo exercitada pela rota, com o id real do provedor.
         *
         * Sufixo com uuid, não com o horário: dois replays no mesmo segundo
         * gerariam a mesma chave e o segundo esbarraria no índice único —
         * justamente o caso de quem está testando e repete o comando.
         */
        $externalId = ($payload['messageId'] ?? 'sem-id').'-replay-'.Str::uuid();

        return WebhookEvent::create([
            'source' => $source,
            'external_event_id' => $externalId,
            'omie_account' => $this->option('conta') ?: $accounts->defaultName(),
            'topic' => $topic,
            'payload' => $payload,
        ]);
    }

    private function despachar(WebhookEvent $event): void
    {
        $fila = (bool) $this->option('fila');

        // Estoque é coalescido num job só (rajada de movimentações), como na
        // rota; os demais tópicos vão para o processamento individual.
        if ($event->source === WebhookEvent::SOURCE_OMIE && str_starts_with($event->topic, 'Estoque.')) {
            $fila ? EvaluateOmieStock::dispatch() : EvaluateOmieStock::dispatchSync();

            return;
        }

        $job = $event->source === WebhookEvent::SOURCE_TRELLO
            ? ProcessTrelloWebhook::class
            : ProcessOmieWebhook::class;

        $fila ? $job::dispatch($event->id) : $job::dispatchSync($event->id);
    }
}
