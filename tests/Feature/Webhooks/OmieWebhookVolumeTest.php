<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\Integrations\EvaluateOmieStock;
use App\Jobs\Integrations\ProcessOmieWebhook;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Volume da fila.
 *
 * A Omie notifica dezenas de tópicos e dispara `Estoque.Alterado` a cada
 * movimentação da conta inteira. Enfileirar um job por evento acumulou
 * milhares de jobs em produção e levou a Omie a recusar por consumo
 * redundante. Estes testes prendem o comportamento que corrigiu isso.
 */
class OmieWebhookVolumeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste';

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.omie.webhook_token' => self::TOKEN]);
    }

    private function send(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/webhooks/omie/'.self::TOKEN, $payload);
    }

    public function test_topico_sem_acao_e_gravado_mas_nao_entra_na_fila(): void
    {
        Queue::fake();

        $this->send([
            'messageId' => 'msg-financeiro-1',
            'topic' => 'ContaPagar.Alterada',
            'event' => ['id' => 1],
        ])->assertOk();

        // Continua no log — o operador precisa ver o que a Omie manda…
        $this->assertDatabaseHas('webhook_events', [
            'external_event_id' => 'msg-financeiro-1',
            'status' => WebhookEvent::STATUS_IGNORED,
        ]);

        // …mas não gasta worker para concluir que não há nada a fazer.
        Queue::assertNothingPushed();
    }

    public function test_rajada_de_estoque_vira_uma_unica_reavaliacao(): void
    {
        Queue::fake();

        // Como numa importação de nota: dezenas de movimentações em segundos
        foreach (range(1, 40) as $i) {
            $this->send([
                'messageId' => "msg-estoque-{$i}",
                'topic' => 'Estoque.Alterado',
                'event' => ['codigo_produto' => "KIT-{$i}"],
            ])->assertOk();
        }

        $this->assertSame(40, WebhookEvent::where('topic', 'Estoque.Alterado')->count());

        // 40 eventos, 40 registros no log, UM job de reavaliação.
        // (o ShouldBeUnique do job descarta os despachos concorrentes)
        Queue::assertPushed(EvaluateOmieStock::class);
        Queue::assertNotPushed(ProcessOmieWebhook::class);
    }

    public function test_pedido_de_venda_continua_indo_para_a_fila(): void
    {
        Queue::fake();

        $this->send([
            'messageId' => 'msg-pedido-1',
            'topic' => 'VendaProduto.Incluida',
            'event' => ['idPedido' => '900001'],
        ])->assertOk();

        // O que gera Projeto não pode ser afetado pelo filtro
        Queue::assertPushed(ProcessOmieWebhook::class);
    }

    public function test_reentrega_do_mesmo_evento_nao_enfileira_de_novo(): void
    {
        Queue::fake();

        $payload = [
            'messageId' => 'msg-pedido-repetido',
            'topic' => 'VendaProduto.Incluida',
            'event' => ['idPedido' => '900002'],
        ];

        $this->send($payload)->assertOk();
        $this->send($payload)->assertOk();
        $this->send($payload)->assertOk();

        $this->assertSame(1, WebhookEvent::where('external_event_id', 'msg-pedido-repetido')->count());
        Queue::assertPushed(ProcessOmieWebhook::class, 1);
    }

    public function test_ping_de_verificacao_nao_vira_evento(): void
    {
        Queue::fake();

        $this->send(['ping' => 'omie'])->assertOk()->assertJson(['pong' => 'omie']);

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }
}
