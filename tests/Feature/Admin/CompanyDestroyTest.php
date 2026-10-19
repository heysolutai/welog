<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Exclusão de empresa pela tela (só Admin WeGift): leva Projetos, resgates e contas. */
class CompanyDestroyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $outra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $produto = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit', 'is_kit' => true]);

        $this->company = Company::create(['name' => 'Sai Ltda', 'cnpj' => '11222333000181']);
        User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->company->id]);
        $projeto = Project::create(['company_id' => $this->company->id, 'name' => 'P', 'quantity' => 1]);
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->company->id]);
        ProjectEntitlement::create(['project_id' => $projeto->id, 'user_id' => $user->id, 'product_id' => $produto->id]);
        $resgate = Redemption::create([
            'project_id' => $projeto->id, 'user_id' => $user->id, 'product_id' => $produto->id,
            'status' => 'confirmed', 'recipient_name' => $user->name, 'zip' => '80010000',
            'street' => 'Rua', 'number' => '1', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);
        DB::table('tracking_events')->insert([
            'trackable_type' => Redemption::class, 'trackable_id' => $resgate->id,
            'status' => 'posted', 'description' => 'Postado', 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->outra = Company::create(['name' => 'Fica Ltda', 'cnpj' => '46997756000133']);
        Project::create(['company_id' => $this->outra->id, 'name' => 'Q', 'quantity' => 1]);
    }

    public function test_admin_exclui_a_empresa_com_tudo_dela_e_o_resto_fica(): void
    {
        $admin = User::where('email', 'admin@welog.local')->first();

        $this->actingAs($admin)
            ->delete(route('admin.companies.destroy', $this->company))
            ->assertRedirect(route('admin.companies.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Sai Ltda excluída'));

        $this->assertDatabaseMissing('companies', ['id' => $this->company->id]);
        $this->assertSame(0, Project::where('company_id', $this->company->id)->count());
        $this->assertSame(0, User::where('company_id', $this->company->id)->count());
        $this->assertSame(0, Redemption::count());
        $this->assertSame(0, DB::table('tracking_events')->count());
        $this->assertSame(0, ProjectEntitlement::count());

        $this->assertDatabaseHas('companies', ['id' => $this->outra->id]);
        $this->assertSame(1, Project::where('company_id', $this->outra->id)->count());
    }

    public function test_n1_e_admin_cliente_nao_excluem(): void
    {
        $n1 = User::where('email', 'operador@welog.local')->first();
        $this->actingAs($n1)->delete(route('admin.companies.destroy', $this->company))->assertForbidden();

        $rh = User::where('company_id', $this->company->id)->where('role', User::ROLE_ADMIN_CLIENT)->first();
        $this->actingAs($rh)->delete(route('admin.companies.destroy', $this->company))->assertForbidden();

        $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
    }
}
