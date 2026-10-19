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

/**
 * `welog:companies:purge --keep=CNPJ` — só a empresa indicada fica, com tudo
 * dela (Projetos, Admin Cliente, Usuários, resgates). O resto some, inclusive
 * empresas com Admin Cliente, que a limpeza por Projeto poupa.
 */
class PurgeCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private Product $produto;

    private Company $fica;

    private Company $sai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->produto = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit', 'is_kit' => true]);

        $this->fica = $this->empresaCompleta('Fica Ltda', '46997756000133');
        $this->sai = $this->empresaCompleta('Sai Ltda', '11222333000181');

        // Empresa sem nada e empresa só com Admin Cliente: as duas devem sair.
        Company::create(['name' => 'Vazia', 'cnpj' => '22333444000105']);
        $soAdmin = Company::create(['name' => 'Só Admin', 'cnpj' => '33444555000129']);
        User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $soAdmin->id]);
    }

    /** Empresa com Admin Cliente, Projeto, Usuário do portal, autorização, resgate e rastreio. */
    private function empresaCompleta(string $nome, string $cnpj): Company
    {
        $empresa = Company::create(['name' => $nome, 'cnpj' => $cnpj]);
        User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $empresa->id]);

        $projeto = Project::create(['company_id' => $empresa->id, 'name' => $nome.' projeto', 'quantity' => 1]);
        $projeto->products()->attach($this->produto->id, ['quantity' => 1]);

        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $empresa->id]);
        ProjectEntitlement::create(['project_id' => $projeto->id, 'user_id' => $user->id, 'product_id' => $this->produto->id]);
        $resgate = Redemption::create([
            'project_id' => $projeto->id, 'user_id' => $user->id, 'product_id' => $this->produto->id,
            'status' => 'confirmed', 'recipient_name' => $user->name, 'zip' => '80010000',
            'street' => 'Rua', 'number' => '1', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);
        DB::table('tracking_events')->insert([
            'trackable_type' => Redemption::class, 'trackable_id' => $resgate->id,
            'status' => 'posted', 'description' => 'Postado', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $empresa;
    }

    public function test_simulacao_nao_apaga_nada(): void
    {
        $antes = [Company::count(), Project::count(), User::count(), Redemption::count()];

        $this->artisan('welog:companies:purge', ['--keep' => '46.997.756/0001-33'])
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();

        $this->assertSame($antes, [Company::count(), Project::count(), User::count(), Redemption::count()]);
    }

    public function test_mantem_so_a_empresa_indicada_com_tudo_dela(): void
    {
        $equipe = User::whereNull('company_id')->count();

        $this->artisan('welog:companies:purge', ['--keep' => '46.997.756/0001-33', '--execute' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame([$this->fica->id], Company::pluck('id')->all());
        $this->assertSame(1, Project::count());
        $this->assertSame($this->fica->id, Project::first()->company_id);
        $this->assertSame(1, Redemption::count());
        $this->assertSame(1, DB::table('tracking_events')->count());
        $this->assertSame(1, ProjectEntitlement::count());

        // Usuários: os 2 da empresa mantida (admin + portal) e a equipe WeGift inteira.
        $this->assertSame(2, User::where('company_id', $this->fica->id)->count());
        $this->assertSame(0, User::whereNotNull('company_id')->where('company_id', '!=', $this->fica->id)->count());
        $this->assertSame($equipe, User::whereNull('company_id')->count());
    }

    public function test_cnpj_desconhecido_ou_vazio_aborta_sem_apagar(): void
    {
        $antes = Company::count();

        $this->artisan('welog:companies:purge', ['--keep' => '99.999.999/0001-99', '--execute' => true, '--force' => true])
            ->assertFailed();
        $this->artisan('welog:companies:purge', ['--execute' => true, '--force' => true])
            ->assertFailed();

        $this->assertSame($antes, Company::count());
    }
}
