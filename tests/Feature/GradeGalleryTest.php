<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * As fotos moram no item principal: num pedido com os tamanhos como itens
 * (Colete P, M, G… da grade PRD11363), a galeria é a do Colete, e os
 * tamanhos não têm fotos próprias, nem as que vieram da Omie.
 */
class GradeGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Project $projeto;

    /** @var array<string, Product> */
    private array $coletes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed();
        Mail::fake();

        $empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);
        $this->projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha 85 anos',
            'quantity' => 20,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);

        // Os tamanhos trazem foto da Omie; o pai (aqui) ainda não existe.
        foreach (['_1' => 'G', '_2' => 'M'] as $sufixo => $tamanho) {
            $this->coletes[$tamanho] = Product::create([
                'omie_code' => 'PRD11363'.$sufixo, 'name' => 'Colete '.$tamanho,
                'image_url' => "https://omie.example/colete-{$tamanho}.jpg",
            ]);
            $this->projeto->products()->attach($this->coletes[$tamanho]->id, ['quantity' => 10]);
        }
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    private function png(): UploadedFile
    {
        $chunk = fn (string $t, string $d): string => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 300, 300, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\x00".str_repeat("\xff", 900), 300)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('foto.png', $png);
    }

    private function produtosDaTela(): \Illuminate\Support\Collection
    {
        $produtos = null;

        $this->actingAs($this->admin())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(function (Assert $p) use (&$produtos) {
                $produtos = collect($p->toArray()['props']['project']['products'])->keyBy('omie_code');
            });

        return $produtos;
    }

    /** Sem fotos no pai, os tamanhos ficam sem foto: a da Omie de cada um não vale. */
    public function test_tamanho_nao_usa_a_propria_foto(): void
    {
        $produtos = $this->produtosDaTela();

        $this->assertNull($produtos['PRD11363_1']['photo_url']);
        // O pai é de memória: a galeria vai pelo código.
        $this->assertNull($produtos['PRD11363_1']['galeria']['product_id']);
        $this->assertSame('PRD11363', $produtos['PRD11363_1']['galeria']['grade_code']);
    }

    /** A primeira foto do pai de memória o cadastra, e ela vale para os tamanhos. */
    public function test_foto_no_pai_de_memoria_cadastra_o_pai(): void
    {
        $this->actingAs($this->admin())
            ->post(route('products.photos.store'), ['grade_code' => 'PRD11363', 'photos' => [$this->png(), $this->png()]])
            ->assertSessionHasNoErrors();

        $pai = Product::where('omie_code', 'PRD11363')->firstOrFail();
        $this->assertSame('Colete', $pai->name);
        $this->assertSame(2, $pai->photos()->count());

        $produtos = $this->produtosDaTela();
        $this->assertSame($pai->photo_url, $produtos['PRD11363_2']['photo_url']);
        $this->assertSame($pai->id, $produtos['PRD11363_2']['galeria']['product_id']);
    }

    /** Tamanho não recebe foto, nem pelo id. */
    public function test_tamanho_recusa_foto(): void
    {
        Product::create(['omie_code' => 'PRD11363', 'name' => 'Colete']);

        $this->actingAs($this->admin())
            ->post(route('products.photos.store'), ['product_id' => $this->coletes['G']->id, 'photos' => [$this->png()]])
            ->assertSessionHasErrors(['photos' => 'Os tamanhos usam as fotos do item principal. Envie as fotos no item de cima.']);

        $this->assertSame(0, $this->coletes['G']->photos()->count());
    }

    /** "ABC_1" sozinho no catálogo é um item como outro, com galeria própria. */
    public function test_codigo_com_sublinhado_sozinho_e_item_isolado(): void
    {
        $avulso = Product::create(['omie_code' => 'CANECA_1', 'name' => 'Caneca']);

        $this->actingAs($this->admin())
            ->post(route('products.photos.store'), ['product_id' => $avulso->id, 'photos' => [$this->png()]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $avulso->photos()->count());
    }

    /** No portal, a galeria do Colete é a do pai; a foto de cada tamanho não entra. */
    public function test_portal_mostra_a_galeria_do_pai(): void
    {
        $pai = Product::create(['omie_code' => 'PRD11363', 'name' => 'Colete']);

        $this->actingAs($this->admin())
            ->post(route('products.photos.store'), ['product_id' => $pai->id, 'photos' => [$this->png(), $this->png()]]);

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('projects.entitlements.store', $this->projeto), [
                'name' => 'Diogo', 'email' => 'diogo@sotreq.com.br',
                'product_ids' => array_map(fn (Product $p) => $p->id, array_values($this->coletes)),
                'mode' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $diogo = User::where('email', 'diogo@sotreq.com.br')->firstOrFail();
        $diogo->forceFill(['password_set_at' => now()])->save();

        $fotos = $pai->fotos();

        $this->actingAs($diogo)
            ->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('gifts.0.grade.pai_fotos', $fotos)
                ->where('gifts.0.product.images', $fotos)
                ->where('gifts.0.product.image_url', $fotos[0]));
    }
}
