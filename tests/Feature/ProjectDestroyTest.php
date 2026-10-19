<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exclusão de Projeto pelo Admin WeGift: apaga rastreios, resgates e
 * autorizações em transação; empresa e usuários ficam. N1 e Admin Cliente
 * não têm o botão nem a rota (403).
 */
class ProjectDestroyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Project $project;

    private User $endCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->product = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit', 'is_kit' => true]);
        $this->project = Project::create([
            'company_id' => $this->company->id,
            'name' => 'Natal 2026',
            'quantity' => 10,
            'released_at' => now(),
        ]);
        $this->project->products()->attach($this->product->id, ['quantity' => 10]);

        $this->endCustomer = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->company->id,
        ]);

        ProjectEntitlement::create([
            'project_id' => $this->project->id,
            'user_id' => $this->endCustomer->id,
            'product_id' => $this->product->id,
        ]);

        $resgate = Redemption::create([
            'project_id' => $this->project->id,
            'user_id' => $this->endCustomer->id,
            'product_id' => $this->product->id,
            'status' => 'confirmed',
            'recipient_name' => $this->endCustomer->name,
            'zip' => '80010000',
            'street' => 'Rua XV de Novembro',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'Curitiba',
            'state' => 'PR',
        ]);

        DB::table('tracking_events')->insert([
            'trackable_type' => Redemption::class,
            'trackable_id' => $resgate->id,
            'occurred_at' => now(),
            'status' => 'shipped',
            'description' => 'Postado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_wegift_exclui_o_projeto_e_todo_o_rastro_mas_empresa_e_usuarios_ficam(): void
    {
        $admin = User::where('email', 'admin@welog.local')->first();

        $this->actingAs($admin)
            ->delete(route('projects.destroy', $this->project))
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Natal 2026'));

        $this->assertDatabaseMissing('projects', ['id' => $this->project->id]);
        $this->assertDatabaseMissing('redemptions', ['project_id' => $this->project->id]);
        $this->assertDatabaseMissing('project_entitlements', ['project_id' => $this->project->id]);
        $this->assertSame(0, DB::table('tracking_events')->where('trackable_type', Redemption::class)->count());

        // Quem NÃO some: a empresa tem ficha própria e a pessoa pode ter
        // autorização em outro Projeto.
        $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
        $this->assertDatabaseHas('users', ['id' => $this->endCustomer->id]);
    }

    public function test_n1_e_admin_cliente_recebem_403(): void
    {
        $n1 = User::where('email', 'operador@welog.local')->first();
        $rh = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($n1)->delete(route('projects.destroy', $this->project))->assertForbidden();
        $this->actingAs($rh)->delete(route('projects.destroy', $this->project))->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $this->project->id]);
        $this->assertDatabaseHas('redemptions', ['project_id' => $this->project->id]);
    }
}
