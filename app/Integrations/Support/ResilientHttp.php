<?php

namespace App\Integrations\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fábrica de clientes HTTP resilientes para os drivers `api` (Fase 3):
 * timeout, retry com backoff exponencial apenas em erros transitórios
 * (timeout/conexão/5xx/429) e logging estruturado no canal 'integrations'.
 */
class ResilientHttp
{
    public static function for(string $integration, string $baseUrl, int $timeout = 30, int $retries = 3): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->retry($retries, throw: false, sleepMilliseconds: function (int $attempt, $exception) use ($integration) {
                Log::channel('integrations')->warning('Retentando chamada de integração', [
                    'integration' => $integration,
                    'attempt' => $attempt,
                    'reason' => $exception?->getMessage(),
                ]);

                return $attempt * 500; // 500ms, 1000ms, 1500ms…
            }, when: fn ($exception) => self::isTransient($exception))
            ->withHeaders(['Accept' => 'application/json']);
    }

    /** Só reexecuta erros transitórios; 4xx (exceto 429) não são retentados. */
    private static function isTransient(Throwable $exception): bool
    {
        // Erros de conexão/timeout sempre são transitórios
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            // Bloqueio por repetição é transitório no tempo, mas não NESTA
            // escala: a Omie pede ~51s e o backoff daqui é de meio segundo.
            // Retentar em milissegundos é o próprio gatilho do bloqueio —
            // quem espera é o backoff do job, que trabalha em minutos.
            if (self::isRateLimited($exception)) {
                return false;
            }

            // Resposta de negócio da Omie: ela responde "não existem registros"
            // e "pedido não localizado" com HTTP 500 e um `faultstring`. Isso é
            // resposta, não falha passageira — e reenviar a chamada idêntica
            // meio segundo depois é exatamente o que a Omie recusa como
            // "consumo redundante". Era assim que buscar um CPF ainda sem
            // cadastro terminava em REDUNDANT, no primeiro clique.
            if (self::isProviderFault($exception)) {
                return false;
            }

            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * A Omie devolve o bloqueio de consumo como HTTP 500 com `REDUNDANT` no
     * corpo — indistinguível de erro de servidor pelo código.
     */
    private static function isProviderFault(RequestException $exception): bool
    {
        $body = $exception->response->json();

        return is_array($body) && isset($body['faultstring']);
    }

    public static function isRateLimited(RequestException $exception): bool
    {
        $body = $exception->response->body();

        return str_contains($body, 'REDUNDANT')
            || str_contains($body, 'Consumo redundante');
    }
}
