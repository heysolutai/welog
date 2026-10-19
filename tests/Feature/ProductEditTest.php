<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Edição do produto, com a Omie como fonte da verdade.
 *
 * O espelho da Omie reescreve o catálogo a cada importação. O que estes testes
 * guardam é que a tela de edição não prometa uma mudança que some sozinha: a
 * descrição, único campo editável, passa a ser respeitada pelo espelho; o
 * resto continua vindo de lá.
 */
class ProductEditTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->seed();

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->produto = Product::create([
            'omie_code' => 'KIT-EDITAR-01',
            'name' => 'Caixa de som bluetooth',
            'description' => null,
            'active' => true,
            'stock_qty' => 543,
            'stock_synced_at' => now(),
        ]);
    }

    public function test_a_tela_de_edicao_abre(): void
    {
        $this->actingAs($this->operador)
            ->get(route('products.edit', $this->produto))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Products/Edit')
                ->where('product.name', 'Caixa de som bluetooth')
                ->where('product.em_uso', null)
                ->where('canEdit', true));
    }

    public function test_salvar_a_descricao(): void
    {
        $this->actingAs($this->operador)
            ->put(route('products.update', $this->produto), ['description' => 'Caixa de som portátil.'])
            ->assertSessionHasNoErrors();

        $this->produto->refresh();
        $this->assertSame('Caixa de som portátil.', $this->produto->description);
        $this->assertNotNull($this->produto->description_edited_at);
    }

    /**
     * O motivo da coluna nova: descrição escrita aqui não pode sumir no próximo
     * "Importar da Omie". O sintoma só apareceria na próxima publicação
     * recusada pela Reconhece.
     */
    public function test_a_importacao_da_omie_nao_apaga_a_descricao_editada(): void
    {
        $this->produto->update(['description' => 'Escrita na WeLog.', 'description_edited_at' => now()]);

        $this->espelharDaOmie(['description' => 'Veio da Omie.', 'name' => 'Nome novo da Omie']);

        $this->produto->refresh();
        $this->assertSame('Escrita na WeLog.', $this->produto->description);

        // O resto continua vindo da Omie, que é a fonte da verdade.
        $this->assertSame('Nome novo da Omie', $this->produto->name);
    }

    /** Sem edição local, a Omie continua mandando na descrição. */
    public function test_sem_edicao_a_omie_continua_mandando(): void
    {
        $this->espelharDaOmie(['description' => 'Veio da Omie.']);

        $this->assertSame('Veio da Omie.', $this->produto->fresh()->description);
    }

    /** Apagar o texto devolve a descrição à Omie, sem precisar de outro botão. */
    public function test_apagar_a_descricao_devolve_o_campo_a_omie(): void
    {
        $this->produto->update(['description' => 'Escrita na WeLog.', 'description_edited_at' => now()]);

        $this->actingAs($this->operador)
            ->put(route('products.update', $this->produto), ['description' => '']);

        $this->assertNull($this->produto->fresh()->description_edited_at);

        $this->espelharDaOmie(['description' => 'Veio da Omie.']);
        $this->assertSame('Veio da Omie.', $this->produto->fresh()->description);
    }

    /**
     * Salvar a categoria SEM enviar à Reconhece.
     *
     * Antes ela só ficava gravada no item já publicado, e a tela abria vazia a
     * cada visita para quem ainda não tinha enviado.
     */
    public function test_salvar_categorias_sem_enviar(): void
    {
        $this->actingAs($this->operador)
            ->put(route('products.reconhece.categories', $this->produto), [
                'product_type' => 'Eletrônicos',
                'department' => 'Áudio',
            ])
            ->assertSessionHasNoErrors();

        $this->produto->refresh();
        $this->assertSame('Eletrônicos', $this->produto->reconhece_product_type);
        $this->assertSame('Áudio', $this->produto->reconhece_department);

        // Nada foi enviado: o produto continua fora da vitrine.
        $this->assertNull($this->produto->partnerSku);

        // E a tela reabre com o que foi salvo.
        $this->actingAs($this->operador)
            ->get(route('products.edit', $this->produto))
            ->assertInertia(fn ($p) => $p
                ->where('product.categorias.product_type', 'Eletrônicos')
                ->where('product.categorias.department', 'Áudio')
                ->where('product.reconhece', null));
    }

    /** Envio recusado não perde o que foi digitado. */
    public function test_envio_recusado_mantem_as_categorias(): void
    {
        // Sem descrição, a publicação recusa.
        $this->actingAs($this->operador)
            ->post(route('products.reconhece.publish', $this->produto), [
                'product_type' => 'Eletrônicos',
                'department' => 'Áudio',
            ]);

        $this->assertSame('Eletrônicos', $this->produto->fresh()->reconhece_product_type);
    }

    /**
     * O que já foi usado aparece como sugestão: a Reconhece recusa nome que
     * não bate exatamente, e redigitar é como nasce a variação que ela não
     * aceita.
     */
    public function test_categorias_usadas_viram_sugestao(): void
    {
        Product::create([
            'omie_code' => 'OUTRO-01', 'name' => 'Fone', 'active' => true,
            'reconhece_product_type' => 'Eletrônicos', 'reconhece_department' => 'Áudio',
        ]);

        $this->actingAs($this->operador)
            ->get(route('products.edit', $this->produto))
            ->assertInertia(fn ($p) => $p
                ->where('sugestoes.product_types', ['Eletrônicos'])
                ->where('sugestoes.departments', ['Áudio']));
    }

    public function test_enviar_e_remover_foto(): void
    {
        $this->actingAs($this->operador)
            ->post(route('products.photos.store'), [
                'product_id' => $this->produto->id,
                'photos' => [UploadedFile::fake()->createWithContent('caixa.png', $this->png(400, 400))],
            ])
            ->assertSessionHasNoErrors();

        $foto = $this->produto->photos()->firstOrFail();

        $this->actingAs($this->operador)
            ->get(route('products.edit', $this->produto))
            ->assertInertia(fn ($p) => $p->where('product.galeria.fotos.0.id', $foto->id));

        $this->actingAs($this->operador)
            ->delete(route('products.photos.destroy', $foto))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->produto->photos()->count());
    }

    // ------------------------------------------------------------------
    // Exclusão
    // ------------------------------------------------------------------

    public function test_produto_sem_uso_e_excluido(): void
    {
        $this->actingAs($this->operador)
            ->delete(route('products.destroy', $this->produto))
            ->assertRedirect(route('products.index'));

        $this->assertNull(Product::find($this->produto->id));
    }

    /**
     * Produto em Projeto não sai: apagá-lo levaria junto o histórico de quem
     * recebeu o quê. A recusa vem com o motivo, em vez de um erro de banco.
     */
    public function test_produto_em_projeto_nao_e_excluido(): void
    {
        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $projeto->products()->attach($this->produto->id, ['quantity' => 10]);

        $this->actingAs($this->operador)
            ->delete(route('products.destroy', $this->produto))
            ->assertSessionHasErrors(['product' => 'Não dá para excluir Caixa de som bluetooth: ele está em 1 Projeto.']);

        $this->assertNotNull(Product::find($this->produto->id));
    }

    /** Excluir e editar mexem no catálogo: não é ação de perfil de consulta. */
    public function test_perfil_de_consulta_nao_edita_nem_exclui(): void
    {
        $consulta = User::factory()->create(['role' => User::ROLE_WEGIFT_N2]);

        $this->actingAs($consulta)
            ->put(route('products.update', $this->produto), ['description' => 'x'])
            ->assertForbidden();

        $this->actingAs($consulta)
            ->delete(route('products.destroy', $this->produto))
            ->assertForbidden();
    }

    /**
     * PNG montado byte a byte, sem GD: o container de desenvolvimento e o runner
     * do CI não têm a extensão com JPEG. Mesmo recurso do KitPhotoTest.
     */
    private function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data))
            .$type.$data
            .pack('N', crc32($type.$data));

        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $bitmap = str_repeat("\x00".str_repeat("\xff", $width * 3), $height);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', $ihdr)
            .$chunk('IDAT', gzcompress($bitmap))
            .$chunk('IEND', '');
    }

    /**
     * Espelha o produto como se a Omie respondesse com `$dados`.
     *
     * Pelo caminho público do espelho, com um gateway que devolve só este
     * produto: o catálogo do fake é fixo e não conhece KIT-EDITAR-01.
     */
    private function espelharDaOmie(array $dados): void
    {
        // `$dados` à esquerda: no `+` do PHP vence a chave de quem vem primeiro.
        $remoto = $dados + ['omie_code' => 'KIT-EDITAR-01', 'name' => 'Caixa de som bluetooth'];

        $omie = $this->createMock(OmieGateway::class);
        $omie->method('findProductByCode')->willReturn($remoto);

        (new MirrorOmieProduct(app(OmieAccounts::class), $omie))->handle('KIT-EDITAR-01');
    }
}
