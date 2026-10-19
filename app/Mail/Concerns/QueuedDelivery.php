<?php

namespace App\Mail\Concerns;

/**
 * Política de entrega comum a todos os e-mails da WeLog.
 *
 * Fila dedicada (`mail`), consumida pelo worker em produção, e retentativas
 * espaçadas: uma indisponibilidade momentânea do provedor SMTP não pode custar
 * o convite de primeiro acesso de um Usuário.
 */
trait QueuedDelivery
{
    /** Tentativas antes de a mensagem ir para `failed_jobs`. */
    public int $tries = 5;

    /** Backoff progressivo (1min, 5min, 15min, 1h) — cobre queda curta do provedor. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    /** Chamado no construtor de cada Mailable. */
    protected function queueForDelivery(): void
    {
        $this->onQueue(config('mail.queue'));
    }
}
