<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\RecebeResgates;
use App\Models\PartnerSku;
use App\Models\WebhookEvent;
use App\Services\FluxoB\RegisterPartnerSku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe o cadastro de produto da Reconhece — a vitrine onde o colaborador da
 * Suvinil escolhe o brinde.
 *
 * É o par do SuvinilWebhookController, na direção oposta: por ali entram os
 * resgates, por aqui entra o catálogo que dá sentido a eles. Um resgate chega
 * com o `ID Produto` da Reconhece, e é este cadastro que diz qual kit da Omie
 * expedir. Sem ele, a linha trava em "produto não cadastrado" no meio da
 * campanha, que é o pior momento para descobrir.
 *
 * Processa na própria requisição, ao contrário do webhook de resgates: aqui
 * não há chamada à Omie nem e-mail, só gravação. Enfileirar só adiaria a
 * resposta que interessa a quem mandou — quantos entraram e quantos ficaram
 * sem vínculo.
 */
class ReconheceWebhookController extends Controller
{
    use RecebeResgates;

    /** O parceiro testa o endereço antes de ligar o disparo. */
    public function verify(Request $request, ?string $token = null): JsonResponse
    {
        if (! $this->tokenConfere($this->chaveRecebida($request, $token))) {
            abort(403, 'Invalid webhook token.');
        }

        return response()->json(['status' => 'ok', 'service' => 'welog']);
    }

    public function __invoke(Request $request, RegisterPartnerSku $registra, ?string $token = null): JsonResponse
    {
        $token = $this->chaveRecebida($request, $token);

        if (! $this->tokenConfere($token)) {
            Log::channel('integrations')->warning('Webhook Reconhece RECUSADO: token não confere.', [
                'token_recebido' => mb_substr($token, 0, 6).'…',
                'token_configurado' => config('integrations.reconhece.webhook_token') !== '',
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid webhook token.');
        }

        // Qualquer corpo é guardado, reconhecido ou não: enquanto o formato do
        // parceiro não está fechado, recusar não deixa nada para olhar deste
        // lado. Ver o mesmo raciocínio no webhook de resgates.
        $payload = $request->json()->all();

        if ($payload === []) {
            $payload = ['corpoCru' => mb_substr($request->getContent(), 0, 20000)];
        }

        /*
         * A mesma URL recebe resgate e catálogo.
         *
         * É a Reconhece que manda os dois, e entregar a ela duas URLs fazia o
         * resgate cair na de catálogo, ser guardado como "nenhum produto
         * reconhecido" e não virar pedido. O corpo diz o que é: resgate vem
         * num envelope de lote (`resgates`), produto traz `sku`.
         */
        if ($this->eResgate($payload)) {
            return $this->receberResgates($payload, $request->getContent(), WebhookEvent::SOURCE_RECONHECE);
        }

        $itens = $this->itens($payload);
        $teto = (int) config('integrations.reconhece.max_rows', 500);

        if (count($itens) > $teto) {
            return response()->json([
                'received' => false,
                'error' => 'Lote de '.count($itens)." itens excede o limite de {$teto} por chamada. Divida em lotes menores.",
            ], 422);
        }

        /*
         * Reenvio idêntico atualiza o registro; NÃO é recusado nem duplicado.
         *
         * Sync de catálogo reenvia o mesmo corpo por definição — é a forma
         * normal de dizer "continua tudo assim". Com `create`, a constraint de
         * unicidade devolvia 500 ao parceiro; com uma linha por entrega, a
         * tabela cresceria sem acrescentar informação nenhuma.
         *
         * E, ao contrário do webhook de resgates, aqui a repetição pode e deve
         * ser reprocessada: cadastro é upsert por SKU, não abre pedido nem
         * despacha brinde. Repetir não custa nada; deixar de repetir esconderia
         * uma correção que o parceiro mandou junto.
         */
        $evento = WebhookEvent::firstOrCreate(
            [
                'source' => WebhookEvent::SOURCE_RECONHECE,
                'external_event_id' => hash('sha256', $request->getContent()),
            ],
            [
                'topic' => 'Produto.Cadastro',
                'payload' => $payload,
            ],
        );

        $evento->touch();

        if ($itens === []) {
            $evento->update(['status' => WebhookEvent::STATUS_IGNORED, 'processed_at' => now()]);

            return response()->json([
                'received' => true,
                'itens' => 0,
                'aviso' => 'Corpo recebido e registrado, mas nenhum produto foi reconhecido.',
                'esperado' => '{"sku":"...","product":{...}} ou uma lista deles',
                'campos_recebidos' => array_slice(array_map('strval', array_keys($payload)), 0, 40),
            ]);
        }

        $gravados = [];

        foreach ($itens as $item) {
            $registro = $registra->handle($item, PartnerSku::SOURCE_RECONHECE);

            if ($registro !== null) {
                $gravados[] = $registro;
            }
        }

        $evento->markProcessed();

        /*
         * A resposta conta o que ficou SEM vínculo, e nomeia os SKUs.
         *
         * É o dado que decide se a campanha pode rodar, e quem está do outro
         * lado é justamente quem consegue corrigir o código. Devolver só
         * "recebido" empurraria a descoberta para o primeiro resgate travado.
         */
        $semVinculo = array_values(array_map(
            fn (PartnerSku $s) => $s->sku,
            array_filter($gravados, fn (PartnerSku $s) => $s->product_id === null),
        ));

        return response()->json([
            'received' => true,
            'itens' => count($gravados),
            'vinculados' => count($gravados) - count($semVinculo),
            'sem_vinculo' => array_slice($semVinculo, 0, 50),
        ]);
    }

    /**
     * A chave vem no header, como a Reconhece pediu (`X-API-Key`, `Api-Key`
     * ou `Authorization`, com ou sem "Bearer"), ou no caminho da URL, como
     * era antes. As duas formas valem o mesmo RECONHECE_WEBHOOK_TOKEN.
     */
    private function chaveRecebida(Request $request, ?string $token): string
    {
        if ($token !== null) {
            return $token;
        }

        $chave = $request->header('X-API-Key') ?? $request->header('Api-Key') ?? $request->header('Authorization') ?? '';

        return trim((string) preg_replace('/^Bearer\s+/i', '', (string) $chave));
    }

    /**
     * Comparação em tempo constante, e porta fechada sem token configurado:
     * senão a string vazia da configuração casaria com a da URL justamente
     * onde ninguém parametrizou nada.
     */
    private function tokenConfere(string $token): bool
    {
        $esperado = (string) config('integrations.reconhece.webhook_token', '');

        return $esperado !== '' && hash_equals($esperado, $token);
    }

    /**
     * Resgate vem em envelope de lote; uma lista pura também é resgate quando
     * os itens não trazem `sku` (sem `sku` não há produto a cadastrar).
     */
    private function eResgate(array $payload): bool
    {
        if (isset($payload['resgates']) || isset($payload['linhas']) || isset($payload['rows'])) {
            return true;
        }

        $primeiro = array_is_list($payload) ? ($payload[0] ?? null) : null;

        return is_array($primeiro) && ! isset($primeiro['sku']);
    }

    /** Aceita um item só, `{"skus":[...]}`, `{"items":[...]}` ou a lista pura. */
    private function itens(array $payload): array
    {
        if (isset($payload['sku'])) {
            return [$payload];
        }

        $lista = $payload['skus'] ?? $payload['items'] ?? $payload['produtos'] ?? null;

        if ($lista === null && array_is_list($payload)) {
            $lista = $payload;
        }

        return array_values(array_filter((array) $lista, 'is_array'));
    }
}
