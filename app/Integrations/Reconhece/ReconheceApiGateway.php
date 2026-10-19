<?php

namespace App\Integrations\Reconhece;

use App\Contracts\Integrations\ReconheceGateway;
use App\Integrations\Support\ResilientHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * Cliente da API da Reconhece (driver 'api').
 *
 * ATENÇÃO: só o formato do CORPO veio da Reconhece. Endpoint, verbo e forma de
 * autenticação ainda não — o que está aqui é o palpite mais provável
 * (`POST /skus` com bearer token), a confirmar com eles.
 *
 * O resto do sistema não depende disso: tudo conversa pelo contrato
 * `ReconheceGateway`, e ajustar esta classe não toca em mais nada. Enquanto a
 * confirmação não chega, o driver fica em 'fake'.
 */
class ReconheceApiGateway implements ReconheceGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    private function client(): PendingRequest
    {
        return ResilientHttp::for('reconhece', $this->baseUrl)->withToken($this->apiKey);
    }

    public function upsertSku(array $payload): array
    {
        $resposta = $this->client()->post('/skus', $payload);

        /*
         * Recusa do parceiro NÃO vira exceção.
         *
         * "Categoria inexistente" é resposta legítima da API, não falha de
         * rede: estourar aqui faria a fila tentar de novo três vezes o que
         * nunca vai passar, e esconderia o motivo — que é justamente o que o
         * operador precisa ler para corrigir o cadastro.
         */
        if ($resposta->failed()) {
            return [
                'sku' => (string) ($payload['sku'] ?? ''),
                'accepted' => false,
                'message' => $this->motivo($resposta->json(), $resposta->status()),
            ];
        }

        return [
            'sku' => (string) ($payload['sku'] ?? ''),
            'accepted' => true,
            'message' => null,
        ];
    }

    private function motivo(mixed $corpo, int $status): string
    {
        foreach (['message', 'error', 'detail'] as $chave) {
            if (is_array($corpo) && filled($corpo[$chave] ?? null) && is_scalar($corpo[$chave])) {
                return (string) $corpo[$chave];
            }
        }

        return "A Reconhece recusou o envio (HTTP {$status}).";
    }
}
