<?php

namespace Tests\Feature;

use App\Models\Carrier;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lista de Resgates: os filtros novos e a alteração de status em massa.
 *
 * O que estes testes guardam: cada ação em massa aplica a MESMA regra da ação
 * de uma linha (expedir exige rastreio, cancelar só antes da expedição), o que
 * não se aplica fica de fora sem derrubar o lote, e "selecionar todos" devolve
 * o filtro inteiro, não só a página.
 */
class RedemptionBulkStatusTest extends TestCase
{
    use RefreshDatabase;

    private Project $projeto;

    private Product $kit;

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '99888777000166']);
        $this->kit = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit A', 'stock_qty' => 100, 'stock_synced_at' => now()]);
        $this->projeto = Project::create([
            'company_id' => $empresa->id, 'name' => 'Campanha', 'quantity' => 50,
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $this->projeto->products()->attach($this->kit->id, ['quantity' => 50]);
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function resgate(string $status = Redemption::STATUS_CONFIRMED, ?Project $projeto = null, array $extra = []): Redemption
    {
        $this->sequencia++;
        $projeto ??= $this->projeto;

        $pessoa = User::create([
            'name' => "Pessoa {$this->sequencia}", 'email' => "p{$this->sequencia}@acme.com.br", 'password' => 'x',
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $projeto->company_id, 'active' => true,
        ]);

        return Redemption::create([
            'project_id' => $projeto->id,
            'user_id' => $pessoa->id,
            'product_id' => $this->kit->id,
            'status' => $status,
            'recipient_name' => $pessoa->name, 'zip' => '80010000', 'street' => 'Rua XV',
            'number' => '10', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ] + $extra);
    }

    /** Uma transportadora que exige código (não os Correios, onde ele é opcional). */
    private function transportadoraComCodigo(): Carrier
    {
        return Carrier::where('active', true)
            ->where('slug', '!=', Redemption::CARRIER_CORREIOS)
            ->where('tracking_field', '!=', 'link')
            ->firstOrFail();
    }

    // ── Lista ───────────────────────────────────────────────────────────────

    public function test_linha_traz_o_endereco_completo(): void
    {
        $this->resgate();

        $this->actingAs($this->operador())->get(route('redemptions.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('redemptions.data.0.endereco.linha1', 'Rua XV, 10')
                ->where('redemptions.data.0.endereco.linha2', 'Centro · Curitiba/PR')
                ->where('redemptions.data.0.endereco.cep', '80010-000')
                ->has('empresas', 1));
    }

    public function test_filtra_por_empresa_e_as_contagens_acompanham(): void
    {
        $outra = Company::create(['name' => 'Outra', 'cnpj' => '11222333000181']);
        $projetoDaOutra = Project::create(['company_id' => $outra->id, 'name' => 'Outra campanha', 'quantity' => 5]);

        $this->resgate();
        $this->resgate(Redemption::STATUS_CONFIRMED, $projetoDaOutra);

        $this->actingAs($this->operador())->get(route('redemptions.index', ['empresa' => $outra->id]))
            ->assertInertia(fn (Assert $p) => $p
                ->where('redemptions.total', 1)
                ->where('redemptions.data.0.referencia', 'Outra')
                ->where('contagens.aguardando', 1));
    }

    public function test_filtra_por_transportadora_e_por_periodo(): void
    {
        $this->resgate(Redemption::STATUS_SHIPPED, extra: ['carrier' => Redemption::CARRIER_CORREIOS]);
        $semTransportadora = $this->resgate();
        $antigo = $this->resgate();
        $antigo->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->actingAs($this->operador())->get(route('redemptions.index', ['transportadora' => Redemption::CARRIER_CORREIOS]))
            ->assertInertia(fn (Assert $p) => $p->where('redemptions.total', 1));

        $this->actingAs($this->operador())->get(route('redemptions.index', ['transportadora' => 'sem', 'periodo' => 30]))
            ->assertInertia(fn (Assert $p) => $p
                ->where('redemptions.total', 1)
                ->where('redemptions.data.0.id', $semTransportadora->id));
    }

    /** "Selecionar todos" é o filtro inteiro, não a página de 25. */
    public function test_selecao_devolve_o_filtro_inteiro(): void
    {
        foreach (range(1, 30) as $_) {
            $this->resgate();
        }
        $this->resgate(Redemption::STATUS_DELIVERED);

        $this->actingAs($this->operador())
            ->getJson(route('redemptions.selection', ['situacao' => 'aguardando']))
            ->assertOk()
            ->assertJsonCount(30, 'itens')
            ->assertJsonPath('itens.0.status', Redemption::STATUS_CONFIRMED);
    }

    public function test_selecao_e_so_de_quem_opera(): void
    {
        $consulta = User::where('email', 'consulta@welog.local')->firstOrFail();

        $this->actingAs($consulta)->getJson(route('redemptions.selection'))->assertForbidden();
    }

    // ── Em massa ────────────────────────────────────────────────────────────

    public function test_expedir_em_massa_com_o_rastreio_de_cada_um(): void
    {
        $a = $this->resgate();
        $b = $this->resgate();
        $transportadora = $this->transportadoraComCodigo();

        $this->actingAs($this->operador())
            ->post(route('redemptions.ship.batch'), [
                'carrier' => $transportadora->slug,
                'itens' => [
                    ['id' => $a->id, 'tracking_code' => 'COD-A'],
                    ['id' => $b->id, 'tracking_code' => 'COD-B'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '2 resgates expedidos.');

        $this->assertSame(Redemption::STATUS_SHIPPED, $a->fresh()->status);
        $this->assertSame('COD-B', $b->fresh()->tracking_code);
    }

    /** A linha sem rastreio fica; as outras saem — e a recusa diz quem ficou. */
    public function test_expedir_em_massa_recusa_so_a_linha_sem_rastreio(): void
    {
        $com = $this->resgate();
        $sem = $this->resgate();

        $this->actingAs($this->operador())
            ->post(route('redemptions.ship.batch'), [
                'carrier' => $this->transportadoraComCodigo()->slug,
                'itens' => [['id' => $com->id, 'tracking_code' => 'COD-1'], ['id' => $sem->id]],
            ])
            ->assertSessionHasErrors('ship_batch');

        $this->assertSame(Redemption::STATUS_SHIPPED, $com->fresh()->status);
        $this->assertSame(Redemption::STATUS_CONFIRMED, $sem->fresh()->status);
        $this->assertStringContainsString($sem->user->name, session('errors')->first('ship_batch'));
    }

    /** Cancelar em massa: só quem aguarda expedição; o resto fica como está. */
    public function test_cancelar_em_massa_ignora_quem_ja_saiu(): void
    {
        $aguardando = $this->resgate();
        $emTransporte = $this->resgate(Redemption::STATUS_SHIPPED);

        $this->actingAs($this->operador())
            ->post(route('redemptions.cancel.batch'), ['ids' => [$aguardando->id, $emTransporte->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '1 resgate cancelado.');

        $this->assertSame(Redemption::STATUS_CANCELED, $aguardando->fresh()->status);
        $this->assertSame(Redemption::STATUS_SHIPPED, $emTransporte->fresh()->status);
    }

    public function test_cancelar_em_massa_sem_nenhum_elegivel_e_erro(): void
    {
        $entregue = $this->resgate(Redemption::STATUS_DELIVERED);

        $this->actingAs($this->operador())
            ->post(route('redemptions.cancel.batch'), ['ids' => [$entregue->id]])
            ->assertSessionHasErrors('cancel');
    }

    public function test_acoes_em_massa_sao_so_de_quem_opera(): void
    {
        $consulta = User::where('email', 'consulta@welog.local')->firstOrFail();
        $r = $this->resgate();

        $this->actingAs($consulta)->post(route('redemptions.cancel.batch'), ['ids' => [$r->id]])->assertForbidden();
        $this->actingAs($consulta)
            ->post(route('redemptions.ship.batch'), ['carrier' => 'correios', 'itens' => [['id' => $r->id]]])
            ->assertForbidden();
    }
}
