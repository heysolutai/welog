<?php

namespace App\Integrations\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Limitador de chamadas por minuto para as APIs externas.
 *
 * A Omie corta o acesso da conta inteira quando o consumo passa do teto
 * ("API bloqueada por consumo indevido", ~30 min de castigo). Como o consumo
 * é concentrado em jobs de fila, a estratégia certa é **esperar** a janela
 * abrir, não descartar a chamada.
 *
 * O contador vive no cache compartilhado (Redis em produção), então o limite
 * vale para todos os workers somados.
 */
class ApiThrottle
{
    /**
     * Segura a execução até haver crédito na janela de 1 minuto.
     *
     * @param  int  $perMinute  teto de chamadas; 0 ou menos desliga o throttle
     */
    public static function acquire(string $integration, int $perMinute, int $maxWaitSeconds = 90): void
    {
        if ($perMinute <= 0) {
            return;
        }

        $limiter = app(RateLimiter::class);
        $key = "integrations:throttle:{$integration}";
        $waited = 0;

        while ($limiter->tooManyAttempts($key, $perMinute)) {
            $wait = max(1, $limiter->availableIn($key));

            if ($waited + $wait > $maxWaitSeconds) {
                // Melhor falhar e deixar a fila retentar do que segurar o
                // worker indefinidamente com o resto da fila parada.
                throw new ApiThrottleException(
                    "Limite de {$perMinute} chamadas/min da integração '{$integration}' "
                    ."excedido; liberaria em {$wait}s."
                );
            }

            Log::channel('integrations')->info('Aguardando janela de rate limit.', [
                'integration' => $integration,
                'wait_seconds' => $wait,
                'limit_per_minute' => $perMinute,
            ]);

            sleep($wait);
            $waited += $wait;
        }

        $limiter->hit($key, 60);
    }

    /**
     * Espaça chamadas do MESMO método — o que a Omie chama de "consumo
     * redundante".
     *
     * O teto por minuto não pega isto: seis `ListarPedidos` em dois segundos
     * cabem folgadamente em 180/min, e foi assim que uma varredura (uma
     * chamada por projeto, em rajada) levou a conta à suspensão. O log da Omie
     * mostrava as seis no mesmo segundo, as primeiras com 200 e as seguintes
     * com 500.
     *
     * O intervalo é por método, não global, para não penalizar quem alterna
     * chamadas diferentes: um webhook faz pedido → cliente → produto, e essas
     * não competem entre si.
     *
     * Cada chamada RESERVA o próximo horário livre antes de dormir, em vez de
     * ler a marca da última. A diferença aparece com mais de um worker: lendo,
     * os dois veem a mesma marca, dormem o mesmo tanto e disparam juntos —
     * exatamente a rajada que isto existe para evitar. Reservando, cada um
     * pega um horário diferente.
     *
     * @param  int  $minIntervalMs  0 ou menos desliga o espaçamento
     */
    public static function spaceOut(string $integration, string $method, int $minIntervalMs, int $maxWaitSeconds = 30): void
    {
        if ($minIntervalMs <= 0) {
            return;
        }

        $key = "integrations:spacing:{$integration}:{$method}";
        $intervalo = $minIntervalMs / 1000;
        // TTL curto: passada a reserva, a marca não serve para mais nada.
        $ttl = max(60, (int) ceil($intervalo * 3));

        $slot = self::reservarSlot($key, $intervalo, $ttl);
        $esperaMs = (int) round(($slot - microtime(true)) * 1000);

        if ($esperaMs <= 0) {
            return;
        }

        // Teto de segurança: fila comprida no mesmo método não pode prender o
        // worker indefinidamente. Melhor sair espaçado do que travado.
        $esperaMs = min($esperaMs, $maxWaitSeconds * 1000);

        usleep($esperaMs * 1000);
    }

    /**
     * Devolve o instante (timestamp com fração) em que esta chamada pode sair,
     * já marcando o seguinte.
     *
     * O lock é o que torna a reserva atômica entre workers. Store sem suporte
     * a lock (ou lock disputado) cai no caminho simples: pior espaçamento,
     * nunca pior que não ter nada.
     */
    private static function reservarSlot(string $key, float $intervalo, int $ttl): float
    {
        $reservar = function () use ($key, $intervalo, $ttl): float {
            $proximoLivre = (float) (Cache::get($key) ?? 0);
            $slot = max(microtime(true), $proximoLivre);

            Cache::put($key, $slot + $intervalo, $ttl);

            return $slot;
        };

        try {
            $lock = Cache::lock($key.':lock', 10);

            if ($lock->get()) {
                try {
                    return $reservar();
                } finally {
                    $lock->release();
                }
            }
        } catch (Throwable $e) {
            Log::channel('integrations')->debug('Cache sem lock para espaçamento; seguindo sem ele.', [
                'key' => $key,
                'erro' => $e->getMessage(),
            ]);
        }

        return $reservar();
    }
}
