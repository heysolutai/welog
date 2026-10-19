<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\Integrations\ProcessOmieWebhook;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Evento que falhou não pode ficar mudo.
 *
 * Em produção o worker rodava com `HOME=/root` depois do `setpriv` e não
 * conseguia abrir conexão com o Postgres. A exceção estourava ANTES do `try`
 * do job, então `markFailed()` nunca era chamado: centenas de eventos ficaram
 * "Na fila" para sempre, sem erro nenhum na tela — o retrato de um sistema
 * parado que parece apenas lento.
 */
class WebhookFailureVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function evento(): WebhookEvent
    {
        return WebhookEvent::create([
            'source' => WebhookEvent::SOURCE_OMIE,
            'external_event_id' => 'msg-1',
            'topic' => 'VendaProduto.Incluida',
            'payload' => ['event' => ['idPedido' => 123]],
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);
    }

    public function test_job_que_esgota_as_tentativas_marca_o_evento_com_o_motivo(): void
    {
        $event = $this->evento();

        (new ProcessOmieWebhook($event->id))
            ->failed(new RuntimeException('SQLSTATE[08006] connection to server failed'));

        $event->refresh();

        $this->assertSame(WebhookEvent::STATUS_FAILED, $event->status);
        $this->assertStringContainsString('SQLSTATE[08006]', $event->error);
    }

    /** O erro precisa chegar à tela — é por ele que se descobre o que aconteceu. */
    public function test_o_motivo_aparece_no_log_de_webhooks(): void
    {
        $event = $this->evento();
        (new ProcessOmieWebhook($event->id))->failed(new RuntimeException('motivo visível'));

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN_WEGIFT]);

        $this->actingAs($admin)
            ->get(route('admin.webhooks.index'))
            ->assertInertia(fn (Assert $p) => $p->where('events.data.0.error', 'motivo visível'));
    }

    /** Banco inalcançável é justamente o caso sem onde gravar: não pode virar segundo erro. */
    public function test_evento_inexistente_nao_estoura_no_hook_de_falha(): void
    {
        (new ProcessOmieWebhook(999999))->failed(new RuntimeException('qualquer coisa'));

        $this->assertTrue(true); // não lançou
    }
}
