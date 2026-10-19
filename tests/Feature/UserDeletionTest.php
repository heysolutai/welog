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
 * Exclusão de usuários: o Admin WeGift apaga qualquer conta; o Admin Cliente
 * (RH) tira colaboradores dos Projetos da própria empresa.
 */
class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->produto = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit', 'is_kit' => true]);
        $this->projeto = Project::create([
            'company_id' => $this->empresa->id, 'name' => 'Natal', 'quantity' => 5,
        ]);
    }

    private function colaborador(?Project $projeto = null, bool $comResgate = false): ProjectEntitlement
    {
        $user = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresa->id,
        ]);

        $entitlement = ProjectEntitlement::create([
            'project_id' => ($projeto ?? $this->projeto)->id,
            'user_id' => $user->id,
            'product_id' => $this->produto->id,
        ]);

        if ($comResgate) {
            $resgate = Redemption::create([
                'project_id' => $entitlement->project_id, 'user_id' => $user->id,
                'product_id' => $this->produto->id, 'status' => 'confirmed',
                'recipient_name' => $user->name, 'zip' => '80010000', 'street' => 'Rua',
                'number' => '1', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
            ]);
            DB::table('tracking_events')->insert([
                'trackable_type' => Redemption::class, 'trackable_id' => $resgate->id,
                'status' => 'posted', 'description' => 'Postado', 'occurred_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $entitlement;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    private function rh(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->empresa->id,
        ]);
    }

    // ── Admin WeGift: exclui qualquer usuário ────────────────────────────

    public function test_admin_wegift_exclui_usuario_com_resgates(): void
    {
        $entitlement = $this->colaborador(comResgate: true);
        $user = $entitlement->user;

        $this->actingAs($this->admin())
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, $user->name));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, Redemption::count());
        $this->assertSame(0, ProjectEntitlement::count());
        $this->assertSame(0, DB::table('tracking_events')->count());
        // O Projeto e a empresa continuam.
        $this->assertDatabaseHas('projects', ['id' => $this->projeto->id]);
        $this->assertDatabaseHas('companies', ['id' => $this->empresa->id]);
    }

    public function test_admin_nao_exclui_a_si_mesmo_e_n1_nao_exclui_ninguem(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertSessionHasErrors('destroy');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);

        $n1 = User::where('email', 'operador@welog.local')->first();
        $alvo = $this->colaborador()->user;
        $this->actingAs($n1)->delete(route('admin.users.destroy', $alvo))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $alvo->id]);
    }

    // ── RH: remove colaborador do Projeto ────────────────────────────────

    public function test_rh_remove_colaborador_e_a_conta_some_quando_nao_ha_outro_projeto(): void
    {
        $entitlement = $this->colaborador(comResgate: true);
        $user = $entitlement->user;

        $this->actingAs($this->rh())
            ->delete(route('projects.entitlements.destroy', [$this->projeto, $entitlement]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, Redemption::count());
        $this->assertSame(0, DB::table('tracking_events')->count());
    }

    public function test_colaborador_em_dois_projetos_apenas_sai_deste(): void
    {
        $outro = Project::create(['company_id' => $this->empresa->id, 'name' => 'Páscoa', 'quantity' => 2]);

        $entitlement = $this->colaborador(comResgate: true);
        $user = $entitlement->user;
        ProjectEntitlement::create([
            'project_id' => $outro->id, 'user_id' => $user->id, 'product_id' => $this->produto->id,
        ]);

        $this->actingAs($this->rh())
            ->delete(route('projects.entitlements.destroy', [$this->projeto, $entitlement]))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'continua ativa'));

        // A conta fica, e o vínculo com o outro Projeto também.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, ProjectEntitlement::where('user_id', $user->id)->count());
        $this->assertSame($outro->id, ProjectEntitlement::where('user_id', $user->id)->value('project_id'));
        // O resgate feito NESTE Projeto foi junto.
        $this->assertSame(0, Redemption::count());
    }

    public function test_rh_de_outra_empresa_nao_remove_e_operador_remove(): void
    {
        $entitlement = $this->colaborador();

        $outraEmpresa = Company::create(['name' => 'Outra', 'cnpj' => '98765432000110']);
        $intruso = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $outraEmpresa->id,
        ]);

        $this->actingAs($intruso)
            ->delete(route('projects.entitlements.destroy', [$this->projeto, $entitlement]))
            ->assertForbidden();
        $this->assertDatabaseHas('project_entitlements', ['id' => $entitlement->id]);

        $n1 = User::where('email', 'operador@welog.local')->first();
        $this->actingAs($n1)
            ->delete(route('projects.entitlements.destroy', [$this->projeto, $entitlement]))
            ->assertRedirect();
        $this->assertDatabaseMissing('project_entitlements', ['id' => $entitlement->id]);
    }
}
