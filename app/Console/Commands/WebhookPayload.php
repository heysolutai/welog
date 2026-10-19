<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Mostra o CORPO de um webhook recebido, formatado.
 *
 *   php artisan welog:webhooks:payload                  último evento
 *   php artisan welog:webhooks:payload --topic=OS       último cujo tópico contém "OS"
 *   php artisan welog:webhooks:payload 1234             por id
 *   php artisan welog:webhooks:payload --list           os 15 últimos, só id/tópico/situação
 *
 * A tela de Log de Webhooks mostra o mesmo em árvore navegável; este comando
 * existe para o servidor, onde o navegador não está à mão, e para copiar o
 * JSON inteiro num chamado com a Omie.
 */
class WebhookPayload extends Command
{
    protected $signature = 'welog:webhooks:payload
                            {id? : Id do evento (padrão: o mais recente)}
                            {--topic= : Filtra por trecho do tópico, ex.: OS, VendaProduto, Estoque}
                            {--source= : omie|trello}
                            {--list : Lista os últimos eventos em vez de mostrar um payload}';

    protected $description = 'Mostra o corpo (JSON) de um webhook recebido, formatado';

    public function handle(): int
    {
        $query = WebhookEvent::query()
            ->when($this->option('source'), fn ($q, $s) => $q->where('source', $s))
            ->when($this->option('topic'), fn ($q, $t) => $q->where('topic', 'like', "%{$t}%"))
            ->latest('id');

        if ($this->option('list')) {
            $eventos = (clone $query)->limit(15)->get();

            if ($eventos->isEmpty()) {
                $this->warn('Nenhum webhook recebido com esse filtro.');

                return self::SUCCESS;
            }

            $this->table(
                ['Id', 'Origem', 'Tópico', 'Situação', 'Recebido em'],
                $eventos->map(fn (WebhookEvent $e) => [
                    $e->id, $e->source, $e->topic, $e->status, $e->created_at?->format('d/m/Y H:i:s'),
                ])->all(),
            );
            $this->line('  Veja um deles com: <options=bold>php artisan welog:webhooks:payload <id></>');

            return self::SUCCESS;
        }

        $id = $this->argument('id');
        $event = $id !== null ? WebhookEvent::find($id) : $query->first();

        if ($event === null) {
            $this->error($id !== null
                ? "Evento {$id} não encontrado."
                : 'Nenhum webhook recebido'.($this->option('topic') ? ' com esse tópico.' : ' ainda.'));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Evento <options=bold>#{$event->id}</>  ·  origem: <options=bold>{$event->source}</>"
            .($event->omie_account ? "  ·  conta: <options=bold>{$event->omie_account}</>" : ''));
        $this->line("Tópico: <options=bold>{$event->topic}</>  ·  situação: {$event->status}"
            .'  ·  recebido em '.$event->created_at?->format('d/m/Y H:i:s'));

        if ($event->error) {
            $this->newLine();
            $this->error('Erro: '.$event->error);
        }

        $this->newLine();
        $this->line('<options=bold>Corpo recebido</>');
        $this->line(json_encode(
            $event->payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }
}
