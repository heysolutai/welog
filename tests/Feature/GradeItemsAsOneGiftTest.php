<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pedido com os TAMANHOS como itens (Colete P, M, G… da grade PRD11363).
 *
 * Para quem cadastra e para quem resgata é um presente só: marcar o Colete
 * autoriza UM colete, e a pessoa escolhe o tamanho. Sem isso, "recebe todos"
 * dava um colete de cada tamanho.
 */
class GradeItemsAsOneGiftTest extends TestCase
{
    use RefreshDatabase;

    private Project $projeto;

    /** @var array<string, Product> por tamanho */
    private array $coletes = [];

    private Product $bone;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);
        $this->projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha 85 anos',
            'quantity' => 30,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);

        Product::create(['omie_code' => 'PRD11363', 'name' => 'Colete', 'stock_qty' => 0]);

        foreach (['_1' => 'G', '_2' => 'M', '_3' => 'P'] as $sufixo => $tamanho) {
            $this->coletes[$tamanho] = Product::create([
                'omie_code' => 'PRD11363'.$sufixo, 'name' => 'Colete '.$tamanho,
                'stock_qty' => 10, 'stock_synced_at' => now(),
            ]);
            $this->projeto->products()->attach($this->coletes[$tamanho]->id, ['quantity' => 10, 'stock_qty' => 10, 'stock_loaded_at' => now()]);
        }

        $this->bone = Product::create(['omie_code' => 'BONE', 'name' => 'Boné', 'stock_qty' => 10, 'stock_synced_at' => now()]);
        $this->projeto->products()->attach($this->bone->id, ['quantity' => 10, 'stock_qty' => 10, 'stock_loaded_at' => now()]);
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function cadastrar(array $produtos, string $modo = 'all'): User
    {
        $this->actingAs($this->operador())
            ->post(route('projects.entitlements.store', $this->projeto), [
                'name' => 'Diogo', 'email' => 'diogo@sotreq.com.br',
                'product_ids' => array_map(fn (Product $p) => $p->id, $produtos),
                'mode' => $modo,
            ])
            ->assertSessionHasNoErrors();

        $pessoa = User::where('email', 'diogo@sotreq.com.br')->firstOrFail();
        $pessoa->forceFill(['password_set_at' => now()])->save();

        return $pessoa;
    }

    /** "Recebe todos" com Colete e Boné: o Boné garantido, um Colete à escolha. */
    public function test_tamanhos_viram_um_grupo_de_escolha_mesmo_em_recebe_todos(): void
    {
        $this->cadastrar([...array_values($this->coletes), $this->bone]);

        $coletes = ProjectEntitlement::whereIn('product_id', collect($this->coletes)->pluck('id'))->get();
        $this->assertCount(3, $coletes);
        $this->assertCount(1, $coletes->pluck('choice_group')->unique());
        $this->assertNotNull($coletes->first()->choice_group);

        $this->assertNull(ProjectEntitlement::where('product_id', $this->bone->id)->value('choice_group'));
    }

    public function test_tela_do_projeto_sabe_que_e_tamanho_do_colete(): void
    {
        $this->actingAs($this->operador())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(function (Assert $p) {
                $produtos = collect($p->toArray()['props']['project']['products'])->keyBy('omie_code');

                $this->assertSame('PRD11363', $produtos['PRD11363_2']['grade']['pai_code']);
                $this->assertSame('Colete', $produtos['PRD11363_2']['grade']['pai_nome']);
                $this->assertSame('M', $produtos['PRD11363_2']['grade']['rotulo']);
                $this->assertNull($produtos['BONE']['grade']);
            });
    }

    /** No portal, cada tamanho diz de quem é: a vitrine monta um presente só. */
    public function test_portal_recebe_o_pai_e_o_tamanho_de_cada_opcao(): void
    {
        $diogo = $this->cadastrar(array_values($this->coletes));

        $this->actingAs($diogo)
            ->get(route('portal.catalog'))
            ->assertInertia(function (Assert $p) {
                $presentes = collect($p->toArray()['props']['gifts']);

                $this->assertCount(3, $presentes);
                $this->assertSame(['PRD11363'], $presentes->pluck('grade.pai_code')->unique()->values()->all());
                $this->assertEqualsCanonicalizing(['G', 'M', 'P'], $presentes->pluck('grade.rotulo')->all());
                $this->assertCount(1, $presentes->pluck('choice_group')->unique());
            });
    }

    /**
     * A Omie pode ter só os tamanhos, sem o produto pai: os irmãos continuam
     * sendo uma grade, com o nome que têm em comum.
     */
    public function test_sem_o_pai_cadastrado_os_tamanhos_ainda_sao_um_item(): void
    {
        Product::where('omie_code', 'PRD11363')->delete();

        $this->actingAs($this->operador())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(function (Assert $p) {
                $produtos = collect($p->toArray()['props']['project']['products'])->keyBy('omie_code');

                $this->assertSame('PRD11363', $produtos['PRD11363_1']['grade']['pai_code']);
                $this->assertSame('Colete', $produtos['PRD11363_1']['grade']['pai_nome']);
                $this->assertSame('G', $produtos['PRD11363_1']['grade']['rotulo']);
            });

        $this->cadastrar(array_values($this->coletes));

        $grupos = ProjectEntitlement::whereIn('product_id', collect($this->coletes)->pluck('id'))->pluck('choice_group');
        $this->assertCount(1, $grupos->unique());
        $this->assertNotNull($grupos->first());
    }

    /** Na lista de Produtos, a grade sem pai vira uma linha de grupo com os tamanhos dentro. */
    public function test_lista_de_produtos_agrupa_a_grade_sem_pai(): void
    {
        Product::where('omie_code', 'PRD11363')->delete();

        $this->actingAs($this->operador())
            ->get(route('products.index'))
            ->assertInertia(function (Assert $p) {
                $linhas = collect($p->toArray()['props']['products']['data']);

                $this->assertFalse($linhas->contains(fn ($l) => str_starts_with($l['omie_code'], 'PRD11363_')));

                $colete = $linhas->firstWhere('omie_code', 'PRD11363');
                $this->assertSame('Colete', $colete['name']);
                $this->assertTrue($colete['grupo_sem_pai']);
                $this->assertSame(['P', 'M', 'G'], array_column($colete['variacoes'], 'size'));
            });
    }

    /** Escolher o M resgata o M: é o estoque dele que baixa, e os outros travam. */
    public function test_resgatar_um_tamanho_baixa_o_estoque_dele(): void
    {
        $diogo = $this->cadastrar(array_values($this->coletes));
        $m = ProjectEntitlement::where('product_id', $this->coletes['M']->id)->firstOrFail();

        $this->actingAs($diogo)->post(route('portal.redeem', $m), [
            'recipient_name' => 'Diogo', 'cpf' => '529.982.247-25',
            'zip' => '80010-000', 'street' => 'Rua XV', 'number' => '100',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->coletes['M']->id, Redemption::firstOrFail()->product_id);

        $this->actingAs($diogo)
            ->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p->where('gifts', fn ($gifts) => collect($gifts)
                ->where('redeemed', false)
                ->every(fn ($g) => $g['choice_locked'] === true)));
    }
}
