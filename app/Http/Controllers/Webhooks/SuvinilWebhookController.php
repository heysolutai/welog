<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\RecebeResgates;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe os resgates que a Suvinil empurra para nós (Importações Parceiros).
 *
 * É a mesma matéria-prima do relatório appnoz que hoje chega por diretório —
 * a diferença é o ritmo: o arquivo é varrido uma vez por dia, o webhook chega
 * no instante em que a pessoa resgata. As duas portas terminam no mesmo
 * IngestPartnerRows, de propósito: uma linha recusada aqui tem de ser recusada
 * lá, pelo mesmo motivo e com a mesma frase.
 *
 * Como o Omie, a Suvinil não assina o corpo. A verificação é o token secreto
 * embutido na URL registrada do lado deles (/webhooks/suvinil/{token}).
 *
 * O processamento NÃO acontece aqui. Cada linha cadastra cliente na Omie e
 * abre Pedido de Venda — chamadas de rede que levam segundos. Feito dentro da
 * requisição, o parceiro estoura por timeout e reenvia o lote inteiro, que é
 * exatamente como se duplica pedido. Aqui só gravamos e respondemos.
 */
class SuvinilWebhookController extends Controller
{
    use RecebeResgates;

    /**
     * Verificação da URL.
     *
     * Todo parceiro quer testar o endereço antes de ligar o disparo, e sem
     * isto o teste dele volta 405 e parece que a URL está errada.
     */
    public function verify(string $token): JsonResponse
    {
        if (! $this->tokenConfere($token)) {
            abort(403, 'Invalid webhook token.');
        }

        return response()->json(['status' => 'ok', 'service' => 'welog']);
    }

    public function __invoke(Request $request, string $token): JsonResponse
    {
        if (! $this->tokenConfere($token)) {
            /*
             * Recusa deixa rastro.
             *
             * Sem este log, uma URL registrada com o token errado some sem
             * linha nenhuma: do lado de cá "não chegou nada", e não há como
             * distinguir isso de "a Suvinil nunca chamou". Só o começo do
             * token vai para o log — o resto é credencial.
             */
            Log::channel('integrations')->warning('Webhook Suvinil RECUSADO: token não confere.', [
                'token_recebido' => mb_substr($token, 0, 6).'…',
                'token_configurado' => config('integrations.suvinil.webhook_token') !== '',
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid webhook token.');
        }

        /*
         * QUALQUER corpo é aceito e guardado, reconhecido ou não.
         *
         * Enquanto o formato da Suvinil não está fechado, recusar o que não
         * entendemos é o pior que se pode fazer: eles mandam, levam 422, e do
         * nosso lado não fica nada para olhar — a conversa vira "mandamos" /
         * "não chegou". Guardando tudo, o payload cru aparece em
         * /admin/webhooks e o formato se descobre olhando, não perguntando.
         *
         * `corpoCru` cobre o que nem JSON é (form-encoded, XML, corpo vazio):
         * `json()->all()` devolveria array vazio e a evidência sumiria.
         */
        $payload = $request->json()->all();

        if ($payload === []) {
            $payload = ['corpoCru' => mb_substr($request->getContent(), 0, 20000)];
        }

        return $this->receberResgates($payload, $request->getContent(), WebhookEvent::SOURCE_SUVINIL);
    }

    /**
     * Comparação em tempo constante e recusa quando não há token configurado.
     *
     * Sem a segunda trava, um ambiente sem `SUVINIL_WEBHOOK_TOKEN` aceitaria
     * a string vazia — a rota ficaria aberta justamente onde ninguém
     * configurou nada.
     */
    private function tokenConfere(string $token): bool
    {
        $esperado = (string) config('integrations.suvinil.webhook_token', '');

        return $esperado !== '' && hash_equals($esperado, $token);
    }
}
