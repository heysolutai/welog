<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Identificação do Projeto (inclusão UAT): o "Nº do pedido do cliente" e a
 * data da compra vêm da Omie, e nome/prazo passam a ser editáveis pela equipe
 * WeGift — sem que a próxima sincronização desfaça a edição.
 */
class ProjectDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        // Isola o estado do gateway fake (fake/omie.json), que sem isso
        // sobrevive entre execuções e devolveria pedidos de runs antigos
        // pela idempotência do codigo_pedido_integracao.
        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
        $this->seed();
    }

    /** Pedido no fake da Omie com o Nº do pedido do cliente preenchido. */
    private function pedidoNaOmie(): string
    {
        $result = app(OmieGateway::class)->createOrder([
            'codigo_pedido_integracao' => 'detalhes-1',
            'cabecalho' => [
                'numero_pedido' => 'PV-70001',
                'codigo_cliente' => 'CLI-00099',
                'data_previsao' => now()->addDays(30)->toDateString(),
            ],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME Tecnologia LTDA'],
            // Mesmo caminho do ConsultarPedido real (docs Omie):
            // informacoes_adicionais.numero_pedido_cliente
            'informacoes_adicionais' => ['numero_pedido_cliente' => 'OC-2026-0042'],
            'det' => [['produto' => ['codigo' => 'KIT-ONBOARD-01', 'quantidade' => 20]]],
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

    private function projetoViaWebhook(): Project
    {
        $orderId = $this->pedidoNaOmie();
        $this->webhook('VendaProduto.Incluida', $orderId, 'msg-detalhes-inc');

        return Project::where('omie_order_id', $orderId)->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    // ------------------------------------------------------------------
    // Campos vindos da Omie
    // ------------------------------------------------------------------

    public function test_projeto_nasce_com_numero_do_pedido_do_cliente_e_data_da_compra(): void
    {
        $project = $this->projetoViaWebhook();

        $this->assertSame('OC-2026-0042', $project->client_order_number);

        // O fake carimba `infoCadastro.dInc` como a Omie real (dd/mm/aaaa, no
        // fuso do negócio) — a data da compra é o "hoje" desse fuso.
        $hoje = now()->setTimezone(config('integrations.business_timezone'))->toDateString();
        $this->assertSame($hoje, $project->ordered_at?->toDateString());
    }

    public function test_show_expoe_numero_do_cliente_e_data_da_compra(): void
    {
        $project = $this->projetoViaWebhook();

        $this->actingAs($this->admin())
            ->get(route('projects.show', $project))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('project.client_order_number', 'OC-2026-0042')
                ->has('project.ordered_at'),
            );
    }

    // ------------------------------------------------------------------
    // Edição de nome e prazo (PATCH projects.details)
    // ------------------------------------------------------------------

    public function test_admin_altera_nome_e_prazo_do_projeto(): void
    {
        $project = $this->projetoViaWebhook();

        $this->actingAs($this->admin())
            ->patch(route('projects.details', $project), [
                'name' => 'OC-2026-0042',
                'delivery_deadline' => '2026-12-20',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('OC-2026-0042', $project->name);
        $this->assertSame('2026-12-20', $project->delivery_deadline->toDateString());
        $this->assertNotNull($project->details_edited_at);
    }

    public function test_operador_n2_nao_pode_editar_os_dados(): void
    {
        $project = $this->projetoViaWebhook();
        $n2 = User::where('email', 'consulta@welog.local')->first();

        $this->actingAs($n2)
            ->patch(route('projects.details', $project), [
                'name' => 'Tentativa N2',
                'delivery_deadline' => '2026-12-20',
            ])
            ->assertForbidden();

        $this->assertNotSame('Tentativa N2', $project->fresh()->name);
    }

    public function test_validacao_recusa_data_invalida_e_nome_curto(): void
    {
        $project = $this->projetoViaWebhook();

        $this->actingAs($this->admin())
            ->patch(route('projects.details', $project), [
                'name' => 'Nome válido',
                'delivery_deadline' => 'não é data',
            ])
            ->assertSessionHasErrors('delivery_deadline');

        $this->actingAs($this->admin())
            ->patch(route('projects.details', $project), [
                'name' => 'ab',
                'delivery_deadline' => '2026-12-20',
            ])
            ->assertSessionHasErrors('name');
    }

    /**
     * O motivo do carimbo `details_edited_at`: `VendaProduto.Alterada` reescreve
     * o Projeto a partir da Omie, e sem a proteção a primeira alteração do
     * pedido desfaria o nome e o prazo que o operador acabou de definir.
     */
    public function test_sincronizacao_nao_desfaz_nome_e_prazo_editados_a_mao(): void
    {
        $project = $this->projetoViaWebhook();

        $this->actingAs($this->admin())
            ->patch(route('projects.details', $project), [
                'name' => 'Campanha Natal ACME',
                'delivery_deadline' => '2026-12-20',
            ])
            ->assertSessionHasNoErrors();

        $this->webhook('VendaProduto.Alterada', $project->omie_order_id, 'msg-detalhes-alt');

        $project->refresh();

        // Editado à mão: fica.
        $this->assertSame('Campanha Natal ACME', $project->name);
        $this->assertSame('2026-12-20', $project->delivery_deadline->toDateString());

        // O resto continua sincronizando normalmente.
        $this->assertSame(20, $project->quantity);
        $this->assertSame('OC-2026-0042', $project->client_order_number);
    }
}
