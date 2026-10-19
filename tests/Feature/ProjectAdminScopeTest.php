<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Escopo do Admin de Projeto (project_admin, feedback do UAT): enxerga o que o
 * Admin Cliente enxerga, mas só do Projeto dele — lista, tela do Projeto e
 * gestão de Usuários. O Admin Geral (admin_client) continua vendo a empresa
 * inteira.
 */
class ProjectAdminScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto1;

    private Project $projeto2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Mail::fake();

        $this->empresa = Company::create(['name' => 'Empresa A', 'cnpj' => '11111111000191']);
        $this->projeto1 = Project::create(['company_id' => $this->empresa->id, 'name' => 'Projeto Um']);
        $this->projeto2 = Project::create(['company_id' => $this->empresa->id, 'name' => 'Projeto Dois']);

        $kit = Product::create(['omie_code' => 'KIT-UM', 'name' => 'Kit Um']);
        $this->projeto1->products()->attach($kit->id, ['quantity' => 10]);
        $kit2 = Product::create(['omie_code' => 'KIT-DOIS', 'name' => 'Kit Dois']);
        $this->projeto2->products()->attach($kit2->id, ['quantity' => 10]);
    }

    private function projectAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->projeto1->id,
        ]);
    }

    private function generalAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresa->id,
        ]);
    }

    public function test_lista_de_projetos_mostra_apenas_o_projeto_dele(): void
    {
        $this->actingAs($this->projectAdmin())
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Projects/Index')
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $this->projeto1->id)
                ->where('projects.total', 1));
    }

    public function test_admin_geral_continua_vendo_todos_os_projetos_da_empresa(): void
    {
        $this->actingAs($this->generalAdmin())
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->has('projects.data', 2));
    }

    public function test_abre_o_proprio_projeto_com_permissoes_de_cliente(): void
    {
        $this->actingAs($this->projectAdmin())
            ->get(route('projects.show', $this->projeto1))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Projects/Show')
                ->where('permissions.client', true)
                ->where('permissions.manage_customers', true)
                ->where('permissions.block_redemptions', false)
                ->where('permissions.critical', false));
    }

    public function test_projeto_de_fora_do_escopo_da_403_mesmo_sendo_da_mesma_empresa(): void
    {
        $this->actingAs($this->projectAdmin())
            ->get(route('projects.show', $this->projeto2))
            ->assertForbidden();
    }

    /** Bloquear resgates e solicitar kits são da WeGift: o Admin Cliente não vê. */
    public function test_admin_geral_nao_bloqueia_nem_solicita(): void
    {
        $this->actingAs($this->generalAdmin())
            ->get(route('projects.show', $this->projeto2))
            ->assertInertia(fn (Assert $p) => $p
                ->where('permissions.block_redemptions', false)
                ->where('permissions.critical', false)
                ->where('permissions.manage_customers', true));
    }

    public function test_equipe_wegift_bloqueia_e_solicita(): void
    {
        $admin = User::where('email', 'admin@welog.local')->first();

        $this->actingAs($admin)
            ->get(route('projects.show', $this->projeto1))
            ->assertInertia(fn (Assert $p) => $p
                ->where('permissions.block_redemptions', true)
                ->where('permissions.critical', true));
    }

    public function test_cadastra_usuario_no_proprio_projeto(): void
    {
        $this->actingAs($this->projectAdmin())
            ->post(route('projects.entitlements.store', $this->projeto1), [
                'name' => 'Colaborador Um',
                'email' => 'colab.um@empresa-a.com.br',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->projeto1->entitlements()->count());
    }

    public function test_nao_cadastra_usuario_em_projeto_fora_do_escopo(): void
    {
        $this->actingAs($this->projectAdmin())
            ->post(route('projects.entitlements.store', $this->projeto2), [
                'name' => 'Colaborador Dois',
                'email' => 'colab.dois@empresa-a.com.br',
            ])
            ->assertSessionHasErrors('entitlement');

        $this->assertSame(0, $this->projeto2->entitlements()->count());
    }

    public function test_modelo_de_lote_so_no_proprio_projeto(): void
    {
        $projectAdmin = $this->projectAdmin();

        $this->actingAs($projectAdmin)
            ->get(route('projects.entitlements.template', $this->projeto1))
            ->assertOk();

        $this->actingAs($projectAdmin)
            ->get(route('projects.entitlements.template', $this->projeto2))
            ->assertForbidden();
    }

    public function test_nao_remove_nem_reconvida_usuario_de_projeto_fora_do_escopo(): void
    {
        $usuario = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
            'password_set_at' => null,
        ]);
        $entitlement = ProjectEntitlement::create([
            'project_id' => $this->projeto2->id,
            'user_id' => $usuario->id,
            'product_id' => $this->projeto2->products()->value('products.id'),
        ]);

        $projectAdmin = $this->projectAdmin();

        $this->actingAs($projectAdmin)
            ->delete(route('projects.entitlements.destroy', [$this->projeto2, $entitlement]))
            ->assertForbidden();

        $this->actingAs($projectAdmin)
            ->post(route('projects.entitlements.resend', [$this->projeto2, $entitlement]))
            ->assertForbidden();

        $this->assertDatabaseHas('project_entitlements', ['id' => $entitlement->id]);
    }

    public function test_remove_usuario_do_proprio_projeto(): void
    {
        $usuario = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
        ]);
        $entitlement = ProjectEntitlement::create([
            'project_id' => $this->projeto1->id,
            'user_id' => $usuario->id,
            'product_id' => $this->projeto1->products()->value('products.id'),
        ]);

        $this->actingAs($this->projectAdmin())
            ->delete(route('projects.entitlements.destroy', [$this->projeto1, $entitlement]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('project_entitlements', ['id' => $entitlement->id]);
    }

    public function test_nao_acessa_a_area_operacional_wegift(): void
    {
        $projectAdmin = $this->projectAdmin();

        $this->actingAs($projectAdmin)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($projectAdmin)->get(route('imports.index'))->assertForbidden();
        $this->actingAs($projectAdmin)->get(route('benefits.index'))->assertForbidden();
    }

    public function test_destino_pos_login_e_a_lista_de_projetos(): void
    {
        $this->assertSame('projects.index', $this->projectAdmin()->homeRouteName());
    }
}
