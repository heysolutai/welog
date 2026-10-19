<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Logo da campanha, enviada em Customizações (tela do Cliente).
 *
 * O que estes testes protegem: a logo é do PROJETO e não da Empresa — dois
 * Projetos do mesmo Cliente têm marcas independentes —, e parametrizar a marca
 * é ação da WeGift, não do Admin Cliente.
 */
class ProjectLogoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed();

        // A customização nasce DESLIGADA. A maioria dos testes aqui trata do
        // upload em si, então o padrão do cenário é com a feature ligada; quem
        // testa o corte desliga explicitamente.
        $this->company = Company::create([
            'name' => 'ACME',
            'cnpj' => '12345678000190',
            'customizations' => ['project_logo' => true],
        ]);
        $this->project = Project::create([
            'company_id' => $this->company->id,
            'name' => 'Campanha 85 anos',
            'quantity' => 100,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    /**
     * PNG montado byte a byte — mesma razão do KitPhotoTest: `fake()->image()`
     * depende da GD, que não existe em todo runner.
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

    private function logo(string $name = 'marca.png', int $width = 256, int $height = 256): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->png($width, $height));
    }

    private function url(): string
    {
        return route('projects.logo.store', $this->project);
    }

    public function test_admin_wegift_envia_a_logo_e_ela_aparece_na_tela_do_projeto(): void
    {
        $this->actingAs($this->admin())
            ->post($this->url(), ['logo' => $this->logo()])
            ->assertRedirect();

        $this->project->refresh();

        $this->assertNotNull($this->project->logo_path);
        Storage::disk('public')->assertExists($this->project->logo_path);

        $this->actingAs($this->admin())->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $p) => $p->where(
                'project.logo_url',
                Storage::disk('public')->url($this->project->logo_path),
            )->etc());
    }

    /** É a tela de onde o envio parte: ela precisa saber quem já tem marca. */
    public function test_tela_do_cliente_traz_a_logo_de_cada_projeto(): void
    {
        $this->actingAs($this->admin())->post($this->url(), ['logo' => $this->logo()]);

        $this->actingAs($this->admin())->get(route('admin.companies.show', $this->company))
            ->assertInertia(fn (Assert $p) => $p->where(
                'projects.0.logo_url',
                Storage::disk('public')->url($this->project->refresh()->logo_path),
            )->etc());
    }

    /**
     * O ponto da decisão: a logo é da campanha. Um segundo Projeto do MESMO
     * Cliente não herda nem é afetado.
     */
    public function test_logo_nao_vaza_para_outro_projeto_da_mesma_empresa(): void
    {
        $outro = Project::create([
            'company_id' => $this->company->id,
            'name' => 'Campanha Natal',
            'quantity' => 50,
        ]);

        $this->actingAs($this->admin())->post($this->url(), ['logo' => $this->logo()]);

        $this->assertNotNull($this->project->refresh()->logo_path);
        $this->assertNull($outro->refresh()->logo_path);
    }

    public function test_arquivo_anterior_e_apagado_ao_trocar_a_logo(): void
    {
        $this->actingAs($this->admin())->post($this->url(), ['logo' => $this->logo('antiga.png')]);
        $antiga = $this->project->refresh()->logo_path;

        $this->actingAs($this->admin())->post($this->url(), ['logo' => $this->logo('nova.png')]);
        $nova = $this->project->refresh()->logo_path;

        $this->assertNotSame($antiga, $nova);
        Storage::disk('public')->assertMissing($antiga);
        Storage::disk('public')->assertExists($nova);
    }

    public function test_remover_a_logo_limpa_caminho_e_arquivo(): void
    {
        $this->actingAs($this->admin())->post($this->url(), ['logo' => $this->logo()]);
        $enviada = $this->project->refresh()->logo_path;

        $this->actingAs($this->admin())
            ->delete(route('projects.logo.destroy', $this->project))
            ->assertRedirect();

        $this->project->refresh();

        $this->assertNull($this->project->logo_path);
        $this->assertNull($this->project->logo_url);
        Storage::disk('public')->assertMissing($enviada);
    }

    public function test_recusa_arquivo_que_nao_e_imagem(): void
    {
        $this->actingAs($this->admin())
            ->post($this->url(), ['logo' => UploadedFile::fake()->create('contrato.pdf', 20)])
            ->assertSessionHasErrors('logo');

        $this->assertNull($this->project->refresh()->logo_path);
    }

    /** SVG aceita script embutido; a regra `dimensions` é o que o barra. */
    public function test_recusa_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><script>alert(1)</script></svg>';

        $this->actingAs($this->admin())
            ->post($this->url(), ['logo' => UploadedFile::fake()->createWithContent('marca.svg', $svg)])
            ->assertSessionHasErrors('logo');

        $this->assertNull($this->project->refresh()->logo_path);
    }

    public function test_recusa_imagem_pequena_demais(): void
    {
        $this->actingAs($this->admin())
            ->post($this->url(), ['logo' => $this->logo('mini.png', 32, 32)])
            ->assertSessionHasErrors('logo');

        $this->assertNull($this->project->refresh()->logo_path);
    }

    /** Customizar a marca é da WeGift: o Admin Cliente não parametriza a própria tela. */
    public function test_admin_cliente_nao_envia_logo(): void
    {
        $adminCliente = User::create([
            'name' => 'RH ACME',
            'email' => 'rh@acme.test',
            'password' => 'password',
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($adminCliente)
            ->post($this->url(), ['logo' => $this->logo()])
            ->assertForbidden();

        $this->assertNull($this->project->refresh()->logo_path);
    }

    public function test_n2_nao_envia_logo(): void
    {
        $n2 = User::where('role', User::ROLE_WEGIFT_N2)->firstOrFail();

        $this->actingAs($n2)
            ->post($this->url(), ['logo' => $this->logo()])
            ->assertForbidden();

        $this->assertNull($this->project->refresh()->logo_path);
    }

    /**
     * A chave governa a EXIBIÇÃO, não o arquivo.
     *
     * Desligar tem de esconder a marca e preservar o upload — senão religar a
     * feature custaria um arquivo novo, e ninguém desligaria para testar.
     */
    public function test_customizacao_desligada_esconde_a_logo_sem_apagar_o_arquivo(): void
    {
        $this->actingAs($this->admin())->post($this->url(), ['logo' => $this->logo()]);
        $enviada = $this->project->refresh()->logo_path;

        $this->company->update(['customizations' => ['project_logo' => false]]);

        $this->actingAs($this->admin())->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $p) => $p->where('project.logo_url', null)->etc());

        $this->assertSame($enviada, $this->project->refresh()->logo_path);
        Storage::disk('public')->assertExists($enviada);
    }

    /** Religar devolve a marca à tela, sem passar por upload de novo. */
    public function test_religar_a_customizacao_traz_a_logo_de_volta(): void
    {
        $this->actingAs($this->admin())->post($this->url(), ['logo' => $this->logo()]);

        $this->company->update(['customizations' => ['project_logo' => false]]);
        $this->company->update(['customizations' => ['project_logo' => true]]);

        $this->actingAs($this->admin())->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $p) => $p->where(
                'project.logo_url',
                Storage::disk('public')->url($this->project->refresh()->logo_path),
            )->etc());
    }

    public function test_projeto_sem_logo_nao_inventa_url(): void
    {
        $this->actingAs($this->admin())->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $p) => $p->where('project.logo_url', null)->etc());
    }
}
