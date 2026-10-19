<?php

namespace Tests\Feature\Admin;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `welog:webhooks:payload` — o corpo do webhook, formatado, no terminal. */
class WebhookPayloadCommandTest extends TestCase
{
    use RefreshDatabase;

    private function evento(string $topic, array $payload): WebhookEvent
    {
        return WebhookEvent::create([
            'source' => WebhookEvent::SOURCE_OMIE,
            'external_event_id' => uniqid(),
            'topic' => $topic,
            'payload' => $payload,
        ]);
    }

    public function test_mostra_o_ultimo_evento_com_o_corpo_formatado(): void
    {
        $this->evento('VendaProduto.Incluida', ['event' => ['idPedido' => 1]]);
        $this->evento('OrdemServico.Alterada', ['event' => ['nCodOS' => 4242, 'cNumOS' => '000123']]);

        $this->artisan('welog:webhooks:payload')
            ->expectsOutputToContain('OrdemServico.Alterada')
            ->expectsOutputToContain('"nCodOS": 4242')
            ->assertSuccessful();
    }

    public function test_filtra_por_topico_e_por_id(): void
    {
        $pedido = $this->evento('VendaProduto.Incluida', ['event' => ['idPedido' => 77]]);
        $this->evento('Estoque.Alterado', ['event' => ['codigo_produto' => 'KIT-1']]);

        $this->artisan('welog:webhooks:payload', ['--topic' => 'VendaProduto'])
            ->expectsOutputToContain('"idPedido": 77')
            ->assertSuccessful();

        $this->artisan('welog:webhooks:payload', ['id' => $pedido->id])
            ->expectsOutputToContain('"idPedido": 77')
            ->assertSuccessful();
    }

    public function test_lista_os_ultimos_e_avisa_quando_nao_ha_nada(): void
    {
        $this->artisan('welog:webhooks:payload', ['--topic' => 'OS'])->assertFailed();

        $this->evento('OrdemServico.Incluida', ['event' => []]);

        $this->artisan('welog:webhooks:payload', ['--list' => true])
            ->expectsOutputToContain('OrdemServico.Incluida')
            ->assertSuccessful();
    }
}
