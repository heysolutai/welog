<?php

namespace Tests\Feature;

use App\Integrations\Flash\FlashApiGateway;
use App\Integrations\Support\TrackingStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Driver `api` da Flash contra respostas simuladas exatamente como na
 * documentação (API de rastreio compatível Flash Courier / Bridge Link).
 */
class FlashApiGatewayTest extends TestCase
{
    private const BASE = 'https://bridgelink.test';

    private const TOKEN_URL = self::BASE.'/FlashPegasus/rest/api/v1/token';

    private const CONSULTA_URL = self::BASE.'/FlashPegasus/rest/padrao/v2/consulta';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function gateway(?string $key = null): FlashApiGateway
    {
        return new FlashApiGateway(self::BASE, 'ws.suaempresa', 'SuaSenha123', 5343, ['7185', '7200'], $key);
    }

    private function token(): array
    {
        return ['access_token' => 'jwt-de-teste', 'token_type' => 'HS512', 'expires_in' => 86400];
    }

    /** O hawb do exemplo da documentação, entregue. */
    private function hawbEntregue(string $numero = 'PED-2026-0001'): array
    {
        return [
            'codigoCartao' => $numero,
            'hawbId' => 'BL0009123456',
            'meuNumero' => $numero,
            'contrato' => 7185,
            'dtBaixa' => '08/07/2026 11:47',
            'baixa' => ['recebedor' => 'MARIA DA SILVA', 'dtBaixa' => '08/07/2026 11:47'],
            'historico' => [
                ['ocorrencia' => '02/07/2026 14:20', 'eventoId' => '1400', 'evento' => 'Postado', 'local' => 'SAO PAULO', 'situacao' => ''],
                ['ocorrencia' => '04/07/2026 08:03', 'eventoId' => '2000', 'evento' => 'Enviado para unidade entregadora', 'local' => 'SAO PAULO', 'situacao' => ''],
                ['ocorrencia' => '08/07/2026 08:15', 'eventoId' => '4100', 'evento' => 'Em rota de entrega', 'local' => 'SAO PAULO', 'situacao' => ''],
                ['ocorrencia' => '08/07/2026 11:47', 'eventoId' => '4300', 'evento' => 'Entrega efetuada com sucesso', 'local' => 'SAO PAULO', 'situacao' => ''],
            ],
        ];
    }

    /** A Key do exemplo da documentação: HMAC-SHA256(chave=login, msg=senha). */
    public function test_key_e_o_hmac_da_documentacao(): void
    {
        $this->assertSame(
            '37625339127e9e29b85ae4250bc65408983fd1baaa14b2bc4a265ad2699dafa1',
            $this->gateway()->key(),
        );
    }

