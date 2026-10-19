<?php

namespace App\Integrations\Flash;

use App\Contracts\Integrations\FlashGateway;
use App\Integrations\Support\ResilientHttp;
use App\Integrations\Support\TrackingStatus;
use App\Support\TextoFlash;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente da API de rastreio Flash (driver 'api').
 *
 * Protocolo da Flash Courier (Web Service FlashPegasus), hoje servido pela
 * Bridge Link em https://bridgelink.com.br — mesmos caminhos, mensagens e
 * credenciais da Flash; muda só a URL base (FLASH_BASE_URL).
 *
 *   1. POST /FlashPegasus/rest/api/v1/token      → JWT de 24h
 *   2. POST /FlashPegasus/rest/padrao/v2/consulta → rastreio de N encomendas
 *
 * Os dois headers Authorization vão SEM prefixo "Bearer" (a API devolve 401
 * com ele) — por isso não se usa withToken() aqui.
 *
 * Só leitura: a Flash recebe a nota por fora da plataforma.
 */
class FlashApiGateway implements FlashGateway
{
    private const TOKEN_PATH = '/FlashPegasus/rest/api/v1/token';

    private const CONSULTA_PATH = '/FlashPegasus/rest/padrao/v2/consulta';

    private const IMPORTACAO_PATH = '/FlashPegasus/rest/padrao/importacao';

    /** Números por consulta: a API aceita lista, e o limite é por requisição. */
    public const LOTE = 50;

