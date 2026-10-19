<?php

namespace App\Integrations\Correios;

use App\Contracts\Integrations\CorreiosGateway;
use App\Integrations\Support\ResilientHttp;
use App\Integrations\Support\TrackingStatus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente da API CWS dos Correios (driver 'api').
 * Autentica via Basic (usuario/código de acesso) trocando por token de
 * contrato; rastreio pela API SRO-Rastro e prepostagem pela API PPN.
 */
class CorreiosApiGateway implements CorreiosGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $usuario,
        private readonly string $codigoAcesso,
        private readonly string $contrato,
    ) {}

    private const TOKEN_CACHE_KEY = 'correios.cws.token';

    private function token(): string
    {
        // Token do CWS expira; cache por 25 min (validade típica 30 min)
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(25), function () {
            $response = Http::baseUrl($this->baseUrl)
                ->withBasicAuth($this->usuario, $this->codigoAcesso)
                ->post('/token/v1/autentica/contrato', ['numero' => $this->contrato])
                ->throw()
                ->json();

            return $response['token'];
        });
    }

    private function client(): PendingRequest
    {
        return ResilientHttp::for('correios', $this->baseUrl)->withToken($this->token());
    }

    /**
     * O token pode ser revogado antes de o cache expirar (renovação de
     * contrato, reset de credencial). Um 401 significa "token velho": descarta
     * o cache e tenta uma única vez com um token novo.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function request(callable $call): Response
    {
        $response = $call($this->client());

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);

            $response = $call($this->client());
        }

        return $response->throw();
    }

    public function createPrePostagem(array $payload): array
    {
        $response = $this->request(
            fn (PendingRequest $client) => $client->post('/prepostagem/v1/prepostagens', $payload),
        )->json();

        return ['tracking_code' => $response['codigoObjeto'] ?? ''];
    }

    public function track(string $trackingCode): array
    {
        $body = $this->request(
            fn (PendingRequest $client) => $client->get(
                '/srorastro/v1/objetos/'.$trackingCode,
                ['resultado' => 'T'],
            ),
        )->json();

        $events = $body['objetos'][0]['eventos'] ?? [];

        // O SRO devolve códigos (BDE/OEC/...); o resto do sistema trabalha com
        // o status canônico — sem isso a entrega nunca seria detectada.
        return array_map(fn (array $e) => [
            'occurred_at' => $e['dtHrCriado'] ?? now()->toIso8601String(),
            'status' => TrackingStatus::fromCorreios($e['codigo'] ?? null, $e['tipo'] ?? null)->value,
            'description' => $e['descricao'] ?? '',
            'location' => isset($e['unidade']['endereco']['cidade'])
                ? $e['unidade']['endereco']['cidade'].'/'.($e['unidade']['endereco']['uf'] ?? '')
                : null,
        ], $events);
    }
}
