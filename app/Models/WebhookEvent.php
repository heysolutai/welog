<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use MassPrunable;

    public const SOURCE_OMIE = 'omie';

    public const SOURCE_TRELLO = 'trello';

    /** Resgates que a Suvinil empurra para nós (Importações Parceiros). */
    public const SOURCE_SUVINIL = 'suvinil';

    /** Catálogo que a Reconhece cadastra e nos avisa (Importações Parceiros). */
    public const SOURCE_RECONHECE = 'reconhece';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /** Recebido e gravado, mas sem ação prevista para o tópico. */
    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'source',
        'omie_account',
        'external_event_id',
        'topic',
        'payload',
        'status',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Retenção: os payloads recebidos são trilha de auditoria/idempotência de
     * curto prazo — a janela de reentrega das duas APIs é de horas. Eventos
     * já processados saem após 90 dias; os que **falharam** ficam, porque são
     * exatamente os que alguém ainda precisa investigar.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', self::STATUS_PROCESSED)
            ->where('created_at', '<', now()->subDays(
                (int) config('integrations.retention.webhook_events_days', 90),
            ));
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSED,
            'error' => null,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
        ]);
    }

    /**
     * Recebido e entendido, mas de propósito não virou nada — e o MOTIVO fica
     * junto.
     *
     * Existe porque "Processado" sem Projeto na tela é a pior resposta
     * possível: quem abre o Log de Webhooks vê que o evento chegou em
     * segundos, não encontra o Projeto e conclui que o sistema está lento,
     * quando na verdade o pedido foi recusado pelo filtro de projeto da Omie.
     * O motivo transforma essa investigação numa leitura.
     */
    public function markIgnored(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_IGNORED,
            'error' => $reason,
            'processed_at' => now(),
        ]);
    }
}
