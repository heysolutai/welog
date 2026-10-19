<?php

namespace Tests\Feature\Webhooks;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Só `VendaProduto.Incluida` dá origem a Projeto.
 *
 * `Alterada` é notícia sobre um pedido que já existe — se ela criasse, um
 * pedido cuja inclusão nunca chegou (ou foi recusada de propósito, como os do
 * Fluxo B) entraria na esteira pela porta dos fundos, e ninguém saberia de
 * onde veio.
 *
 * `Excluida`/`Cancelada` fazem o caminho inverso: o pedido morreu na Omie e o
 * Projeto sai da plataforma — exceto quando já há kit expedido no mundo físico
 * (decisão conservadora: mantém e loga).
 */
class OmieOrderTopicsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        // Estado do fake da Omie isolado por teste: o fake/omie.json real
        // sobrevive entre execuções, e a idempotência de `topicos-1` passaria
        // a devolver o pedido de um run antigo.
        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
        $this->seed();
    }

    private function pedidoNaOmie(): string
    {
        $result = app(OmieGateway::class)->createOrder([
            'codigo_pedido_integracao' => 'topicos-1',
            'cabecalho' => [
                'numero_pedido' => 'PV-4242',
                'codigo_cliente' => 'CLI-00099',
                'data_previsao' => now()->addDays(20)->toDateString(),
            ],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME Tecnologia LTDA'],
            'det' => [['produto' => ['codigo' => 'KIT-ONBOARD-01', 'quantidade' => 30]]],
        ]);

        return $result['omie_order_id'];
    }

    private function webhook(string $topic, string $orderId, string $messageId): void
    {
        $this->postJson('/webhooks/omie/'.self::TOKEN, [
            'messageId' => $messageId,
            'topic' => $topic,
            'event' => ['idPedido' => $orderId],
        ])->assertOk();
    }

    public function test_inclusao_cria_o_projeto(): void
    {
        $orderId = $this->pedidoNaOmie();

        $this->webhook('VendaProduto.Incluida', $orderId, 'msg-inc');

        $this->assertSame(1, Project::where('omie_order_id', $orderId)->count());
    }

    public function test_alteracao_de_pedido_sem_projeto_nao_cria_nada(): void
    {
        $orderId = $this->pedidoNaOmie();

        $this->webhook('VendaProduto.Alterada', $orderId, 'msg-alt');

        $this->assertSame(0, Project::where('omie_order_id', $orderId)->count());
    }

    /** Alteração continua servindo para o que ela é: manter o Projeto em dia. */
    public function test_alteracao_atualiza_o_projeto_que_ja_existe(): void
    {
        $orderId = $this->pedidoNaOmie();

        $this->webhook('VendaProduto.Incluida', $orderId, 'msg-inc');

        $project = Project::where('omie_order_id', $orderId)->firstOrFail();
        $project->update(['quantity' => 1]); // desatualiza de propósito

        $this->webhook('VendaProduto.Alterada', $orderId, 'msg-alt');

        $this->assertSame(30, $project->fresh()->quantity);
        $this->assertSame(1, Project::where('omie_order_id', $orderId)->count());
    }

    // ------------------------------------------------------------------
    // Exclusão/cancelamento do pedido na Omie (falha UAT: "excluí na Omie
    // e continuou na plataforma")
    // ------------------------------------------------------------------

    /** Projeto criado via webhook, com autorização e resgate no status dado. */
    private function projetoComResgate(string $statusResgate): Project
    {
        $orderId = $this->pedidoNaOmie();
        $this->webhook('VendaProduto.Incluida', $orderId, 'msg-inc-'.$statusResgate);

        $project = Project::where('omie_order_id', $orderId)->firstOrFail();
        $kit = $project->products->first();

        $endCustomer = User::factory()->create(['role' => User::ROLE_END_CUSTOMER]);

        ProjectEntitlement::create([
            'project_id' => $project->id,
            'user_id' => $endCustomer->id,
            'product_id' => $kit->id,
        ]);

        Redemption::create([
            'project_id' => $project->id,
            'user_id' => $endCustomer->id,
            'product_id' => $kit->id,
            'status' => $statusResgate,
            'recipient_name' => $endCustomer->name,
            'zip' => '80010000',
            'street' => 'Rua XV de Novembro',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'Curitiba',
            'state' => 'PR',
        ]);

        return $project;
    }

    public function test_exclusao_do_pedido_apaga_o_projeto_e_o_rastro(): void
    {
        $project = $this->projetoComResgate(Redemption::STATUS_CONFIRMED);

        $this->webhook('VendaProduto.Excluida', $project->omie_order_id, 'msg-exc');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('project_entitlements', ['project_id' => $project->id]);
        $this->assertDatabaseMissing('redemptions', ['project_id' => $project->id]);
    }

    /** Cancelamento é o mesmo fim para a esteira: o pedido morreu na Omie. */
    public function test_cancelamento_do_pedido_tambem_apaga_o_projeto(): void
    {
        $orderId = $this->pedidoNaOmie();
        $this->webhook('VendaProduto.Incluida', $orderId, 'msg-inc-cancel');

        $project = Project::where('omie_order_id', $orderId)->firstOrFail();

        $this->webhook('VendaProduto.Cancelada', $orderId, 'msg-cancel');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    /**
     * Kit já expedido = coisa física em trânsito ou entregue. Apagar o Projeto
     * tiraria da operação o rastreio e o endereço de algo que ainda pode
     * precisar de suporte — a exclusão fica a cargo da WeGift, à mão.
     */
    public function test_projeto_com_resgate_expedido_e_mantido_na_exclusao(): void
    {
        $project = $this->projetoComResgate(Redemption::STATUS_SHIPPED);

        $this->webhook('VendaProduto.Excluida', $project->omie_order_id, 'msg-exc-shipped');

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('redemptions', ['project_id' => $project->id]);
    }

    /** Exclusão de pedido que nunca virou Projeto (filtro Omie) não é erro. */
    public function test_exclusao_sem_projeto_correspondente_nao_falha(): void
    {
        $this->webhook('VendaProduto.Excluida', '999999', 'msg-exc-fantasma');

        $this->assertDatabaseHas('webhook_events', [
            'external_event_id' => 'msg-exc-fantasma',
            'status' => 'processed',
        ]);
    }
}
