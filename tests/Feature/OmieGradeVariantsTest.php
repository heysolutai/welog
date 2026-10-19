<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PartnerSku;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Support\Variacoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tamanho pela grade da própria Omie.
 *
 * A jaqueta é cadastrada na Omie como produto com variação: o pai PRD11361
 * (sem estoque próprio) e os filhos PRD11361_1 (G), _2 (M), _3 (P). O pedido
 * traz só o pai; o seletor do portal mostra os tamanhos dos filhos, sem
 * depender do catálogo da Reconhece, e o resgate grava o tamanho escolhido.
 */
class OmieGradeVariantsTest extends TestCase
{
    use RefreshDatabase;

    private const NOME = 'Jaqueta corta-vento com capuz, fechamento com zíper e forro interno';

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

    private Product $jaqueta;

    private Product $tamanhoM;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha Jaqueta',
            'quantity' => 20,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);

        $produto = fn (string $codigo, string $nome, bool $ativo = true) => Product::create([
            'omie_code' => $codigo, 'name' => $nome, 'active' => $ativo, 'stock_qty' => 20, 'stock_synced_at' => now(),
        ]);

        $this->jaqueta = $produto('PRD11361', self::NOME);
        $produto('PRD11361_1', self::NOME.' G');
        $this->tamanhoM = $produto('PRD11361_2', self::NOME.' M');
        $produto('PRD11361_3', self::NOME.' P');
        $produto('PRD11361_4', self::NOME.' GG', ativo: false); // inativo na Omie: fora
        $produto('PRD113610', 'Outro produto G');               // código parecido, não é da grade

        $this->projeto->products()->attach($this->jaqueta->id, ['quantity' => 20]);
    }

    private function colaborador(string $email): User
    {
        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('projects.entitlements.store', $this->projeto), [
                'name' => 'Pessoa', 'email' => $email, 'product_ids' => [$this->jaqueta->id], 'mode' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $u = User::where('email', $email)->firstOrFail();
        $u->forceFill(['password_set_at' => now()])->save();

        return $u;
    }

    public function test_vitrine_mostra_os_tamanhos_da_grade_em_ordem_de_loja(): void
    {
        $ana = $this->colaborador('ana@acme.com.br');

        $this->actingAs($ana)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('gifts.0.variants', 3)
                ->where('gifts.0.variants.0.size', 'P')
                ->where('gifts.0.variants.0.sku', 'PRD11361_3')
                ->where('gifts.0.variants.1.size', 'M')
                ->where('gifts.0.variants.2.size', 'G'));
    }

    /** O tamanho vai no resgate; o saldo que cai é o das 20 jaquetas do pai. */
    public function test_resgate_grava_o_tamanho_da_grade_e_debita_o_pai(): void
    {
        $ana = $this->colaborador('ana@acme.com.br');
        $e = ProjectEntitlement::where('user_id', $ana->id)->firstOrFail();

        $this->actingAs($ana)
            ->post(route('portal.redeem', $e), self::ENDERECO + ['variant_sku_id' => $this->tamanhoM->id])
            ->assertSessionHasNoErrors();

        $r = Redemption::firstOrFail();
        $this->assertSame($this->jaqueta->id, $r->product_id);
        $this->assertSame(['PRD11361_2', null, 'M'], [$r->variant_sku, $r->variant_color, $r->variant_size]);
        $this->assertSame(19.0, (float) DB::table('project_products')
            ->where('project_id', $this->projeto->id)->where('product_id', $this->jaqueta->id)->value('stock_qty'));
    }

    /** A Omie é o cadastro de quem expede: com grade, o catálogo do parceiro não entra. */
    public function test_grade_da_omie_tem_preferencia_sobre_o_catalogo_do_parceiro(): void
    {
        PartnerSku::create([
            'source' => PartnerSku::SOURCE_RECONHECE, 'sku' => 'JAQ-XG', 'enabled' => true,
            'parent_code' => 'PRD11361', 'name' => 'Jaqueta', 'size' => 'XG',
        ]);

        $tamanhos = $this->jaqueta->opcoesDeVariacao()->pluck('size')->all();

        $this->assertSame(['P', 'M', 'G'], $tamanhos);
    }

    public function test_ficha_do_produto_mostra_as_opcoes_da_grade(): void
    {
        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('products.edit', $this->jaqueta))
            ->assertInertia(fn (Assert $page) => $page->has('product.opcoes', 3));
    }

    /** Na lista de Produtos, os tamanhos são subitens da jaqueta, não linhas soltas. */
    public function test_lista_de_produtos_mostra_os_tamanhos_como_subitens_do_pai(): void
    {
        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('products.index'))
            ->assertInertia(function (Assert $page) {
                $codigos = collect($page->toArray()['props']['products']['data'])->pluck('omie_code');

                // Nenhum PRD11361_n como linha própria; o código parecido fica.
                $this->assertFalse($codigos->contains(fn ($c) => str_starts_with($c, 'PRD11361_')));
                $this->assertTrue($codigos->contains('PRD113610'));

                $jaqueta = collect($page->toArray()['props']['products']['data'])->firstWhere('omie_code', 'PRD11361');
                $this->assertSame(['P', 'M', 'G', 'GG'], array_column($jaqueta['variacoes'], 'size'));
                $this->assertSame('PRD11361_3', $jaqueta['variacoes'][0]['omie_code']);
                $this->assertFalse($jaqueta['variacoes'][3]['active']);
            });
    }

    /**
     * O tamanho no meio do nome: "Jaqueta corta vento G Unissex + Necessaire".
     * Pegar a última palavra dava "Necessaire" como tamanho de todas.
     */
    public function test_tamanho_no_meio_do_nome_do_filho(): void
    {
        $pai = new Product(['name' => 'Jaqueta corta vento unissex + Necessaire']);
        $filho = fn (string $nome) => new Product(['name' => $nome]);

        $this->assertSame([null, 'G'], $pai->variacaoDoFilho($filho('Jaqueta corta vento G Unissex + Necessaire')));
        $this->assertSame([null, 'PP'], $pai->variacaoDoFilho($filho('Jaqueta corta vento PP Unissex + Necessaire')));
        $this->assertSame([null, 'G4'], $pai->variacaoDoFilho($filho('Jaqueta corta vento G4 Unissex + Necessaire')));

        // Nome reescrito inteiro: fica o tamanho, sem inventar cor.
        $this->assertSame([null, 'M'], $pai->variacaoDoFilho(
            $filho('Jaqueta corta-vento com capuz, fechamento com zíper e forro interno M'),
        ));

        // Cor de verdade ao lado do tamanho continua sendo cor.
        $this->assertSame(['Azul', 'G'], (new Product(['name' => 'Camiseta Dry Fit']))->variacaoDoFilho($filho('Camiseta Azul G Dry Fit')));

        // Grade sem o pai cadastrado: o pai de memória é a parte comum.
        $deMemoria = new Product(['name' => 'Jaqueta corta vento']);
        $this->assertSame([null, 'G'], $deMemoria->variacaoDoFilho($filho('Jaqueta corta vento G Unissex + Necessaire')));

        // Variação só de cor segue lida do fim, com cor composta.
        $this->assertSame(['Azul Marinho', null], (new Product(['name' => 'Caneca']))->variacaoDoFilho($filho('Caneca Azul Marinho')));
    }

    /** A característica da Omie vale antes do nome, que pode dizer outra coisa. */
    public function test_caracteristica_da_omie_vale_antes_do_nome(): void
    {
        $pai = new Product(['name' => 'Jaqueta corta vento unissex + Necessaire']);
        $filho = new Product([
            'name' => 'Jaqueta corta vento G Unissex + Necessaire',
            'caracteristicas' => [
                ['nome' => 'Tamanho Corta Vento', 'conteudo' => 'pp'],
                ['nome' => 'Cor', 'conteudo' => 'Preta'],
            ],
        ]);

        $this->assertSame(['Preta', 'PP'], $pai->variacaoDoFilho($filho));

        // Característica que não é tamanho nem cor não decide nada: fica o nome.
        $outro = new Product([
            'name' => 'Jaqueta corta vento M Unissex + Necessaire',
            'caracteristicas' => [['nome' => 'Material', 'conteudo' => 'Nylon']],
        ]);

        $this->assertSame([null, 'M'], $pai->variacaoDoFilho($outro));
    }

    public function test_cor_e_tamanho_saem_do_fim_do_nome(): void
    {
        $this->assertSame([null, 'G'], Variacoes::doSufixo(' G'));
        $this->assertSame(['Preta', 'GG'], Variacoes::doSufixo('Preta GG'));
        $this->assertSame([null, 'G'], Variacoes::doSufixo('- Tamanho G'));
        $this->assertSame([null, '38'], Variacoes::doSufixo('38'));
        $this->assertSame(['Azul Marinho', null], Variacoes::doSufixo('Azul Marinho'));
    }
}
