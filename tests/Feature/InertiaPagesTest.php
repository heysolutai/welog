<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Verifica que cada rota renderiza o componente Inertia (Vue) correto com as
 * props esperadas — pega erros de serialização de props e de wiring.
 */
class InertiaPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_operational_pages_render_for_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $p) => $p->component('Dashboard')->has('stats')->has('auth.user'));

        $this->actingAs($admin)->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Projects/Index')->has('projects.data')->has('projects.links'));

        $this->actingAs($admin)->get(route('redemptions.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Redemptions/Index')->has('redemptions'));

        $this->actingAs($admin)->get(route('imports.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Imports/Index')->has('rows.data')->has('contagens'));

        $this->actingAs($admin)->get(route('benefits.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Benefits/Index')->has('benefits'));

        $this->actingAs($admin)->get(route('projects.create'))
            ->assertInertia(fn (Assert $p) => $p->component('Projects/Create')->has('products'));
    }

    public function test_project_show_renders_with_full_resource(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $product = Product::create(['omie_code' => 'KIT-X', 'name' => 'Kit X', 'is_kit' => true]);
        $project = Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto Show',
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);
        $project->products()->attach($product->id, ['quantity' => 1]);

        $this->actingAs($this->admin())->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Projects/Show')
                ->where('project.name', 'Projeto Show')
                ->has('project.products', 1)
                ->has('permissions'));
    }

    public function test_import_row_show_uses_resgate_prop(): void
    {
        $import = \App\Models\AppnozImport::create([
            'filename' => 'SUV-1', 'file_hash' => 'hash-x', 'source' => 'webhook',
        ]);
        $row = $import->rows()->create([
            'row_number' => 1, 'cpf' => '52998224725', 'customer_name' => 'Ana',
            'product_code' => 'KIT-X', 'quantity' => 1, 'status' => 'pending',
        ]);

        $this->actingAs($this->admin())->get(route('imports.rows.show', $row))
            ->assertInertia(fn (Assert $p) => $p->component('Imports/Show')->has('resgate')->has('permissions'));
    }

    public function test_portal_pages_render_for_end_customer(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $product = Product::create(['omie_code' => 'KIT-Y', 'name' => 'Kit Y']);
        $project = Project::create([
            'company_id' => $company->id, 'name' => 'Proj', 'released_at' => now(), 'stock_available_at' => now(),
        ]);
        $customer = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id]);
        $entitlement = ProjectEntitlement::create([
            'project_id' => $project->id, 'user_id' => $customer->id, 'product_id' => $product->id,
        ]);

        $this->actingAs($customer)->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p->component('Portal/Catalog')->has('gifts', 1));

        $this->actingAs($customer)->get(route('portal.redeem.form', $entitlement))
            ->assertInertia(fn (Assert $p) => $p->component('Portal/Redeem')->has('entitlement'));

        $this->actingAs($customer)->get(route('portal.redemptions'))
            ->assertInertia(fn (Assert $p) => $p->component('Portal/Redemptions')->has('redemptions'));
    }
}
