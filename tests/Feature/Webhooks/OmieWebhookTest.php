<?php

namespace Tests\Feature\Webhooks;

use App\Contracts\Integrations\OmieGateway;
use App\Jobs\Integrations\ProcessOmieWebhook;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OmieWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'messageId' => 'msg-001',
            'topic' => 'VendaProduto.Incluida',
            'event' => ['idPedido' => 123456, 'etapa' => '10'],
            'appKey' => 'app-key',
        ], $overrides);
    }

    public function test_rejects_invalid_token(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/omie/wrong-token', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_persists_event_and_dispatches_job(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/omie/'.self::TOKEN, $this->payload())
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertDatabaseHas('webhook_events', [
            'source' => WebhookEvent::SOURCE_OMIE,
            'external_event_id' => 'msg-001',
            'topic' => 'VendaProduto.Incluida',
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);

        Queue::assertPushed(ProcessOmieWebhook::class, 1);
    }

    public function test_redelivery_of_same_message_is_idempotent(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/omie/'.self::TOKEN, $this->payload())->assertOk();
        $this->postJson('/webhooks/omie/'.self::TOKEN, $this->payload())->assertOk();

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessOmieWebhook::class, 1);
    }

    public function test_answers_omie_verification_ping(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/omie/'.self::TOKEN, ['ping' => 'omie'])
            ->assertOk()
            ->assertJson(['pong' => 'omie']);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_processing_marks_event_as_processed(): void
    {
        $this->seed();

        // Pedido que existe de verdade na Omie simulada: é o caminho feliz,
        // em que o evento vira Projeto e fica "Processado".
        $orderId = app(OmieGateway::class)->createOrder([
            'codigo_pedido_integracao' => 'webhook-002',
            'cabecalho' => ['numero_pedido' => 'PV-002', 'codigo_cliente' => 'CLI-1'],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME'],
            'det' => [],
        ])['omie_order_id'];

        $event = WebhookEvent::create([
            'source' => WebhookEvent::SOURCE_OMIE,
            'external_event_id' => 'msg-002',
            'topic' => 'VendaProduto.Incluida',
            'payload' => $this->payload([
                'messageId' => 'msg-002',
                'event' => ['idPedido' => $orderId, 'etapa' => '10'],
            ]),
        ]);

        (new ProcessOmieWebhook($event->id))->handle();

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    /**
     * Pedido que a Omie não devolve não é "processado": é IGNORADO, com o
     * motivo à vista. Marcado como processado, o evento dizia que tudo correu
     * bem enquanto nenhum Projeto existia — e a investigação começava pelo
     * lugar errado, procurando lentidão onde havia recusa.
     */
    public function test_processing_marks_unknown_order_as_ignored_with_reason(): void
    {
        $event = WebhookEvent::create([
            'source' => WebhookEvent::SOURCE_OMIE,
            'external_event_id' => 'msg-003',
            'topic' => 'VendaProduto.Incluida',
            'payload' => $this->payload(['messageId' => 'msg-003']),
        ]);

        (new ProcessOmieWebhook($event->id))->handle();

        $this->assertSame(WebhookEvent::STATUS_IGNORED, $event->fresh()->status);
        $this->assertStringContainsString('não encontrado na Omie', $event->fresh()->error);
    }
}
