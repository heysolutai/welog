<?php

namespace Tests\Feature\Webhooks;

use App\Contracts\Integrations\OmieGateway;
use App\Jobs\FluxoB\PollFlashTracking;
use App\Jobs\FluxoB\SyncOmieInvoices;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Nota autorizada na Omie → resgate em "NF emitida", na hora.
 *
 * O webhook NFe.NotaAutorizada traz `id_pedido`, o ID interno do pedido — o
 * mesmo que o resgate guardou ao criar o pedido. A nota da operação inteira
 * passa por aqui; só a de resgate vira processamento.
 */
class OmieInvoiceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-omie-teste';

    private const CHAVE = '35260951785932000128550010000689811935151876';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
        $this->seed();
    }

    private function resgate(string $status = AppnozImportRow::STATUS_ORDER_CREATED): AppnozImportRow
    {
        $import = AppnozImport::create([
            'filename' => 'reconhece-TESTE-006', 'file_hash' => 'hash-006', 'source' => AppnozImport::SOURCE_WEBHOOK,
        ]);

        return $import->rows()->create([
            'row_number' => 1, 'external_id' => 'TESTE-0006',
            'cpf' => '04166533169', 'customer_name' => 'Yago Machado',
            'product_code' => '1034', 'quantity' => 1, 'status' => $status,
            'omie_order_id' => '7830935972', 'omie_order_number' => '70669',
        ]);
    }

    /** O corpo como a Omie manda (o do pedido 70669). */
    private function nota(string $pedido = '7830935972', string $messageId = '333baad3-2d12-4cb7-984b-4b74243240db'): array
    {
        return [
            'messageId' => $messageId,
            'topic' => 'NFe.NotaAutorizada',
            'event' => [
                'acao' => 'autorizada',
                'id_nf' => 7831068037,
                'serie' => '001',
                'ambiente' => 'P',
                'operacao' => '11',
                'data_emis' => '2026-09-30T00:00:00-03:00',
                'hora_emis' => '14:18:38',
                'id_pedido' => (int) $pedido,
                'nfe_chave' => self::CHAVE,
                'numero_nf' => '00068981',
                'empresa_cnpj' => '51785932000128',
                'empresa_uf' => 'SP',
            ],
            'author' => ['name' => 'Integração', 'email' => 'no-reply@omie.com.br', 'userId' => 89],
            'appKey' => '4096265237064',
            'appHash' => 'webox-5ebbi2dk',
        ];
    }

    private function enviar(array $corpo)
    {
        return $this->postJson('/webhooks/omie/'.self::TOKEN, $corpo);
    }

    public function test_nota_do_pedido_do_resgate_vira_nf_emitida(): void
    {
        $resgate = $this->resgate();

        $this->enviar($this->nota())->assertOk();

        $resgate->refresh();
        $this->assertSame(AppnozImportRow::STATUS_INVOICED, $resgate->status);
        $this->assertSame(self::CHAVE, $resgate->nf_key);
        $this->assertSame('2026-09-30 14:18:38', $resgate->nf_issued_at->setTimezone(config('integrations.business_timezone'))->format('Y-m-d H:i:s'));
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::firstOrFail()->status);

        // E o rastreio já sabe como a Flash conhece a encomenda.
        $this->assertSame('WEBOX68981', PollFlashTracking::referencia($resgate));
    }

    /** Nota de pedido que não é resgate: fica no log como ignorada, sem fila. */
    public function test_nota_de_outro_pedido_e_ignorada(): void
    {
        $this->resgate();

        $this->enviar($this->nota('9999999999'))->assertOk();

        $this->assertSame(WebhookEvent::STATUS_IGNORED, WebhookEvent::firstOrFail()->status);
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, AppnozImportRow::firstOrFail()->status);
    }

    /** Resgate já em transporte não volta para "NF emitida". */
    public function test_nota_nao_faz_o_resgate_voltar_de_etapa(): void
    {
        $resgate = $this->resgate(AppnozImportRow::STATUS_DISPATCHED);

        $this->enviar($this->nota())->assertOk();

        $this->assertSame(AppnozImportRow::STATUS_DISPATCHED, $resgate->fresh()->status);
        $this->assertSame(self::CHAVE, $resgate->fresh()->nf_key);
    }

    /** A varredura de reserva lê além da primeira página da ListarNF. */
    public function test_varredura_acha_a_nota_na_segunda_pagina(): void
    {
        $resgate = $this->resgate();

        $outras = array_map(fn (int $i) => [
            'nf_key' => str_pad((string) $i, 44, '1', STR_PAD_LEFT),
            'omie_order_id' => (string) (1000 + $i),
            'issued_at' => '29/09/2026',
        ], range(1, 100));

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('listInvoices')->withArgs(fn ($desde, $pagina) => $pagina === 1)->andReturn($outras);
        $omie->shouldReceive('listInvoices')->withArgs(fn ($desde, $pagina) => $pagina === 2)->andReturn([[
            'nf_key' => self::CHAVE, 'omie_order_id' => '7830935972', 'issued_at' => '30/09/2026',
        ]]);

        app()->call([new SyncOmieInvoices, 'handle']);

        $resgate->refresh();
        $this->assertSame(AppnozImportRow::STATUS_INVOICED, $resgate->status);
        $this->assertSame('2026-09-30', $resgate->nf_issued_at->format('Y-m-d'));
    }
}