    /**
     * @param  array<int, int|string>  $cttIds
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $login,
        private readonly string $senha,
        private readonly int $clienteId,
        private readonly array $cttIds,
        private readonly ?string $key = null,
        // Importação de encomendas (HTTP Basic). Vazios = login/senha do rastreio.
        private readonly ?string $importUsuario = null,
        private readonly ?string $importSenha = null,
        private readonly ?int $ccustoId = null,
        private readonly ?string $remetente = null,
        // Campos do contrato do exemplo da Bridge Link (tipo_enc_id,
        // prod_flash_id, frq_rec_id, id_local_rem, num_cliente).
        private readonly array $contrato = [],
    ) {}

    /**
     * O lote como vai para a Bridge Link: cada encomenda com conta, contrato,
     * centro de custo e remetente na frente.
     *
     * Sem escapar barra nem acento (`\/`, `ã`): a documentação deles
     * cita "erro de leitura do JSON" como causa de 500, e o texto já sai sem
     * pontuação (TextoFlash) — o que se lê aqui é o que chega lá.
     */
    public function corpoDaImportacao(array $encomendas): string
    {
        $conta = array_filter([
            'cliente_id' => $this->clienteId,
            'ctt_id' => isset($this->cttIds[0]) ? (int) $this->cttIds[0] : null,
            'ccusto_id' => $this->ccustoId,
            // Sem acento nem pontuação, como os campos da encomenda: o nome
            // do remetente vem do .env e costuma ter "Ltda." ou "/".
            'nome_rem' => TextoFlash::limpar($this->remetente),
        ], fn ($v) => $v !== null) + $this->camposDoContrato();

        $lote = array_map(fn (array $e) => $conta + $e, array_values($encomendas));

        return json_encode($lote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Os campos do contrato com o tipo do exemplo da Bridge Link: números
     * como número, códigos como texto ("CRS", "40013").
     *
     * @return array<string, int|string>
     */
    private function camposDoContrato(): array
    {
        $numeros = ['tipo_enc_id', 'prod_flash_id', 'num_cliente'];
        $campos = [];

        foreach ($this->contrato as $campo => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }

            $campos[$campo] = in_array($campo, $numeros, true) ? (int) $valor : (string) $valor;
        }

        return $campos;
    }

    public function getTracking(string $reference): ?array
    {
        return $this->getTrackingMany([$reference])[$reference] ?? null;
    }

    /**
     * POST /FlashPegasus/rest/padrao/importacao — o lote de encomendas.
     *
     * HTTP Basic, sem token. Conta, contrato, centro de custo e remetente são
     * carimbados aqui: quem monta a encomenda só descreve destinatário e nota.
     * A resposta é do LOTE (não há erro por linha): 200 = ao menos uma criada
     * ou já existente; 401 = credencial ou conta/contrato; 500 = nenhuma criada.
     */
    public function importShipments(array $encomendas): array
    {
        if ($encomendas === []) {
            return ['mensagem' => null, 'arquivo' => null];
        }

        // O corpo sai pronto, e não montado pelo cliente HTTP: é o mesmo
        // texto que a tela do resgate mostra (corpoDaImportacao).
        $response = $this->client()
            ->withBasicAuth(
                filled($this->importUsuario) ? (string) $this->importUsuario : $this->login,
                filled($this->importSenha) ? (string) $this->importSenha : $this->senha,
            )
            ->withBody($this->corpoDaImportacao($encomendas), 'application/json')
            ->post(self::IMPORTACAO_PATH);

        if ($response->status() === 401) {
            throw new RuntimeException('Importação Flash recusada (401): usuário/senha da importação, ou cliente_id/ctt_id que não são dessa credencial.');
        }

        if ($response->failed()) {
            throw new RuntimeException("Importação Flash falhou: HTTP {$response->status()} - ".mb_substr($response->body(), 0, 500));
        }

        // `[{"type": "SUCESS", "text": "...", "arquivo": "import_padrao_….txt"}]`
        // (o "SUCESS" é deles). 204 = lote vazio do lado de lá.
        $retorno = (array) (($response->json() ?? [])[0] ?? []);

        return [
            'mensagem' => isset($retorno['text']) ? (string) $retorno['text'] : null,
            'arquivo' => isset($retorno['arquivo']) ? (string) $retorno['arquivo'] : null,
        ];
    }

    public function getTrackingMany(array $references): array
    {
        $references = array_values(array_unique(array_filter($references, 'strlen')));
        $resultado = array_fill_keys($references, null);

        foreach (array_chunk($references, self::LOTE) as $lote) {
            $hawbs = $this->consultar($lote);

            foreach ($hawbs as $hawb) {
                // Só voltam números da lista enviada: um enviado e um de volta é ele.
                $ref = $this->numeroDoHawb($hawb, $lote)
                    ?? (count($lote) === 1 && count($hawbs) === 1 ? $lote[0] : null);

                if ($ref === null) {
                    Log::warning('Flash: hawb sem número correspondente na consulta', [
                        'meuNumero' => $hawb['meuNumero'] ?? null,
                        'codigoCartao' => $hawb['codigoCartao'] ?? null,
                        'hawbId' => $hawb['hawbId'] ?? null,
                    ]);

                    continue;
                }

                $resultado[$ref] = $this->normalizar($hawb);
            }
        }

        return $resultado;
    }

    /**
     * A qual número enviado o hawb pertence. `meuNumero` deveria ser o eco
     * exato do numEncCli, mas pode voltar sem o prefixo (WEBOX), que a Bridge
     * Link tira antes da busca, ou com outra grafia.
     *
     * @param  array<string, mixed>  $hawb
     * @param  array<int, string>  $lote
     */
    private function numeroDoHawb(array $hawb, array $lote): ?string
    {
        $doCliente = array_filter([(string) ($hawb['meuNumero'] ?? ''), (string) ($hawb['codigoCartao'] ?? '')], 'strlen');
        $todos = array_filter([...$doCliente, (string) ($hawb['hawbId'] ?? '')], 'strlen');

        $formas = [
            [fn (string $v): string => $v, $todos],
            [fn (string $v): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $v)), $todos],
            // WEBOX69284 x 69284: só os números, sem zeros à esquerda.
            [fn (string $v): string => ltrim((string) preg_replace('/\D+/', '', $v), '0'), $doCliente],
        ];

        foreach ($formas as [$forma, $candidatos]) {
            foreach ($candidatos as $candidato) {
                $chave = $forma($candidato);

                if ($chave === '') {
                    continue;
                }

                $iguais = array_values(array_filter($lote, fn ($ref) => $forma((string) $ref) === $chave));

                if (count($iguais) === 1) {
                    return (string) $iguais[0];
                }
            }
        }

