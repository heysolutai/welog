<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Integrations\Reconhece\ReconheceFakeGateway;
use App\Models\PartnerSku;
use App\Models\Product;
use App\Models\User;
use App\Services\Integrations\PublishProductToReconhece;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O sentido de saída da integração: publicar o produto na vitrine da Reconhece.
 *
 * O que fecha o ciclo é o item publicado JÁ nascer vinculado ao produto que o
 * expede. O colaborador escolhe na vitrine deles, o resgate volta com o código
 * de lá, e o vínculo diz qual kit tirar do estoque. Publicar sem registrar
 * criaria, na origem, o mesmo bloqueio que a tela de Catálogo do Parceiro
 * existe para resolver.
 */
class PublishToReconheceTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->produto = Product::create([
            'omie_code' => 'KIT-VITRINE-01',
            'active' => true,
            'name' => 'Kit Pincéis Profissional',
            'description' => 'Conjunto com três pincéis profissionais.',
            'stock_qty' => 50,
            'stock_synced_at' => now(),
        ]);
    }

    private function publicar(?Product $produto = null, string $categoria = 'Casa e Construção', string $depto = 'Ferramentas'): PartnerSku
    {
        return app(PublishProductToReconhece::class)
            ->handle($produto ?? $this->produto, $categoria, $depto, $this->operador);
    }

    // ------------------------------------------------------------------
    // O envio
    // ------------------------------------------------------------------

    public function test_o_produto_sobe_no_formato_do_contrato_deles(): void
    {
        $this->publicar();

        $enviado = app(ReconheceFakeGateway::class)->catalogItem('KIT-VITRINE-01');

        $this->assertNotNull($enviado);
        $this->assertSame('KIT-VITRINE-01', $enviado['sku']);
        $this->assertTrue($enviado['enabled']);

        // Os obrigatórios deles moram ANINHADOS em `product`, e é esse tipo de
        // detalhe que quebra em silêncio se o teste só conferir a chamada.
        $this->assertSame('KIT-VITRINE-01', $enviado['product']['parentCode']);
        $this->assertSame('Kit Pincéis Profissional', $enviado['product']['name']);
        $this->assertSame('Casa e Construção', $enviado['product']['productType']);
        $this->assertSame('Ferramentas', $enviado['product']['department']);
    }

    /**
     * Produto avulso: `parentCode` igual ao `sku`.
     *
     * É como o contrato deles descreve item sem variação, e é o que o catálogo
     * da Omie é hoje — a cor vive dentro do nome, não em campo próprio.
     */
    public function test_o_item_sobe_como_avulso(): void
    {
        $this->assertTrue($this->publicar()->isAvulso());
    }

    /** Produto inativo sobe desativado, em vez de sumir da vitrine sem aviso. */
    public function test_produto_inativo_sobe_desativado(): void
    {
        $this->produto->update(['active' => false]);

        $this->publicar();

        $this->assertFalse(app(ReconheceFakeGateway::class)->catalogItem('KIT-VITRINE-01')['enabled']);
    }

    // ------------------------------------------------------------------
    // O vínculo, que é o motivo de publicar por aqui e não na mão
    // ------------------------------------------------------------------

    public function test_o_item_publicado_ja_nasce_vinculado(): void
    {
        $sku = $this->publicar();

        $this->assertSame($this->produto->id, $sku->product_id);
        $this->assertNotNull($sku->linked_at);
        $this->assertSame($this->operador->id, $sku->linked_by);
    }

    /** Republicar atualiza o mesmo registro; não cria um segundo. */
    public function test_republicar_atualiza_o_mesmo_item(): void
    {
        $this->publicar();
        $this->publicar(categoria: 'Ferramentas e Jardim', depto: 'Pintura');

        $this->assertSame(1, PartnerSku::count());
        $this->assertSame('Pintura', PartnerSku::firstOrFail()->department);
        $this->assertSame(1, app(ReconheceFakeGateway::class)->catalogCount());
    }

    /**
     * Vínculo manual anterior é preservado: quem amarrou este SKU a outro
     * produto fez isso por um motivo que a publicação não conhece.
     */
    public function test_republicar_nao_desfaz_vinculo_manual(): void
    {
        $sku = $this->publicar();

        $outro = Product::create([
            'omie_code' => 'KIT-OUTRO', 'name' => 'Outro kit',
            'description' => 'x', 'stock_qty' => 1, 'stock_synced_at' => now(),
        ]);
        $sku->update(['product_id' => $outro->id]);

        $this->publicar();

        $this->assertSame($outro->id, $sku->fresh()->product_id);
    }

    // ------------------------------------------------------------------
    // O que a Reconhece exige e a Omie não garante
    // ------------------------------------------------------------------

    /**
     * Descrição é obrigatória lá e nula aqui, e produto da Omie sem descrição
     * é rotina. A conferência antes da chamada existe para o operador ler o
     * que falta, em vez de um erro da API do parceiro.
     */
    public function test_produto_sem_descricao_nao_sobe(): void
    {
        $this->produto->update(['description' => null]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('descrição');

        $this->publicar();
    }

    public function test_categoria_em_branco_nao_sobe(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('categoria');

        $this->publicar(categoria: '   ');
    }

    /** Nada foi enviado quando a conferência barrou: o catálogo deles fica intacto. */
    public function test_conferencia_barra_antes_de_chamar_o_parceiro(): void
    {
        $this->produto->update(['description' => null]);

        try {
            $this->publicar();
        } catch (DomainException) {
            // esperado
        }

        $this->assertSame(0, app(ReconheceFakeGateway::class)->catalogCount());
        $this->assertSame(0, PartnerSku::count());
    }

    // ------------------------------------------------------------------
    // A tela
    // ------------------------------------------------------------------

    public function test_a_tela_publica_e_avisa(): void
    {
        $this->actingAs($this->operador)
            ->post(route('products.reconhece.publish', $this->produto), [
                'product_type' => 'Casa e Construção',
                'department' => 'Ferramentas',
            ])
            ->assertSessionHas('status');

        $this->assertSame(1, PartnerSku::count());
    }

    public function test_a_tela_exige_as_duas_categorias(): void
    {
        $this->actingAs($this->operador)
            ->post(route('products.reconhece.publish', $this->produto), ['product_type' => 'Casa'])
            ->assertSessionHasErrors('department');

        $this->assertSame(0, PartnerSku::count());
    }

    /** Publicar mexe no catálogo de um parceiro: não é ação de perfil de consulta. */
    public function test_perfil_sem_permissao_nao_publica(): void
    {
        $consulta = User::factory()->create(['role' => User::ROLE_WEGIFT_N2]);

        $this->actingAs($consulta)
            ->post(route('products.reconhece.publish', $this->produto), [
                'product_type' => 'Casa', 'department' => 'Ferramentas',
            ])
            ->assertForbidden();
    }

    /** A lista mostra o que já está na vitrine, para não republicar no escuro. */
    public function test_a_lista_mostra_o_que_ja_esta_na_vitrine(): void
    {
        $this->publicar();

        /*
         * Sem o filtro de busca: ela usa `ilike`, que o Postgres entende e o
         * SQLite da suíte não. Procurar o item na lista inteira também não
         * depende da ordenação, que é por nome e muda com o catálogo semeado.
         */
        $this->actingAs($this->operador)
            ->get(route('products.index'))
            ->assertInertia(fn ($p) => $p->where('products.data', function ($linhas) {
                $item = collect($linhas)->firstWhere('omie_code', 'KIT-VITRINE-01');

                return $item !== null
                    && $item['reconhece'] !== null
                    && $item['categorias']['department'] === 'Ferramentas'
                    && $item['has_description'] === true;
            }));
    }
}
