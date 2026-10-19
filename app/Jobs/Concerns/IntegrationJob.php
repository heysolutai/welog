<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Política comum aos jobs que falam com APIs externas.
 *
 * `$timeout` precisa ficar **abaixo** do `retry_after` da conexão de fila
 * (`config/queue.php`), senão o Laravel reenfileira o job enquanto ele ainda
 * está executando — duas execuções simultâneas chamando a mesma API.
 */
trait IntegrationJob
{
    /** Menor que REDIS_QUEUE_RETRY_AFTER (180s) por margem de segurança. */
    public int $timeout = 120;

    public int $tries = 3;

    /**
     * Espaça as tentativas: falha de API raramente se resolve em 1s.
     *
     * O primeiro degrau é 60s por causa do bloqueio de consumo da Omie, que
     * pede ~51 segundos. Em 30s a retentativa caía dentro da janela ainda
     * bloqueada e queimava uma tentativa à toa.
     */
    public function backoff(): array
    {
        return [60, 180, 600];
    }

    /** Última tentativa esgotada: registra com contexto antes de ir para failed_jobs. */
    public function failed(?Throwable $e): void
    {
        Log::channel('integrations')->error('Job de integração falhou definitivamente.', [
            'job' => static::class,
            'exception' => $e?->getMessage(),
        ]);
    }
}
