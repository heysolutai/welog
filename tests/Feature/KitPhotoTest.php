<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Galeria de fotos do kit, enviada a partir do Projeto (requisitos 2 e 2.1).
 *
 * O que estes testes protegem: a foto pertence ao CATÁLOGO, não ao Projeto de
 * onde foi enviada, e não pode ser apagada pela próxima sincronização da Omie.
 */
class KitPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Product $kit;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed();

        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->kit = Product::create(['omie_code' => 'KIT-X', 'name' => 'Kit X', 'is_kit' => true]);
        $this->project = Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto Foto',
            'quantity' => 100,
        ]);
        $this->project->products()->attach($this->kit->id, ['quantity' => 100]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    private function enviar(array $fotos, ?Product $produto = null)
    {
        return $this->actingAs($this->admin())->post(route('products.photos.store'), [
            'product_id' => ($produto ?? $this->kit)->id,
            'photos' => $fotos,
        ]);
    }

    /**
     * PNG de verdade, montado byte a byte.
     *
     * `UploadedFile::fake()->image()` exige a extensão GD, que o runner do CI não
     * tem — e, quando tem, pode estar compilada sem JPEG (é o caso do container
     * de desenvolvimento). Aqui só entram `gzcompress` e `crc32`, que fazem parte
     * do PHP de qualquer ambiente. O `dimensions` da validação lê isto com
     * `getimagesize()`, e o `mimes`, com o finfo.
     */
    private function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data))
            .$type.$data
            .pack('N', crc32($type.$data));

        // IHDR: dimensões, 8 bits por canal, tipo de cor 2 (RGB), sem interlace.
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        // Cada linha do bitmap começa com o byte de filtro (0) e segue com 3
        // bytes por pixel. Branco chapado comprime a quase nada.
        $bitmap = str_repeat("\x00".str_repeat("\xff", $width * 3), $height);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', $ihdr)
            .$chunk('IDAT', gzcompress($bitmap))
            .$chunk('IEND', '');
    }

    private function foto(string $name = 'kit.png', int $width = 800, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->png($width, $height));
    }

    public function test_admin_envia_fotos_e_elas_valem_para_o_catalogo(): void
    {
        $this->enviar([$this->foto('a.png'), $this->foto('b.png')])->assertSessionHasNoErrors();

        $fotos = $this->kit->photos()->get();

        $this->assertCount(2, $fotos);
        $fotos->each(fn ($f) => Storage::disk('public')->assertExists($f->path));

        // 2.1: quem lê o catálogo vê a capa, não só a tela do Projeto.
        $this->actingAs($this->admin())->get(route('products.index'))
            ->assertInertia(fn (Assert $p) => $p->where('products.data.0.photo_url', $fotos[0]->url));
    }

    public function test_foto_enviada_sobrevive_a_sincronizacao_da_omie(): void
    {
        $this->enviar([$this->foto()]);
        $capa = $this->kit->photos()->first()->url;

        // O MirrorOmieProduct reescreve `image_url` a cada sync do catálogo.
        $this->kit->update(['image_url' => 'https://omie.example/outra.jpg']);

        $this->assertSame($capa, $this->kit->refresh()->photo_url);
    }

    public function test_galeria_aceita_ate_dez_fotos(): void
    {
        $this->enviar(array_map(fn ($i) => $this->foto("f{$i}.png"), range(1, 9)))->assertSessionHasNoErrors();

        $this->enviar([$this->foto('x.png'), $this->foto('y.png')])
            ->assertSessionHasErrors(['photos' => 'Cabem mais 1 foto neste item (até 10).']);

        $this->enviar([$this->foto('z.png')])->assertSessionHasNoErrors();
        $this->assertSame(10, $this->kit->photos()->count());
    }

    public function test_reordenar_troca_a_capa(): void
    {
        $this->enviar([$this->foto('a.png'), $this->foto('b.png')]);
        [$a, $b] = $this->kit->photos()->get()->all();

        $this->actingAs($this->admin())
            ->put(route('products.photos.reorder', $this->kit), ['ids' => [$b->id, $a->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame($b->url, $this->kit->refresh()->photo_url);
    }

    public function test_remover_a_ultima_foto_volta_para_a_imagem_da_omie(): void
    {
        $this->kit->update(['image_url' => 'https://omie.example/original.jpg']);
        $this->enviar([$this->foto()]);
        $foto = $this->kit->photos()->first();

        $this->actingAs($this->admin())
            ->delete(route('products.photos.destroy', $foto))
            ->assertRedirect();

        Storage::disk('public')->assertMissing($foto->path);
        $this->assertSame('https://omie.example/original.jpg', $this->kit->refresh()->photo_url);
    }

    public function test_recusa_arquivo_que_nao_e_imagem(): void
    {
        $this->enviar([UploadedFile::fake()->create('planilha.xlsx', 20)])->assertSessionHasErrors('photos.0');

        $this->assertSame(0, $this->kit->photos()->count());
    }

    /** SVG aceita script embutido; a regra `dimensions` é o que o barra. */
    public function test_recusa_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><script>alert(1)</script></svg>';

        $this->enviar([UploadedFile::fake()->createWithContent('kit.svg', $svg)])->assertSessionHasErrors('photos.0');

        $this->assertSame(0, $this->kit->photos()->count());
    }

    public function test_recusa_imagem_pequena_demais(): void
    {
        $this->enviar([$this->foto('mini.png', 100, 100)])->assertSessionHasErrors('photos.0');
    }

    public function test_n2_nao_envia_foto(): void
    {
        $n2 = User::where('role', User::ROLE_WEGIFT_N2)->firstOrFail();

        $this->actingAs($n2)
            ->post(route('products.photos.store'), ['product_id' => $this->kit->id, 'photos' => [$this->foto()]])
            ->assertForbidden();

        $this->assertSame(0, $this->kit->photos()->count());
    }

    public function test_tela_do_projeto_mostra_estoque_por_kit(): void
    {
        $this->kit->update(['stock_qty' => 40]);

        $this->actingAs($this->admin())->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $p) => $p
                // O JSON serializa 40.0 como 40: comparar com float falharia.
                ->where('project.products.0.stock.available', 40)
                ->where('project.products.0.stock.required', 100)
                ->where('project.products.0.stock.sufficient', false)
                ->etc());
    }

    public function test_lista_de_projetos_traz_o_kit(): void
    {
        $this->actingAs($this->admin())->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->where('projects.data.0.kits.0.name', 'Kit X')->etc());
    }
}
