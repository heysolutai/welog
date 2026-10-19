<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tirar itens do Projeto à mão (Admin WeGift): só sem resgate, a autorização
 * do item sai junto, a alteração do pedido na Omie não traz o item de volta,
 * e restaurar devolve o item como estava.
 */
class ProjectItemRemovalTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-remocao-itens';

    private string $pedido;

    private Project $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
        $this->seed();

        $omie = app(OmieGateway::class);
        $omie->addProduct(['omie_code' => 'COLETE', 'name' => 'Colete']);
        $omie->addProduct(['omie_code' => 'COLETE_1', 'name' => 'Colete G']);
        $omie->addProduct(['omie_code' => 'COLETE_2', 'name' => 'Colete M']);
        $omie->addProduct(['omie_code' => 'CANECA', 'name' => 'Caneca']);

        $this->pedido = $omie->createOrder([
            'codigo_pedido_integracao' => 'remocao-1',
            'cabecalho' => [
                'numero_pedido' => 'PV-REM-1',
                'codigo_cliente' => 'CLI-00085',
                'data_previsao' => now()->addDays(30)->toDateString(),
            ],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'Sotreq S.A.'],
            'det' => [
                ['produto' => ['codigo' => 'COLETE_1', 'quantidade' => 5]],
                ['produto' => ['codigo' => 'COLETE_2', 'quantidade' => 10]],
                ['produto' => ['codigo' => 'CANECA', 'quantidade' => 20]],
            ],
        ])['omie_order_id'];

        $this->webhook('VendaProduto.Incluida', 'msg-inc');
        $this->projeto = Project::where('omie_order_id', $this->pedido)->firstOrFail();
    }

    private function webhook(string $topico, string $mensagem): void
    {
        $this->postJson('/webhooks/omie/'.self::TOKEN, [
            'messageId' => $mensagem,
            'topic' => $topico,
            'event' => ['idPedido' => $this->pedido],
        ])->assertOk();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    private function produto(string $codigo): Product
    {
        return Product::where('omie_code', $codigo)->firstOrFail();
    }

    private function remover(array $codigos, ?User $quem = null)
    {
        return $this->actingAs($quem ?? $this->admin())->delete(route('projects.items.destroy', $this->projeto), [
            'ids' => array_map(fn (string $c) => $this->produto($c)->id, $codigos),
        ]);
    }

    private function codigosDoProjeto(): array
    {
        return $this->projeto->fresh()->products->pluck('omie_code')->sort()->values()->all();
    }

    public function test_remove_item_sem_resgate_e_a_autorizacao_sai_junto(): void
    {
        $pessoa = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->projeto->company_id]);
        ProjectEntitlement::create(['project_id' => $this->projeto->id, 'user_id' => $pessoa->id, 'product_id' => $this->produto('CANECA')->id]);

        $this->remover(['CANECA'])->assertSessionHasNoErrors();

        $this->assertSame(['COLETE_1', 'COLETE_2'], $this->codigosDoProjeto());
        $this->assertSame(15, $this->projeto->fresh()->quantity);
        $this->assertSame(0, ProjectEntitlement::where('project_id', $this->projeto->id)->count());
    }

    /** A Omie reenvia o pedido inteiro a cada alteração: o item removido não volta. */
    public function test_alteracao_do_pedido_nao_traz_o_item_de_volta(): void
    {
        $this->remover(['CANECA'])->assertSessionHasNoErrors();

        $this->webhook('VendaProduto.Alterada', 'msg-alt');

        $this->assertSame(['COLETE_1', 'COLETE_2'], $this->codigosDoProjeto());
        $this->assertSame(15, $this->projeto->fresh()->quantity);
    }

    public function test_com_resgate_o_item_nao_sai(): void
    {
        $pessoa = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->projeto->company_id]);
        Redemption::create([
            'project_id' => $this->projeto->id, 'user_id' => $pessoa->id, 'product_id' => $this->produto('CANECA')->id,
            'status' => 'confirmed', 'recipient_name' => $pessoa->name, 'zip' => '80010000',
            'street' => 'Rua', 'number' => '1', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);

        $this->remover(['CANECA'])->assertSessionHasErrors('remove');

        $this->assertContains('CANECA', $this->codigosDoProjeto());
    }

    /** No pai da grade, saem todos os tamanhos; restaurar devolve um a um. */
    public function test_remove_a_grade_inteira_e_restaura(): void
    {
        $this->remover(['COLETE_1', 'COLETE_2'])->assertSessionHasNoErrors();
        $this->assertSame(['CANECA'], $this->codigosDoProjeto());

        $this->actingAs($this->admin())
            ->post(route('projects.items.restore', [$this->projeto, 'colete_2']))
            ->assertSessionHasNoErrors();

        $this->assertSame(['CANECA', 'COLETE_2'], $this->codigosDoProjeto());
        $this->assertSame(10, (int) $this->projeto->fresh()->products->firstWhere('omie_code', 'COLETE_2')->pivot->quantity);
        $this->assertSame(['colete_1'], array_keys($this->projeto->fresh()->removed_items));

        // Restaurado, ele volta a seguir a Omie.
        $this->webhook('VendaProduto.Alterada', 'msg-alt-2');
        $this->assertSame(['CANECA', 'COLETE_2'], $this->codigosDoProjeto());
    }

    /** Só Admin WeGift: nem o operador N1. */
    public function test_operador_nao_remove(): void
    {
        $this->remover(['CANECA'], User::where('email', 'operador@welog.local')->firstOrFail())->assertForbidden();

        $this->assertContains('CANECA', $this->codigosDoProjeto());
    }
}
