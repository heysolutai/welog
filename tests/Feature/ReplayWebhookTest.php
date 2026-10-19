<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `welog:webhooks:replay` — injeta um webhook pelo mesmo caminho do provedor.
 *
 * Existe para testar a esteira sem depender de a Omie disparar, e sem abrir
 * uma rota sem token: o token da URL É a credencial do webhook, e uma rota
 * aberta "só para teste" aceitaria evento forjado de qualquer um — inclusive
 * os tópicos que APAGAM Projeto.
 */
class ReplayWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
    }

    /** O JSON real que a Omie envia numa inclusão de pedido. */
    private function eventoJson(string $orderId): string
    {
        return json_encode([
            'event' => [
                'etapa' => '10',
                'idPedido' => $orderId,
                'numeroPedido' => '69848',
                'numeroPedidoCliente' => 'Projeto Teste - Template',
            ],
            'topic' => 'VendaProduto.Incluida',
            'messageId' => '99e27638-5f18-4e0b-830d-a2d2a78c659a',
        ]);
    }

    public function test_replay_cria_o_projeto_como_o_webhook_criaria(): void
    {
        $orderId = app(OmieGateway::class)->createOrder([
            'codigo_pedido_integracao' => 'replay-1',
            'cabecalho' => ['numero_pedido' => '69848', 'codigo_cliente' => 'CLI-1'],
            'informacoes_adicionais' => ['numero_pedido_cliente' => 'Projeto Teste - Template'],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME'],
            'det' => [],
        ])['omie_order_id'];

        $arquivo = storage_path('app/private/replay.json');
        file_put_contents($arquivo, $this->eventoJson($orderId));

        $this->artisan('welog:webhooks:replay', ['--arquivo' => $arquivo])
            ->assertSuccessful();

        $event = WebhookEvent::latest('id')->first();
        $this->assertSame(WebhookEvent::SOURCE_OMIE, $event->source);
        $this->assertSame('VendaProduto.Incluida', $event->topic);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);

        $this->assertSame('69848 - Projeto Teste - Template', Project::first()->name);
    }

    /**
     * Repetir o replay do MESMO arquivo tem de valer: é o uso normal de quem
     * está testando. A idempotência por `messageId` continua valendo para o
     * provedor de verdade, na rota.
     */
    public function test_replay_repetido_cria_um_evento_novo_a_cada_vez(): void
    {
        $arquivo = storage_path('app/private/replay.json');
        file_put_contents($arquivo, $this->eventoJson('999999'));

        $this->artisan('welog:webhooks:replay', ['--arquivo' => $arquivo]);
        $this->artisan('welog:webhooks:replay', ['--arquivo' => $arquivo]);

        $this->assertSame(2, WebhookEvent::count());
    }

    /** Pedido que a Omie não conhece: evento ignorado, com o motivo. */
    public function test_pedido_inexistente_fica_ignorado_com_motivo(): void
    {
        $arquivo = storage_path('app/private/replay.json');
        file_put_contents($arquivo, $this->eventoJson('999999'));

        $this->artisan('welog:webhooks:replay', ['--arquivo' => $arquivo])
            ->assertSuccessful();

        $event = WebhookEvent::latest('id')->first();
        $this->assertSame(WebhookEvent::STATUS_IGNORED, $event->status);
        $this->assertStringContainsString('não encontrado na Omie', $event->error);
    }

    /** Reprocessar um evento que já está no log, pelo id. */
    public function test_reprocessa_evento_existente_pelo_id(): void
    {
        $event = WebhookEvent::create([
            'source' => WebhookEvent::SOURCE_OMIE,
            'external_event_id' => 'msg-antigo',
            'topic' => 'VendaProduto.Incluida',
            'payload' => ['event' => ['idPedido' => '999999']],
            'status' => WebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        $this->artisan('welog:webhooks:replay', ['--evento' => $event->id])
            ->expectsOutputToContain('reabri para processar de novo')
            ->assertSuccessful();

        $this->assertSame(1, WebhookEvent::count(), 'Reprocessar não duplica o evento');
    }

    public function test_json_invalido_falha_com_mensagem(): void
    {
        $arquivo = storage_path('app/private/replay.json');
        file_put_contents($arquivo, '{isso não é json');

        $this->artisan('welog:webhooks:replay', ['--arquivo' => $arquivo])
            ->expectsOutputToContain('JSON inválido')
            ->assertFailed();
    }
}