    public function test_autentica_e_consulta_sem_prefixo_bearer(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::CONSULTA_URL => Http::response(['statusRetorno' => '00', 'hawbs' => [$this->hawbEntregue()]], 201),
        ]);

        $tracking = $this->gateway()->getTracking('PED-2026-0001');

        $this->assertSame('BL0009123456', $tracking['tracking_code']);
        $this->assertCount(4, $tracking['events']);
        $this->assertSame(TrackingStatus::Posted->value, $tracking['events'][0]['status']);
        $this->assertSame(TrackingStatus::Delivered->value, $tracking['events'][3]['status']);
        $this->assertSame('SAO PAULO', $tracking['events'][3]['location']);

        Http::assertSent(fn (Request $r) => $r->url() === self::TOKEN_URL
            && $r->header('Authorization')[0] === '37625339127e9e29b85ae4250bc65408983fd1baaa14b2bc4a265ad2699dafa1'
            && $r['login'] === 'ws.suaempresa');

        // Token puro, sem "Bearer"; cttId sempre lista de números.
        Http::assertSent(fn (Request $r) => $r->url() === self::CONSULTA_URL
            && $r->header('Authorization')[0] === 'jwt-de-teste'
            && $r['clienteId'] === 5343
            && $r['cttId'] === [7185, 7200]
            && $r['numEncCli'] === ['PED-2026-0001']);
    }

    /** "08/07/2026 11:47" é dia/mês no fuso do negócio, não mês/dia. */
    public function test_data_da_flash_e_dia_mes_no_fuso_do_negocio(): void
    {
        config(['integrations.business_timezone' => 'America/Sao_Paulo']);
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::CONSULTA_URL => Http::response(['statusRetorno' => '00', 'hawbs' => [$this->hawbEntregue()]], 201),
        ]);

        $evento = $this->gateway()->getTracking('PED-2026-0001')['events'][3];

        $this->assertSame('2026-07-08T11:47:00-03:00', $evento['occurred_at']);
    }

    /** O token vale 24h: uma autenticação para várias consultas. */
    public function test_token_e_reaproveitado(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::CONSULTA_URL => Http::response(null, 204),
        ]);

        $this->gateway()->getTracking('A');
        $this->gateway()->getTracking('B');

        Http::assertSentCount(3); // 1 token + 2 consultas
    }

    /** Token vencido: 401 na consulta → novo token e uma segunda tentativa. */
    public function test_token_expirado_e_renovado_uma_vez(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::sequence()->push(['access_token' => 'velho'])->push(['access_token' => 'novo']),
            self::CONSULTA_URL => Http::sequence()
                ->push(null, 401)
                ->push(['statusRetorno' => '00', 'hawbs' => [$this->hawbEntregue()]], 201),
        ]);

        $tracking = $this->gateway()->getTracking('PED-2026-0001');

        $this->assertNotNull($tracking);
        Http::assertSent(fn (Request $r) => $r->url() === self::CONSULTA_URL && $r->header('Authorization')[0] === 'novo');
    }

    /** 204 = nenhum localizado. Não é erro: a coleta ainda não aconteceu. */
    public function test_nao_localizado_e_null(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::CONSULTA_URL => Http::response(null, 204),
        ]);

        $this->assertNull($this->gateway()->getTracking('NAO-EXISTE'));
    }

    /** Em lote: cada hawb volta para o seu número pelo `meuNumero`; o resto é null. */
    public function test_lote_casa_cada_resposta_pelo_numero(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::CONSULTA_URL => Http::response(['statusRetorno' => '00', 'hawbs' => [$this->hawbEntregue('B')]], 201),
        ]);

        $resultado = $this->gateway()->getTrackingMany(['A', 'B', 'C']);

        $this->assertNull($resultado['A']);
        $this->assertSame('BL0009123456', $resultado['B']['tracking_code']);
        $this->assertNull($resultado['C']);
        Http::assertSent(fn (Request $r) => $r->url() === self::CONSULTA_URL && $r['numEncCli'] === ['A', 'B', 'C']);
    }

    /** `meuNumero` sem o prefixo (a Bridge Link tira antes da busca) ainda casa. */
    public function test_numero_devolvido_sem_prefixo_casa(): void
    {
        $hawb = $this->hawbEntregue('69284');
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::CONSULTA_URL => Http::response(['statusRetorno' => '00', 'hawbs' => [$hawb]], 201),
        ]);

        $resultado = $this->gateway()->getTrackingMany(['WEBOX44883', 'WEBOX69284']);

        $this->assertNull($resultado['WEBOX44883']);
        $this->assertSame('BL0009123456', $resultado['WEBOX69284']['tracking_code']);
    }

    /** Um número enviado e um hawb de volta: é ele, mesmo com outro número no eco. */
    public function test_um_enviado_um_devolvido_e_ele(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::CONSULTA_URL => Http::response(['statusRetorno' => '00', 'hawbs' => [$this->hawbEntregue('PEDIDO-77')]], 201),
        ]);

        $this->assertSame('BL0009123456', $this->gateway()->getTracking('WEBOX69284')['tracking_code']);
    }

    /** Pedido sem movimentação (evento 1100) aparece, mas não conta como postado. */
    public function test_aguardando_postagem_nao_vira_postado(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response($this->token()),
            self::CONSULTA_URL => Http::response(['statusRetorno' => '00', 'hawbs' => [[
                'meuNumero' => 'P1', 'hawbId' => '', 'historico' => [
                    ['ocorrencia' => '05/10/2026 09:00', 'eventoId' => '1100', 'evento' => 'Em arquivo-aguardando Postagem'],
                ],
            ]]], 201),
        ]);

        $tracking = $this->gateway()->getTracking('P1');

        $this->assertNull($tracking['tracking_code']);
        $this->assertSame(TrackingStatus::Other->value, $tracking['events'][0]['status']);
    }

    /** Código de evento desconhecido nunca fecha o pedido como entregue. */
    public function test_evento_desconhecido_nunca_e_entrega(): void
    {
        foreach (['4200', '9999', '', null, '1100'] as $codigo) {
            $this->assertFalse(TrackingStatus::fromFlashEvento($codigo)->isDelivered());
        }
        $this->assertTrue(TrackingStatus::fromFlashEvento('4300')->isDelivered());
    }

    public function test_credencial_invalida_falha_com_erro(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(null, 401)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $this->gateway()->getTracking('PED-2026-0001');
    }
}
