<?php

namespace App\Integrations\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Para de bater na porta quando o provedor já disse que está fechada.
 *
 * A Omie responde ao excesso de chamadas em dois níveis: primeiro recusa a
 * consulta repetida ("Consumo redundante detectado. Aguarde 56 segundos") e,
 * se insistirmos, SUSPENDE o aplicativo inteiro. Suspenso, nada funciona —
 * webhook, varredura e criação de Projeto param juntos.
 *
 * O problema é que uma plataforma cheia de jobs enfileirados continua tentando
 * sozinha: cada job faz sua chamada, leva o mesmo 403 e reforça o castigo. O
 * disjuntor corta isso — a primeira recusa abre o circuito, e as chamadas
 * seguintes falham na hora, sem rede, até a janela passar.
 *
 * O estado vive no cache compartilhado (Redis), então vale para todos os
 * workers somados — que é onde o consumo realmente se acumula.
 */
class ApiCircuitBreaker
{
    /** Padrão quando o provedor não diz por quanto tempo ficar fora. */
    private const DEFAULT_COOLDOWN = 300;

    private static function key(string $integration): string
    {
        return "integrations:circuit:{$integration}";
    }

    /** Abre o circuito por `$seconds`, registrando o motivo. */
    public static function open(string $integration, string $reason, ?int $seconds = null): void
    {
        $seconds = max(30, $seconds ?? self::DEFAULT_COOLDOWN);

        Cache::put(self::key($integration), [
            'reason' => $reason,
            'until' => now()->addSeconds($seconds)->toIso8601String(),
        ], $seconds);

        Log::channel('integrations')->warning('Integração pausada: o provedor recusou o acesso.', [
            'integration' => $integration,
            'segundos' => $seconds,
            'motivo' => $reason,
        ]);
    }

    /** Segundos restantes de pausa, ou null quando o circuito está fechado. */
    public static function remaining(string $integration): ?int
    {
        $estado = Cache::get(self::key($integration));

        if (! is_array($estado)) {
            return null;
        }

        $faltam = now()->diffInSeconds($estado['until'], false);

        return $faltam > 0 ? (int) ceil($faltam) : null;
    }

    /**
     * Recusa a chamada enquanto o circuito estiver aberto.
     *
     * Lança em vez de devolver false porque o chamador é um job: falhar é o
     * comportamento certo — a fila reenfileira com backoff e tenta de novo
     * quando a janela já passou, sem gastar uma chamada agora.
     *
     * @throws ApiCircuitOpenException
     */
    public static function ensureClosed(string $integration): void
    {
        $faltam = self::remaining($integration);

        if ($faltam === null) {
            return;
        }

        $estado = Cache::get(self::key($integration));

        throw new ApiCircuitOpenException(
            "Integração {$integration} pausada por mais {$faltam}s: ".($estado['reason'] ?? 'acesso recusado pelo provedor'),
        );
    }

    /** Fecha o circuito à mão (depois de reativar a chave no painel). */
    public static function close(string $integration): void
    {
        Cache::forget(self::key($integration));
    }
}
