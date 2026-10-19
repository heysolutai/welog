<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Customizações em dois níveis.
 *
 * Logo: a do Cliente (topo das telas do Admin Cliente) e a de cada Projeto,
 * cada uma ligada por um check. Campos para resgate: uma chave que, desligada,
 * faz o Cliente coletar tudo — sem perder a seleção, que volta ao religar.
 */
class ClientBrandingAndFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed();

        $this->company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->project = Project::create(['company_id' => $this->company->id, 'name' => 'Campanha', 'quantity' => 10]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    private function adminCliente(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->company->id,
            'password_set_at' => now(),
        ]);
    }

    /** PNG montado byte a byte: `fake()->image()` depende da GD. */
    private function logo(): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', 128, 128, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\x00".str_repeat("\xff", 128 * 3), 128)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('marca.png', $png);
    }

    // ── Campos para resgate ─────────────────────────────────────────────────

    public function test_chave_de_campos_desligada_coleta_todos(): void
    {
        $this->assertSame(['nome', 'email', 'funcao', 'cpf', 'telefone'], $this->company->colunasDaPlanilha());
        $this->assertTrue($this->company->pedeCpf());
    }

    /** Escolher campos liga a chave; desligar volta a "todos" sem apagar a escolha. */
    public function test_desligar_e_religar_preserva_a_selecao(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.companies.import-columns.update', $this->company), ['columns' => ['telefone']])
            ->assertSessionHasNoErrors();

        $this->company->refresh();
        $this->assertTrue($this->company->customizacao('import_fields'));
        $this->assertFalse($this->company->pedeCpf());

        $this->actingAs($this->admin())
            ->patch(route('admin.companies.customizations.update', $this->company), ['key' => 'import_fields', 'enabled' => false]);

        $this->company->refresh();
        $this->assertTrue($this->company->pedeCpf());

        // A tela mostra a seleção guardada, para religar sem refazer.
        $this->actingAs($this->admin())->get(route('admin.companies.show', $this->company))
            ->assertInertia(fn (Assert $p) => $p->where('company.import_columns', ['nome', 'email', 'telefone'])->etc());

        $this->actingAs($this->admin())
            ->patch(route('admin.companies.customizations.update', $this->company), ['key' => 'import_fields', 'enabled' => true]);

        $this->assertFalse($this->company->refresh()->pedeCpf());
    }

    // ── Logo em dois níveis ─────────────────────────────────────────────────

    /** Padrão só no Projeto: era o único nível que existia. */
    public function test_logo_nasce_so_no_projeto(): void
    {
        $this->assertSame(['projeto'], $this->company->logoNiveis());
    }

    public function test_logo_do_cliente_aparece_no_topo_do_admin_cliente(): void
    {
        $this->company->update(['customizations' => ['project_logo' => true, 'logo_niveis' => ['cliente', 'projeto']]]);

        $this->actingAs($this->admin())
            ->post(route('admin.companies.logo.store', $this->company), ['logo' => $this->logo()])
            ->assertSessionHasNoErrors();

        $this->company->refresh();
        Storage::disk('public')->assertExists($this->company->logo_path);

        $this->actingAs($this->adminCliente())->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.user.company_logo_url', $this->company->logo_url)->etc());
    }

    /** Desmarcar o nível Cliente esconde a marca do topo, sem apagar o arquivo. */
    public function test_nivel_desmarcado_esconde_sem_apagar(): void
    {
        $this->company->update(['customizations' => ['project_logo' => true, 'logo_niveis' => ['cliente']]]);
        $this->actingAs($this->admin())
            ->post(route('admin.companies.logo.store', $this->company), ['logo' => $this->logo()]);

        $this->actingAs($this->admin())
            ->put(route('admin.companies.logo-levels.update', $this->company), ['levels' => ['projeto']])
            ->assertSessionHasNoErrors();

        $this->company->refresh();
        $this->assertNotNull($this->company->logo_path);
        $this->assertNull($this->company->logoDoClienteUrl());

        $this->actingAs($this->adminCliente())->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.user.company_logo_url', null)->etc());
    }

    /** Nível Projeto desmarcado: a logo da campanha some da tela do Projeto. */
    public function test_nivel_projeto_desmarcado_esconde_a_logo_da_campanha(): void
    {
        $this->company->update(['customizations' => ['project_logo' => true, 'logo_niveis' => ['cliente']]]);
        $this->project->update(['logo_path' => 'logos/campanha.png']);

        $this->actingAs($this->admin())->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $p) => $p->where('project.logo_url', null)->etc());
    }

    public function test_remove_a_logo_do_cliente(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.companies.logo.store', $this->company), ['logo' => $this->logo()]);
        $caminho = $this->company->refresh()->logo_path;

        $this->actingAs($this->admin())
            ->delete(route('admin.companies.logo.destroy', $this->company))
            ->assertRedirect();

        $this->assertNull($this->company->refresh()->logo_path);
        Storage::disk('public')->assertMissing($caminho);
    }

    public function test_nivel_desconhecido_e_recusado(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.companies.logo-levels.update', $this->company), ['levels' => ['portal']])
            ->assertSessionHasErrors('levels.0');
    }

    /** Parametrizar a marca é da WeGift, não do Admin Cliente. */
    public function test_admin_cliente_nao_altera_a_logo(): void
    {
        $cliente = $this->adminCliente();

        $this->actingAs($cliente)
            ->post(route('admin.companies.logo.store', $this->company), ['logo' => $this->logo()])
            ->assertForbidden();

        $this->actingAs($cliente)
            ->put(route('admin.companies.logo-levels.update', $this->company), ['levels' => ['cliente']])
            ->assertForbidden();
    }
}
