<?php

namespace App\Http\Controllers;

use App\Contracts\Integrations\CepGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Preenchimento de endereço a partir do CEP.
 *
 * A consulta passa pelo servidor, e não pelo navegador, por dois motivos: o
 * cache é compartilhado entre todos os usuários (CEP repetido não gera chamada
 * externa nenhuma) e a troca de provedor fica num só lugar.
 */
class CepLookupController extends Controller
{
    public function __invoke(string $cep, CepGateway $gateway): JsonResponse
    {
        try {
            $endereco = $gateway->lookup($cep);
        } catch (Throwable $e) {
            // Provedor fora do ar não é "CEP inexistente": a tela precisa
            // distinguir para pedir o preenchimento manual.
            Log::channel('integrations')->warning('Consulta de CEP falhou.', [
                'cep' => $cep,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Serviço de CEP indisponível. Preencha o endereço manualmente.',
            ], 503);
        }

        if ($endereco === null) {
            return response()->json(['message' => 'CEP não encontrado.'], 404);
        }

        return response()->json($endereco);
    }
}
