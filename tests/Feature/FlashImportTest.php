<?php

namespace Tests\Feature;

use App\Contracts\Integrations\FlashGateway;
use App\Integrations\Flash\FlashApiGateway;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\User;
use App\Services\FluxoB\SendShipmentsToFlash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Resgate com NF emitida → encomenda na Flash (importação da Bridge Link,
 * POST /FlashPegasus/rest/padrao/importacao).
 *
 * O número da encomenda é o mesmo que o rastreio consulta (WEBOX + nº da NF):
 * importada aqui, ela é acompanhada pelo PollFlashTracking sem tradução.
 */
class FlashImportTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35260951785932000128550010000689811935151876';

    private const IMPORTACAO = 'https://bridgelink.test/FlashPegasus/rest/padrao/importacao';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
        $this->seed();
    }

    private function resgate(array $extra = []): AppnozImportRow
    {
        $import = AppnozImport::create([
            'filename' => 'reconhece-TESTE', 'file_hash' => 'hash-'.uniqid(), 'source' => AppnozImport::SOURCE_WEBHOOK,
        ]);

        return $import->rows()->create(array_merge([
            'row_number' => 1, 'external_id' => 'TESTE-0006',
            'cpf' => '04166533169', 'customer_name' => 'Yago Machado',
            'email' => 'yago@teste.com.br', 'phone' => '(62) 99999-0000',
            'product_code' => '1034', 'quantity' => 1,
            'zip' => '74920190', 'street' => 'Rua Fagundes Varella', 'number' => 'Qd 58 Lt 2',
            'complement' => 'Casa 2', 'district' => 'Cidade Satélite São Luiz',
            'city' => 'Aparecida de Goiânia', 'state' => 'GO',
            'status' => AppnozImportRow::STATUS_ORDER_CREATED,
            'omie_order_id' => '7830935972', 'omie_order_number' => '70669',
        ], $extra));
    }

    private function flashApi(array $import = []): FlashApiGateway
    {
        return new FlashApiGateway(
            'https://bridgelink.test', 'ws.teste', 'segredo', 5343, ['7185', '7200'], null,
            $import['usuario'] ?? null, $import['senha'] ?? null, $import['ccusto'] ?? null, 'WeGift',
        );
    }

    public function test_a_encomenda_sai_com_o_numero_do_rastreio_e_os_dados_do_resgate(): void
    {
        $resgate = $this->resgate(['nf_key' => self::CHAVE, 'status' => AppnozImportRow::STATUS_INVOICED]);

        $encomenda = app(SendShipmentsToFlash::class)->encomenda($resgate);

        $this->assertSame('WEBOX68981', $encomenda['num_enc_cli']);
        $this->assertSame(self::CHAVE, $encomenda['chave_nf']);
        $this->assertSame('68981', $encomenda['cod_doc']);
        $this->assertSame('04166533169', $encomenda['cpf_des']);
        // Sem acento nem pontuação: era o que derrubava a importação.
        $this->assertSame([
            'nome_des' => 'Yago Machado',
            'logr_dest' => 'Rua Fagundes Varella',
            'num_des' => 'Qd 58 Lt 2',
            'compl_end_dest' => 'Casa 2',
            'bairro_des' => 'Cidade Satelite Sao Luiz',
            'cid_dest' => 'Aparecida de Goiania',
            'uf_dest' => 'GO',
            'cep_dest' => '74920190',
            'fone1_des' => '62999990000',
        ], $encomenda['endHawbs']);

        // Como no exemplo de importação da Bridge Link: quantidade em texto,
        // sem e-mail (o único campo com pontuação) e sem observação.
        $this->assertSame('1', $encomenda['qtde_itens']);
        $this->assertArrayNotHasKey('email_des', $encomenda);
        $this->assertArrayNotHasKey('obs1', $encomenda);
    }

    /** Endereço como as pessoas escrevem: tudo sai sem acento nem pontuação. */
    public function test_endereco_com_pontuacao_sai_limpo(): void
    {
        $resgate = $this->resgate([
            'nf_key' => self::CHAVE, 'status' => AppnozImportRow::STATUS_INVOICED,
            'customer_name' => "Joana D'Ávila", 'street' => 'R. Ipê, nº 12 - fundos', 'number' => 'S/N',
            'complement' => 'Apto. 3º; bloco "B"', 'email' => 'joana.d\'avila@empresa.com.br',
        ]);

        $encomenda = app(SendShipmentsToFlash::class)->encomenda($resgate);

        $this->assertSame('Joana D Avila', $encomenda['endHawbs']['nome_des']);
        $this->assertSame('R Ipe no 12 fundos', $encomenda['endHawbs']['logr_dest']);
        $this->assertSame('SN', $encomenda['endHawbs']['num_des']);
        $this->assertSame('Apto 3o bloco B', $encomenda['endHawbs']['compl_end_dest']);
        $this->assertArrayNotHasKey('email_des', $encomenda);
    }

    /** Os campos do contrato, quando configurados, vão em toda encomenda com o tipo do exemplo. */
    public function test_campos_do_contrato_vao_com_o_tipo_do_exemplo(): void
    {
        Http::fake([self::IMPORTACAO => Http::response([['type' => 'SUCESS']])]);

        $flash = new FlashApiGateway(
            'https://bridgelink.test', 'ws.teste', 'segredo', 5343, ['7185'], null, null, null, null, 'WeGift',
            ['tipo_enc_id' => '21796', 'prod_flash_id' => '157', 'frq_rec_id' => 'CRS', 'id_local_rem' => '40013', 'num_cliente' => '102'],
        );

        $flash->importShipments([['num_enc_cli' => 'WEBOX68981']]);

        Http::assertSent(fn (Request $r) => $r->data() === [[
            'cliente_id' => 5343,
            'ctt_id' => 7185,
            'nome_rem' => 'WeGift',
            'tipo_enc_id' => 21796,
            'prod_flash_id' => 157,
            'frq_rec_id' => 'CRS',
            'id_local_rem' => '40013',
            'num_cliente' => 102,
            'num_enc_cli' => 'WEBOX68981',
        ]]);
    }

    /** Contra a API: HTTP Basic, lista, conta e contrato carimbados pelo gateway. */
    public function test_importacao_na_bridge_link(): void
    {
        Http::fake([self::IMPORTACAO => Http::response([[
            'type' => 'SUCESS', 'text' => 'Operacao realizada com sucesso.', 'arquivo' => 'import_padrao_5343_7185.txt',
        ]])]);

        $retorno = $this->flashApi(['ccusto' => 38466])->importShipments([['num_enc_cli' => 'WEBOX68981']]);

        $this->assertSame('import_padrao_5343_7185.txt', $retorno['arquivo']);

        Http::assertSent(fn (Request $r) => $r->url() === self::IMPORTACAO
            && $r->header('Authorization')[0] === 'Basic '.base64_encode('ws.teste:segredo')
            && $r->data() === [[
                'cliente_id' => 5343,
                'ctt_id' => 7185,
                'ccusto_id' => 38466,
                'nome_rem' => 'WeGift',
                'num_enc_cli' => 'WEBOX68981',
            ]]);
    }

    public function test_credencial_propria_da_importacao_quando_configurada(): void
    {
        Http::fake([self::IMPORTACAO => Http::response([['type' => 'SUCESS']])]);

        $this->flashApi(['usuario' => 'importador', 'senha' => 'outra'])->importShipments([['num_enc_cli' => 'X']]);

        Http::assertSent(fn (Request $r) => $r->header('Authorization')[0] === 'Basic '.base64_encode('importador:outra'));
    }

    public function test_credencial_recusada_vira_erro_claro(): void
    {
        Http::fake([self::IMPORTACAO => Http::response(null, 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Importação Flash recusada (401)');

        $this->flashApi()->importShipments([['num_enc_cli' => 'X']]);
    }

    /** A nota chegou: a encomenda vai sozinha, e o resgate guarda quando foi. */
    public function test_nota_registrada_envia_a_encomenda(): void
    {
        $resgate = $this->resgate();

        $resgate->registrarNota(self::CHAVE);

        $this->assertNotNull($resgate->fresh()->flash_sent_at);
        $this->assertArrayHasKey('WEBOX68981', app(FlashGateway::class)->importadas());
    }

    public function test_com_envio_desligado_a_nota_nao_envia(): void
    {
        config(['integrations.flash.import.enabled' => false]);
        $resgate = $this->resgate();

        $resgate->registrarNota(self::CHAVE);

        $this->assertNull($resgate->fresh()->flash_sent_at);
        $this->assertSame([], app(FlashGateway::class)->importadas());
    }

    /** Nota simulada não existe na Sefaz: nunca vai à Flash de verdade. */
    public function test_nota_simulada_nao_vai_a_flash_real(): void
    {
        config(['integrations.flash.driver' => 'api']);
        $resgate = $this->resgate([
            'nf_key' => '99'.str_repeat('0', 42), 'nf_simulated' => true, 'status' => AppnozImportRow::STATUS_INVOICED,
        ]);

        $this->assertFalse(SendShipmentsToFlash::podeEnviar($resgate));
        $this->assertSame(0, SendShipmentsToFlash::pendentes()->count());
    }

    /** A recusa fica no resgate, para a página dizer por que não foi. */
    public function test_recusa_da_flash_fica_gravada_no_resgate(): void
    {
        $resgate = $this->resgate(['nf_key' => self::CHAVE, 'status' => AppnozImportRow::STATUS_INVOICED]);

        $flash = $this->mock(FlashGateway::class);
        $flash->shouldReceive('importShipments')->andThrow(new RuntimeException('Importação Flash recusada (401): credencial.'));

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('imports.rows.flash', $resgate))
            ->assertSessionHasErrors(['flash' => 'A Flash recusou: Importação Flash recusada (401): credencial.']);

        $resgate->refresh();
        $this->assertNull($resgate->flash_sent_at);
        $this->assertSame('Importação Flash recusada (401): credencial.', $resgate->flash_error);
    }

    public function test_botao_envia_e_a_pagina_mostra_o_envio(): void
    {
        $resgate = $this->resgate(['nf_key' => self::CHAVE, 'status' => AppnozImportRow::STATUS_INVOICED]);
        $operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->actingAs($operador)
            ->get(route('imports.rows.show', $resgate))
            ->assertInertia(fn (Assert $p) => $p
                ->where('permissions.send_to_flash', true)
                ->where('resgate.flash_sent_at', null));

        $this->actingAs($operador)
            ->post(route('imports.rows.flash', $resgate))
            ->assertSessionHas('status', 'Encomenda WEBOX68981 enviada à Flash.');

        $this->assertNotNull($resgate->fresh()->flash_sent_at);
    }

    /** A página do resgate mostra o JSON que vai para a Flash, como vai. */
    public function test_pagina_mostra_o_json_enviado_a_flash(): void
    {
        $resgate = $this->resgate(['nf_key' => self::CHAVE, 'status' => AppnozImportRow::STATUS_INVOICED]);
        $operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->actingAs($operador)
            ->get(route('imports.rows.show', $resgate))
            ->assertInertia(fn (Assert $p) => $p->where('resgate.flash_json', fn (?string $json) => $json !== null
                && str_contains($json, '"num_enc_cli": "WEBOX68981"')
                && str_contains($json, '"bairro_des": "Cidade Satelite Sao Luiz"')));
    }

    /** O corpo do envio é o mesmo texto que a tela mostra, com a conta na frente. */
    public function test_corpo_da_importacao_tem_a_conta_e_nao_escapa_barra(): void
    {
        $corpo = $this->flashApi(['ccusto' => 99])->corpoDaImportacao([['num_enc_cli' => 'WEBOX68981', 'cod_doc' => 'NF/1']]);

        $this->assertSame(
            '[{"cliente_id":5343,"ctt_id":7185,"ccusto_id":99,"nome_rem":"WeGift","num_enc_cli":"WEBOX68981","cod_doc":"NF/1"}]',
            $corpo,
        );
    }

    /** O que já foi não sai de novo na varredura. */
    public function test_enviado_nao_volta_para_a_fila(): void
    {
        $this->resgate(['nf_key' => self::CHAVE, 'status' => AppnozImportRow::STATUS_INVOICED, 'flash_sent_at' => now()]);

        $this->assertSame(0, SendShipmentsToFlash::pendentes()->count());
    }
}
