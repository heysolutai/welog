<?php

namespace App\Console\Commands;

use App\Integrations\Flash\FlashApiGateway;
use App\Integrations\Support\TrackingStatus;
use App\Jobs\FluxoB\PollFlashTracking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Testa a API de rastreio Flash com as credenciais do .env — sem gravar nada.
 *
 *   php artisan welog:flash:testar                       # só autentica
 *   php artisan welog:flash:testar PED-0001 35261012...  # autentica e consulta
 *
 * As duas operações da API são somente leitura (a doc garante: não há
 * homologação separada porque nenhuma consulta altera dados), então dá para
 * rodar contra produção com segurança. Funciona com qualquer FLASH_DRIVER:
 * monta o cliente real direto, para testar ANTES de ligar o driver `api`.
 */
class FlashTest extends Command
{
    protected $signature = 'welog:flash:testar
                            {numeros?* : Números de encomenda (numEncCli) a consultar}
                            {--novo-token : Ignora o token em cache e autentica de novo}';

    protected $description = 'Testa autenticação e consulta de rastreio na API Flash, sem gravar nada';

    public function handle(): int
    {
        $cfg = config('integrations.flash');
        $faltando = array_keys(array_filter([
            'FLASH_LOGIN' => blank($cfg['login'] ?? null),
            'FLASH_SENHA' => blank($cfg['senha'] ?? null),
            'FLASH_CLIENTE_ID' => blank($cfg['cliente_id'] ?? null),
            'FLASH_CTT_IDS' => empty($cfg['ctt_ids']),
        ]));

        if ($faltando !== []) {
            $this->error('Faltam no .env: '.implode(', ', $faltando).'.');

            return self::FAILURE;
        }

        $flash = new FlashApiGateway(
            (string) $cfg['base_url'],
            (string) $cfg['login'],
            (string) $cfg['senha'],
            (int) $cfg['cliente_id'],
            (array) $cfg['ctt_ids'],
            $cfg['key'] ?? null,
        );

        $this->line("URL: <options=bold>{$cfg['base_url']}</> · clienteId {$cfg['cliente_id']} · cttId ".implode(', ', $cfg['ctt_ids']));
        $this->line('Referência usada no rastreio automático: <options=bold>'.PollFlashTracking::campoDeReferencia()
            .(PollFlashTracking::campoDeReferencia() === 'nf_number' ? ' (prefixo '.($cfg['tracking_prefix'] ?? '').')' : '').'</>');

        // Key informada x calculada: se divergirem, o 401 do token tem causa clara.
        $calculada = hash_hmac('sha256', (string) $cfg['senha'], (string) $cfg['login']);
        if (filled($cfg['key'] ?? null) && ! hash_equals($calculada, strtolower((string) $cfg['key']))) {
            $this->warn('FLASH_KEY difere do HMAC calculado de login+senha. Se o token der 401, confira os três.');
        }

        // 1. Token
        if ($this->option('novo-token')) {
            Cache::forget('flash:token:'.sha1($cfg['base_url'].'|'.$cfg['login']));
        }

        try {
            $token = $flash->token();
        } catch (Throwable $e) {
            $this->error('✗ Autenticação falhou: '.$e->getMessage());
            $this->line('  401 = login, senha ou Key inválidos — ou credencial ainda não ativada pela Bridge Link.');

            return self::FAILURE;
        }

        $this->info('✓ Autenticado. Token: '.substr($token, 0, 12).'… (guardado em cache por 23h)');

        $numeros = (array) $this->argument('numeros');

        if ($numeros === []) {
            $this->newLine();
            $this->line('Para consultar: php artisan welog:flash:testar <numero> [<numero> ...]');

            return self::SUCCESS;
        }

        // 2. Consulta: primeiro a resposta como a Bridge Link mandou, para
        // comparar com o que outra ferramenta (N8N, Postman) recebe.
        try {
            $resposta = $flash->respostaDaConsulta($numeros);
        } catch (Throwable $e) {
            $this->error('✗ Consulta falhou: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Resposta da Bridge Link: HTTP <options=bold>{$resposta->status()}</>");

        if (trim($resposta->body()) !== '') {
            $this->line(json_encode($resposta->json() ?? $resposta->body(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if ($resposta->status() === 401) {
            $this->error('✗ 401 na consulta = clienteId/cttId não conferem com a credencial.');

            return self::FAILURE;
        }

        try {
            $resultado = $flash->getTrackingMany($numeros);
        } catch (Throwable $e) {
            $this->error('✗ Consulta falhou: '.$e->getMessage());
            $this->line('  401 aqui = clienteId/cttId não conferem com a credencial.');

            return self::FAILURE;
        }

        foreach ($resultado as $numero => $tracking) {
            $this->newLine();

            if ($tracking === null) {
                $this->warn($resposta->status() === 204
                    ? "{$numero}: não localizado (204)."
                    : "{$numero}: a Bridge Link respondeu {$resposta->status()}, mas nenhum hawb corresponde a este número (veja o JSON acima).");

                continue;
            }

            $entregue = collect($tracking['events'])->contains(fn ($e) => $e['status'] === TrackingStatus::Delivered->value);

            $this->line("<options=bold>{$numero}</> · rastreio ".($tracking['tracking_code'] ?? '—')
                .($entregue ? ' · <fg=green>ENTREGUE</>' : ''));

            $this->table(
                ['Quando', 'Status WeLog', 'Evento Flash', 'Local'],
                array_map(fn ($e) => [
                    \Illuminate\Support\Carbon::parse($e['occurred_at'])->format('d/m/Y H:i'),
                    $e['status'],
                    $e['description'],
                    $e['location'] ?? '',
                ], $tracking['events']),
            );
        }

        return self::SUCCESS;
    }
}
