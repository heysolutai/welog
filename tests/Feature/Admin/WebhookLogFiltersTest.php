<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Filtros do Log de Webhooks.
 *
 * A tela existe para responder "o webhook chegou e não virou nada — onde
 * parou?". Com centenas de eventos, isso só é respondível filtrando: por
 * origem, tópico, período e pelo conteúdo do payload — que é onde mora o
 * número do pedido que o operador tem em mãos.
 */
class WebhookLogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN_WEGIFT]);
    }

    private function evento(array $atributos = []): WebhookEvent
    {
        static $sequencia = 0;
        $sequencia++;

        return WebhookEvent::create(array_merge([
            'source' => WebhookEvent::SOURCE_OMIE,
            'external_event_id' => "msg-{$sequencia}",
            'topic' => 'VendaProduto.Incluida',
            'payload' => ['event' => ['idPedido' => 100 + $sequencia]],
            'status' => WebhookEvent::STATUS_RECEIVED,
        ], $atributos));
    }

    private function abrir(array $filtros = [])
    {
        return $this->actingAs($this->admin())->get(route('admin.webhooks.index', $filtros));
    }

    public function test_filtra_por_origem(): void
    {
        $this->evento(['source' => WebhookEvent::SOURCE_OMIE]);
        $this->evento(['source' => WebhookEvent::SOURCE_TRELLO, 'topic' => 'card.moved']);

        $this->abrir(['source' => 'trello'])
            ->assertInertia(fn (Assert $p) => $p
                ->has('events.data', 1)
                ->where('events.data.0.source', 'trello')
                ->etc());
    }

    public function test_filtra_por_topico(): void
    {
        $this->evento(['topic' => 'VendaProduto.Incluida']);
        $this->evento(['topic' => 'Estoque.Alterado']);

        $this->abrir(['topic' => 'Estoque.Alterado'])
            ->assertInertia(fn (Assert $p) => $p
                ->has('events.data', 1)
                ->where('events.data.0.topic', 'Estoque.Alterado')
                ->etc());
    }

    /** O operador tem o número do pedido, não o id da mensagem da Omie. */
    public function test_busca_alcanca_o_conteudo_do_payload(): void
    {
        $alvo = $this->evento(['payload' => ['event' => ['idPedido' => 66661]]]);
        $this->evento(['payload' => ['event' => ['idPedido' => 12345]]]);

        $this->abrir(['q' => '66661'])
            ->assertInertia(fn (Assert $p) => $p
                ->has('events.data', 1)
                ->where('events.data.0.id', $alvo->id)
                ->etc());
    }

    public function test_busca_alcanca_o_id_externo(): void
    {
        $alvo = $this->evento(['external_event_id' => 'e2e-order-200623']);
        $this->evento(['external_event_id' => 'outro-id']);

        $this->abrir(['q' => 'order-200623'])
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)
                ->where('events.data.0.id', $alvo->id)->etc());
    }

    /**
     * Buscar pelo número da linha é o caminho de quem veio de um log ou de um
     * print. Os payloads aqui são propositalmente sem dígito: a busca é por
     * SUBSTRING e inclui o payload, então "2" também acharia um `idPedido: 102`
     * — o que é o comportamento certo, mas não é o que este teste mede.
     */
    public function test_busca_alcanca_o_numero_do_evento(): void
    {
        $this->evento(['payload' => ['event' => ['ref' => 'alfa']]]);
        $alvo = $this->evento(['payload' => ['event' => ['ref' => 'beta']]]);

        $this->abrir(['q' => (string) $alvo->id])
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)
                ->where('events.data.0.id', $alvo->id)->etc());
    }

    public function test_periodo_recorta_pela_data_de_recebimento(): void
    {
        $recente = $this->evento();
        $antigo = $this->evento();
        $antigo->forceFill(['created_at' => now()->subDays(20)])->save();

        $this->abrir(['period' => '7d'])
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)
                ->where('events.data.0.id', $recente->id)->etc());

        // Sem período, os dois voltam — a janela não pode virar filtro implícito.
        $this->abrir()->assertInertia(fn (Assert $p) => $p->has('events.data', 2)->etc());
    }

    /**
     * O bug que este teste tranca: as contagens eram globais. Filtrando por
     * Trello, a aba "Com falha" seguia mostrando as falhas da Omie — número que
     * não corresponde a nenhuma linha clicável.
     */
    public function test_contagem_das_abas_respeita_os_demais_filtros(): void
    {
        $this->evento(['source' => WebhookEvent::SOURCE_OMIE, 'status' => WebhookEvent::STATUS_FAILED]);
        $this->evento(['source' => WebhookEvent::SOURCE_OMIE, 'status' => WebhookEvent::STATUS_FAILED]);
        $this->evento(['source' => WebhookEvent::SOURCE_TRELLO, 'topic' => 'card.moved']);

        $this->abrir(['source' => 'trello'])
            ->assertInertia(fn (Assert $p) => $p
                ->where('counts.total', 1)
                ->where('counts.failed', 0)
                ->where('counts.received', 1)
                ->etc());

        $this->abrir()
            ->assertInertia(fn (Assert $p) => $p
                ->where('counts.total', 3)
                ->where('counts.failed', 2)
                ->etc());
    }

    /**
     * O diagnóstico do topo responde "a integração está de pé?". Se lesse as
     * contagens filtradas, uma busca sem resultado acusaria "nenhum webhook
     * recebido" num sistema saudável.
     */
    public function test_diagnostico_ignora_os_filtros(): void
    {
        $this->evento();

        $this->abrir(['q' => 'nao-existe-em-lugar-nenhum'])
            ->assertInertia(fn (Assert $p) => $p
                ->has('events.data', 0)
                ->where('counts.total', 0)
                ->where('diagnosis.totalEvents', 1)
                ->etc());
    }

    /** Campo livre não servia: ninguém digita "VendaProduto.Incluida" de cabeça. */
    public function test_lista_de_topicos_vem_do_que_realmente_chegou(): void
    {
        $this->evento(['topic' => 'VendaProduto.Incluida']);
        $this->evento(['topic' => 'VendaProduto.Incluida']);
        $this->evento(['topic' => 'Estoque.Alterado']);
        $this->evento(['source' => WebhookEvent::SOURCE_TRELLO, 'topic' => 'card.moved']);

        // Sem origem: todos os tópicos, sem repetir.
        $this->abrir()->assertInertia(fn (Assert $p) => $p
            ->where('topics', ['Estoque.Alterado', 'VendaProduto.Incluida', 'card.moved'])
            ->etc());

        // Com origem: só os daquela origem — oferecer um tópico da Omie sob
        // "Trello" devolveria lista vazia sem explicar de onde veio o vazio.
        $this->abrir(['source' => 'trello'])
            ->assertInertia(fn (Assert $p) => $p->where('topics', ['card.moved'])->etc());
    }

    public function test_filtros_se_combinam(): void
    {
        $alvo = $this->evento(['topic' => 'Estoque.Alterado', 'status' => WebhookEvent::STATUS_FAILED]);
        $this->evento(['topic' => 'Estoque.Alterado', 'status' => WebhookEvent::STATUS_PROCESSED]);
        $this->evento(['topic' => 'VendaProduto.Incluida', 'status' => WebhookEvent::STATUS_FAILED]);

        $this->abrir(['topic' => 'Estoque.Alterado', 'status' => 'failed'])
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)
                ->where('events.data.0.id', $alvo->id)->etc());
    }

    public function test_filtro_invalido_e_recusado(): void
    {
        $this->abrir(['source' => 'sap'])->assertSessionHasErrors('source');
        $this->abrir(['period' => 'ontem'])->assertSessionHasErrors('period');
    }

    public function test_operador_sem_perfil_de_admin_nao_acessa(): void
    {
        $n1 = User::factory()->create(['role' => User::ROLE_WEGIFT_N1]);

        $this->actingAs($n1)->get(route('admin.webhooks.index'))->assertForbidden();
    }
}
