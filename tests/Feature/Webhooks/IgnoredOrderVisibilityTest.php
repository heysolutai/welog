<?php

namespace Tests\Feature\Webhooks;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedido recusado pelo filtro precisa DIZER que foi recusado.
 *
 * Marcado só como "Processado", o evento produzia a pior leitura possível:
 * chegou em segundos, não gerou Projeto, e quem abria o Log de Webhooks
 * concluía que a plataforma estava lenta — quando ela, de propósito, tinha
 * ignorado um pedido de projeto fora do recorte.
 */
class IgnoredOrderVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
        $this->seed();
    }

    private function webhookDoPedido(string $orderId): void
    {
        $this->postJson('/webhooks/omie/'.self::TOKEN, [
            'messageId' => 'msg-'.$orderId,
            'topic' => 'VendaProduto.Incluida',
            'event' => ['idPedido' => $orderId],
        ])->assertOk();
    }

    public function test_pedido_fora_do_filtro_fica_ignorado_com_o_motivo(): void
    {
        config(['integrations.omie.project_codes' => '7828126392']);

        // Pedido de OUTRO projeto da Omie (Suvinil): o filtro recusa.
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getOrder')->andReturn([
            'cabecalho' => ['numero_pedido' => '69848', 'codigo_cliente' => 'CLI-1'],
            'informacoes_adicionais' => ['codProj' => 7386090746],
            'det' => [],
        ]);

        $this->webhookDoPedido('7828451375');

        $event = WebhookEvent::latest('id')->first();

        $this->assertSame(WebhookEvent::STATUS_IGNORED, $event->status);
        $this->assertStringContainsString('7386090746', $event->error);
        $this->assertStringContainsString('fora do filtro', $event->error);
        // O código aceito aparece junto: é o que mostra que o filtro está
        // configurado, e com quê.
        $this->assertStringContainsString('7828126392', $event->error);

        $this->assertSame(0, Project::count());
    }

    public function test_pedido_aceito_continua_processado_sem_motivo(): void
    {
        config(['integrations.omie.project_codes' => '7828126392']);

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getOrder')->andReturn([
            'cabecalho' => ['numero_pedido' => '69845', 'codigo_cliente' => 'CLI-1'],
            'informacoes_adicionais' => ['codProj' => 7828126392, 'numero_pedido_cliente' => 'Projeto Teste'],
            'det' => [],
        ]);
        $omie->shouldReceive('getCustomer')->andReturn([
            'omie_customer_code' => 'CLI-1', 'document' => '12345678000190', 'name' => 'ACME',
        ]);

        $this->webhookDoPedido('7828412596');

        $event = WebhookEvent::latest('id')->first();

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertNull($event->error);
        $this->assertSame('69845 - Projeto Teste', Project::first()->name);
    }

    /** Pedido que a Omie não devolve também explica o silêncio. */
    public function test_pedido_inexistente_na_omie_registra_o_motivo(): void
    {
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getOrder')->andReturn(null);

        $this->webhookDoPedido('999999');

        $event = WebhookEvent::latest('id')->first();

        $this->assertSame(WebhookEvent::STATUS_IGNORED, $event->status);
        $this->assertStringContainsString('não encontrado na Omie', $event->error);
    }
}
