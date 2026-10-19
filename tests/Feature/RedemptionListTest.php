<?php

namespace Tests\Feature;

use App\Mail\ShipmentUpdateMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A lista de Resgates como ferramenta de operação, não só de consulta.
 *
 * A transportadora entrega em lote e avisa em lote: uma campanha inteira chega
 * ao mesmo destino no mesmo dia. Marcar um a um eram quinze confirmações e
 * quinze recarregamentos da tela — e, para achar a campanha no meio de tudo,
 * a busca por texto não separava dois Projetos do mesmo cliente.
 */
class RedemptionListTest extends TestCase
{
    use RefreshDatabase;

    private Project $projetoA;

    private Project $projetoB;

    private Product $kit;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->kit = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit A']);

        $this->projetoA = $this->projeto($empresa, 'Campanha A');
        $this->projetoB = $this->projeto($empresa, 'Campanha B');

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function projeto(Company $empresa, string $nome): Project
    {
        $projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => $nome,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);
        $projeto->products()->attach($this->kit->id, ['quantity' => 50, 'stock_qty' => 50]);

        return $projeto;
    }

    private function resgate(Project $projeto, string $status, string $nome): Redemption
    {
        $pessoa = User::factory()->create(['role' => User::ROLE_END_CUSTOMER]);

        return Redemption::create([
            'project_id' => $projeto->id,
            'user_id' => $pessoa->id,
            'product_id' => $this->kit->id,
            'status' => $status,
            'recipient_name' => $nome,
            'cpf' => '52998224725',
            'zip' => '80010000', 'street' => 'Rua XV', 'number' => '10',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
            'carrier' => 'mrb',
            'tracking_code' => 'MRB1',
            'shipped_at' => $status === Redemption::STATUS_CONFIRMED ? null : now(),
        ]);
    }

    public function test_entrega_em_massa_marca_os_expedidos_e_avisa_cada_um(): void
    {
        $um = $this->resgate($this->projetoA, Redemption::STATUS_SHIPPED, 'Ana');
        $dois = $this->resgate($this->projetoA, Redemption::STATUS_SHIPPED, 'Bruno');

        $this->actingAs($this->operador)
            ->post(route('redemptions.delivered.batch'), ['ids' => [$um->id, $dois->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 resgates'));

        foreach ([$um, $dois] as $resgate) {
            $resgate->refresh();
            $this->assertSame(Redemption::STATUS_DELIVERED, $resgate->status);
            $this->assertNotNull($resgate->delivered_at);
        }

        Mail::assertQueued(ShipmentUpdateMail::class, 2);
    }

    /**
     * Linha que não está em transporte é ignorada em silêncio: a seleção pode
     * ter pegado uma já entregue de arrasto, e derrubar o lote inteiro por
     * causa dela seria pior do que deixá-la de fora.
     */
    public function test_quem_nao_esta_em_transporte_fica_de_fora_sem_derrubar_o_lote(): void
    {
        $emTransporte = $this->resgate($this->projetoA, Redemption::STATUS_SHIPPED, 'Ana');
        $aguardando = $this->resgate($this->projetoA, Redemption::STATUS_CONFIRMED, 'Bruno');

        $this->actingAs($this->operador)
            ->post(route('redemptions.delivered.batch'), ['ids' => [$emTransporte->id, $aguardando->id]])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 resgate'));

        $this->assertSame(Redemption::STATUS_DELIVERED, $emTransporte->fresh()->status);
        $this->assertSame(Redemption::STATUS_CONFIRMED, $aguardando->fresh()->status);
        Mail::assertQueued(ShipmentUpdateMail::class, 1);
    }

    public function test_lote_sem_nenhum_expedido_devolve_mensagem(): void
    {
        $aguardando = $this->resgate($this->projetoA, Redemption::STATUS_CONFIRMED, 'Ana');

        $this->actingAs($this->operador)
            ->post(route('redemptions.delivered.batch'), ['ids' => [$aguardando->id]])
            ->assertSessionHasErrors('deliver');

        Mail::assertNothingQueued();
    }

    public function test_admin_cliente_nao_marca_entregas_em_massa(): void
    {
        $resgate = $this->resgate($this->projetoA, Redemption::STATUS_SHIPPED, 'Ana');

        $cliente = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->projetoA->company_id,
        ]);

        $this->actingAs($cliente)
            ->post(route('redemptions.delivered.batch'), ['ids' => [$resgate->id]])
            ->assertForbidden();

        $this->assertSame(Redemption::STATUS_SHIPPED, $resgate->fresh()->status);
    }

    /** O recorte por Projeto separa duas campanhas do MESMO cliente. */
    public function test_filtro_por_projeto_separa_campanhas_da_mesma_empresa(): void
    {
        $this->resgate($this->projetoA, Redemption::STATUS_SHIPPED, 'Ana');
        $this->resgate($this->projetoB, Redemption::STATUS_SHIPPED, 'Bruno');

        $this->actingAs($this->operador)
            ->get(route('redemptions.index', ['projeto' => $this->projetoA->id]))
            ->assertInertia(fn (Assert $p) => $p
                ->where('redemptions.total', 1)
                ->where('redemptions.data.0.project_id', $this->projetoA->id)
                // A contagem das abas acompanha o recorte: "Entregue 340" com
                // seis linhas na tela é um número que não descreve nada.
                ->where('contagens.em_transporte', 1));
    }

    /** O seletor só lista Projeto que tem resgate — Design nunca aparece aqui. */
    public function test_o_filtro_lista_apenas_projetos_com_resgate(): void
    {
        $this->resgate($this->projetoA, Redemption::STATUS_SHIPPED, 'Ana');

        $this->actingAs($this->operador)
            ->get(route('redemptions.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->has('projetos', 1)
                ->where('projetos.0.nome', 'Campanha A'));
    }
}