        return null;
    }

    /**
     * A resposta da consulta como a Bridge Link mandou, renovando o token
     * uma vez se ele expirou. Somente leitura.
     *
     * @param  array<int, string>  $numeros
     */
    public function respostaDaConsulta(array $numeros): Response
    {
        $response = $this->postConsulta(array_values($numeros));

        if ($response->status() === 401) {
            // Token de 24h vencido (ou revogado): um novo, e uma segunda chance.
            Cache::forget($this->tokenCacheKey());
            $response = $this->postConsulta(array_values($numeros));
        }

        return $response;
    }

    /**
     * Uma chamada de consulta.
     *
     * @param  array<int, string>  $lote
     * @return array<int, array<string, mixed>> hawbs localizados
     */
    private function consultar(array $lote): array
    {
        $response = $this->respostaDaConsulta($lote);

        // 204 = nenhum dos números localizado. Não é erro: coleta ainda não feita.
        if ($response->status() === 204) {
            return [];
        }

        $body = $response->throw()->json();

        if (($body['statusRetorno'] ?? null) !== '00') {
            throw new RuntimeException('Consulta Flash recusada: statusRetorno='.json_encode($body['statusRetorno'] ?? null));
        }

        return $body['hawbs'] ?? [];
    }

    private function postConsulta(array $lote): Response
    {
        return $this->client()
            ->withHeaders(['Authorization' => $this->token()])
            ->post(self::CONSULTA_PATH, [
                'clienteId' => $this->clienteId,
                'cttId' => array_map('intval', $this->cttIds),
                'numEncCli' => array_values($lote),
            ]);
    }

    /**
     * JWT reaproveitado por quase 24h: a API limita o serviço de token a 10
     * chamadas/minuto por IP e recomenda gerar uma vez ao dia.
     */
    public function token(): string
    {
        return Cache::remember($this->tokenCacheKey(), now()->addHours(23), function () {
            $body = $this->client()
                ->withHeaders(['Authorization' => $this->key()])
                ->post(self::TOKEN_PATH, ['login' => $this->login, 'senha' => $this->senha])
                ->throw()
                ->json();

            $token = $body['access_token'] ?? null;

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Serviço de token da Flash respondeu sem access_token.');
            }

            return $token;
        });
    }

    /** HMAC-SHA256 da senha com o login como chave, em hexadecimal minúsculo. */
    public function key(): string
    {
        return filled($this->key) ? (string) $this->key : hash_hmac('sha256', $this->senha, $this->login);
    }

    private function tokenCacheKey(): string
    {
        return 'flash:token:'.sha1($this->baseUrl.'|'.$this->login);
    }

    private function client(): PendingRequest
    {
        return ResilientHttp::for('flash', $this->baseUrl)->acceptJson();
    }

    /**
     * Um hawb da API → o formato do contrato.
     *
     * @param  array<string, mixed>  $hawb
     */
    private function normalizar(array $hawb): array
    {
        $eventos = [];

        foreach ($hawb['historico'] ?? [] as $e) {
            $quando = $this->data($e['ocorrencia'] ?? $e['dtEvento'] ?? null);

            if ($quando === null) {
                continue;
            }

            $descricao = trim((string) ($e['evento'] ?? $e['descricaoEvento'] ?? ''));
            $situacao = trim((string) ($e['situacao'] ?? ''));

            $eventos[] = [
                'occurred_at' => $quando,
                'status' => TrackingStatus::fromFlashEvento($e['eventoId'] ?? null)->value,
                // O motivo (ex.: insucesso de entrega) vem em `situacao`.
                'description' => $situacao !== '' ? "{$descricao} — {$situacao}" : $descricao,
                'location' => trim((string) ($e['local'] ?? '')) ?: null,
            ];
        }

        return [
            'tracking_code' => filled($hawb['hawbId'] ?? null) ? (string) $hawb['hawbId'] : null,
            'events' => $eventos,
        ];
    }

    /** "08/07/2026 11:47", no fuso do negócio → ISO 8601. */
    private function data(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y H:i', $valor, config('integrations.business_timezone', 'America/Sao_Paulo'))
                ->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
