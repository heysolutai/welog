<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PartnerSku;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Cor e tamanho pelo parent code.
 *
 * O cliente pede 15 camisetas pretas: o pedido da Omie traz só o produto PAI,
 * e o saldo é dele. Cada colaborador escolhe o tamanho que veste entre os SKUs
 * do catálogo do parceiro com aquele parent code; a produção acontece depois,
 * pela soma das escolhas. Nenhum tamanho tem estoque próprio.
 */
class ParentCodeVariantsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDERECO = [
        'recipient_name' => 'Ana Colaboradora',
        'cpf' => '529.982.247-25',
        'zip' => '80010-000',
        'street' => 'Rua XV de Novembro',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'Curitiba',
        'state' => 'PR',
    ];

    private Project $projeto;

    private Product $camiseta;

    private Product $caneca;

    private PartnerSku $p;

    private PartnerSku $m;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha Camiseta',
            'quantity' => 15,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);

        $this->camiseta = Product::create(['omie_code' => 'CAM-PRETA', 'name' => 'Camiseta Preta', 'stock_qty' => 15, 'stock_synced_at' => now()]);
        $this->caneca = Product::create(['omie_code' => 'CANECA', 'name' => 'Caneca', 'stock_qty' => 15, 'stock_synced_at' => now()]);
        $this->projeto->products()->attach($this->camiseta->id, ['quantity' => 15]);
        $this->projeto->products()->attach($this->caneca->id, ['quantity' => 15]);

        $sku = fn (string $codigo, ?string $tamanho, string $pai = 'CAM-PRETA', bool $ativo = true) => PartnerSku::create([
            'source' => PartnerSku::SOURCE_RECONHECE,
            'sku' => $codigo,
            'enabled' => $ativo,
            'parent_code' => $pai,
            'name' => 'Camiseta Preta',
            'color' => $tamanho ? 'Preto' : null,
            'size' => $tamanho,
        ]);

        // Fora de ordem de propósito: a vitrine ordena como loja.
        $this->m = $sku('CAM-PRETA-M', 'M');
        $this->p = $sku('CAM-PRETA-P', 'P');
        $sku('CAM-PRETA-G', 'G', ativo: false);   // desativado no parceiro: não aparece
        $sku('CAM-PRETA', null);                  // o próprio pai (avulso): não é opção
        $sku('MOL-AZUL-P', 'P', 'MOLETOM');       // de outro pai
    }

    private function colaborador(string $email, Product $produto): User
    {
        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('projects.entitlements.store', $this->projeto), [
                'name' => 'Pessoa', 'email' => $email, 'product_ids' => [$produto->id], 'mode' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $u = User::where('email', $email)->firstOrFail();
        $u->forceFill(['password_set_at' => now()])->save();

        return $u;
    }

    private function resgatar(User $u, ?PartnerSku $opcao)
    {
        $e = ProjectEntitlement::where('user_id', $u->id)->firstOrFail();

        return $this->actingAs($u)->post(route('portal.redeem', $e), self::ENDERECO + ($opcao ? ['variant_sku_id' => $opcao->id] : []));
    }

    public function test_vitrine_mostra_as_opcoes_do_parent_code_em_ordem_de_loja(): void
    {
        $ana = $this->colaborador('ana@acme.com.br', $this->camiseta);

        $this->actingAs($ana)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('gifts.0.product.name', 'Camiseta Preta')
                ->has('gifts.0.variants', 2)
                ->where('gifts.0.variants.0.size', 'P')
                ->where('gifts.0.variants.1.size', 'M')
                ->where('gifts.0.variants.0.color_hex', '#111111'));
    }

    /** O tamanho vai no resgate; o saldo que cai é o das 15 camisetas. */
    public function test_resgate_grava_o_tamanho_e_debita_o_produto_pai(): void
    {
        $ana = $this->colaborador('ana@acme.com.br', $this->camiseta);

        $this->resgatar($ana, $this->m)->assertSessionHasNoErrors()->assertRedirect(route('portal.redemptions'));

        $r = Redemption::firstOrFail();
        $this->assertSame($this->camiseta->id, $r->product_id);
        $this->assertSame(['CAM-PRETA-M', 'Preto', 'M'], [$r->variant_sku, $r->variant_color, $r->variant_size]);
        $this->assertSame(14.0, (float) DB::table('project_products')
            ->where('project_id', $this->projeto->id)->where('product_id', $this->camiseta->id)->value('stock_qty'));
    }

    public function test_sem_escolher_o_tamanho_e_recusado(): void
    {
        $ana = $this->colaborador('ana@acme.com.br', $this->camiseta);

        $this->resgatar($ana, null)->assertSessionHasErrors(['redeem' => 'Escolha a cor e o tamanho antes de confirmar.']);

        $this->assertSame(0, Redemption::count());
    }

    /** Um id trocado no formulário não grava a opção de outro produto. */
    public function test_opcao_de_outro_parent_code_e_recusada(): void
    {
        $ana = $this->colaborador('ana@acme.com.br', $this->camiseta);
        $doMoletom = PartnerSku::where('sku', 'MOL-AZUL-P')->firstOrFail();

        $this->resgatar($ana, $doMoletom)->assertSessionHasErrors('redeem');

        $this->assertSame(0, Redemption::count());
    }

    public function test_produto_sem_opcoes_resgata_como_sempre(): void
    {
        $bia = $this->colaborador('bia@acme.com.br', $this->caneca);

        $this->resgatar($bia, null)->assertSessionHasNoErrors();

        $r = Redemption::firstOrFail();
        $this->assertSame($this->caneca->id, $r->product_id);
        $this->assertNull($r->variant_size);
    }

    /** A produção lê aqui quantas de cada tamanho fazer. */
    public function test_projeto_mostra_a_soma_das_escolhas(): void
    {
        $this->resgatar($this->colaborador('a@acme.com.br', $this->camiseta), $this->p);
        $this->resgatar($this->colaborador('b@acme.com.br', $this->camiseta), $this->p);
        $this->resgatar($this->colaborador('c@acme.com.br', $this->camiseta), $this->m);

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn (Assert $page) => $page->where('project.products', fn ($itens) => collect($itens)
                ->firstWhere('omie_code', 'CAM-PRETA')['variant_choices'] == [
                    ['label' => 'Preto · P', 'total' => 2],
                    ['label' => 'Preto · M', 'total' => 1],
                ]));
    }

    /** Cancelado não vai ser produzido: sai da soma. */
    public function test_cancelado_sai_da_soma(): void
    {
        $this->resgatar($this->colaborador('a@acme.com.br', $this->camiseta), $this->p);
        Redemption::firstOrFail()->update(['status' => Redemption::STATUS_CANCELED]);

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn (Assert $page) => $page->where('project.products', fn ($itens) => collect($itens)
                ->firstWhere('omie_code', 'CAM-PRETA')['variant_choices'] == []));
    }

    public function test_relatorio_de_resgates_traz_cor_e_tamanho(): void
    {
        $this->resgatar($this->colaborador('a@acme.com.br', $this->camiseta), $this->m);

        $csv = $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('reports.redemptions'))
            ->streamedContent();

        $this->assertStringContainsString('Cor/Tamanho', $csv);
        $this->assertStringContainsString('Preto · M', $csv);
    }

    public function test_ficha_do_produto_mostra_as_opcoes(): void
    {
        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('products.edit', $this->camiseta))
            ->assertInertia(fn (Assert $page) => $page->has('product.opcoes', 2));
    }
}
