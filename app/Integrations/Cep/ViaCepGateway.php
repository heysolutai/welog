<?php

namespace App\Integrations\Cep;

use App\Contracts\Integrations\CepGateway;
use App\Integrations\Support\ResilientHttp;
use Illuminate\Support\Facades\Cache;

/**
 * ViaCEP (driver 'api') — público, sem credencial.
 *
 * CEP praticamente não muda, então o resultado é cacheado por 30 dias: a
 * segunda consulta ao mesmo CEP não sai da nossa rede. Isso protege o
 * provedor (que é gratuito e sem contrato) e deixa o preenchimento instantâneo
 * nos CEPs recorrentes, que é o caso comum num cliente corporativo.
 */
class ViaCepGateway implements CepGateway
{
    public function __construct(private readonly string $baseUrl) {}

    public function lookup(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen((string) $cep) !== 8) {
            return null;
        }

        // Cacheia inclusive o "não existe" (como null explícito via flag), para
        // um CEP digitado errado repetidamente não virar tráfego externo.
        return Cache::remember("cep:{$cep}", now()->addDays(30), function () use ($cep) {
            $response = ResilientHttp::for('viacep', $this->baseUrl)
                ->get("/ws/{$cep}/json/")
                ->throw();

            $body = $response->json();

            // ViaCEP responde 200 com {"erro": true} quando o CEP não existe
            if (! is_array($body) || filter_var($body['erro'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }

            return [
                'zip' => $cep,
                'street' => $body['logradouro'] ?: null,
                'district' => $body['bairro'] ?: null,
                'city' => $body['localidade'] ?: null,
                'state' => $body['uf'] ?: null,
            ];
        });
    }
}
