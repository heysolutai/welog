<?php

namespace Tests\Feature;

use App\Mail\ProjectReleasedMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin do Projeto que também resgata.
 *
 * Ele é Colaborador da empresa como os outros: o Admin Cliente ou a WeGift
 * ligam o resgate no cadastro dele, e a mesma conta administra e resgata. Na
 * lista de Colaboradores ele aparece com a etiqueta Admin; o presente fica em
 * "Meu presente", no cabeçalho. O Admin Projeto cadastra outros Admins, mas
 * não dá resgate a ninguém.
 */
class ProjectAdminRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDERECO = [
        'recipient_name' => 'Ana Admin',
        'cpf' => '529.982.247-25',
        'zip' => '80010-000',
        'street' => 'Rua XV de Novembro',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'Curitiba',
        'state' => 'PR',
    ];

    private Company $empresa;

    private Project $projeto;

    private Product $kit;

    private Product $caneca;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->seed();

        $this->empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->kit = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit A', 'stock_qty' => 100, 'stock_synced_at' => now()]);
        $this->caneca = Product::create(['omie_code' => 'CAN-A', 'name' => 'Caneca', 'stock_qty' => 100, 'stock_synced_at' => now()]);

        $this->projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => 'Projeto Um',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);
        $this->projeto->products()->attach($this->kit->id, ['quantity' => 10, 'stock_qty' => 10]);
        $this->projeto->products()->attach($this->caneca->id, ['quantity' => 10, 'stock_qty' => 10]);
    }

    private function adminCliente(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresa->id,
            'password_set_at' => now(),
        ]);
    }

    private function cadastrar(User $ator, array $extra = [])
    {
        return $this->actingAs($ator)->post(route('company.admins.store'), $extra + [
            'name' => 'Ana Admin',
            'email' => 'ana@acme.com.br',
            'project_id' => $this->projeto->id,
            'resgata' => true,
            'product_ids' => [$this->kit->id, $this->caneca->id],
            'mode' => 'choose',
        ]);
    }

    private function ana(): User
    {
        return User::where('email', 'ana@acme.com.br')->firstOrFail();
    }

    public function test_admin_cliente_cadastra_admin_que_tambem_resgata(): void
    {
        $this->cadastrar($this->adminCliente())->assertSessionHasNoErrors()->assertSessionHas('status');

        $ana = $this->ana();
        $this->assertSame(User::ROLE_PROJECT_ADMIN, $ana->role);
        $this->assertSame($this->projeto->id, $ana->admin_project_id);

        // "Escolhe 1 entre 2": as duas autorizações no mesmo grupo.
        $autorizacoes = ProjectEntitlement::where('user_id', $ana->id)->get();
        $this->assertCount(2, $autorizacoes);
        $this->assertNotNull($autorizacoes->first()->choice_group);
        $this->assertSame(1, $autorizacoes->pluck('choice_group')->unique()->count());

        // Já liberado, mas o aviso de liberação não sai junto do convite: o
        // link de criar senha dele invalidaria o do convite.
        Mail::assertNotQueued(ProjectReleasedMail::class);
    }

    public function test_conta_como_colaborador_com_a_etiqueta_admin(): void
    {
        $this->cadastrar($this->adminCliente());
        $ana = $this->ana();

        $this->actingAs(User::where('email', 'admin@welog.local')->firstOrFail())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn (Assert $p) => $p
                ->has('colaboradores.data', 1)
                ->where('colaboradores.data.0.user.id', $ana->id)
                ->where('colaboradores.data.0.admin', true)
                ->where('project.pessoas.total', 1)
                ->where('project.admins.0.resgata', true));
    }

    public function test_o_admin_ve_meu_presente_e_resgata_no_portal(): void
    {
        $this->cadastrar($this->adminCliente());
        $ana = $this->ana();
        $ana->forceFill(['password_set_at' => now()])->save();

        $this->actingAs($ana)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.user.meu_presente', true));

        $this->actingAs($ana)
            ->get(route('portal.catalog'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Portal/Catalog')->has('gifts', 2));

        $kit = ProjectEntitlement::where('user_id', $ana->id)->where('product_id', $this->kit->id)->firstOrFail();

        $this->actingAs($ana)
            ->post(route('portal.redeem', $kit), self::ENDERECO)
            ->assertRedirect(route('portal.redemptions'));

        $this->assertDatabaseHas('redemptions', ['user_id' => $ana->id, 'product_id' => $this->kit->id]);
    }

    /** Sem o resgate ligado, nada muda: Admin sem presente e sem atalho. */
    public function test_sem_a_opcao_o_admin_nao_ganha_presente(): void
    {
        $this->cadastrar($this->adminCliente(), ['resgata' => false])->assertSessionHasNoErrors();
        $ana = $this->ana();

        $this->assertSame(0, ProjectEntitlement::where('user_id', $ana->id)->count());

        $this->actingAs($ana)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.user.meu_presente', false));
    }

    public function test_wegift_tambem_libera_o_resgate(): void
    {
        $this->cadastrar(User::where('email', 'operador@welog.local')->firstOrFail())->assertSessionHasNoErrors();

        $this->assertSame(2, ProjectEntitlement::where('user_id', $this->ana()->id)->count());
    }

    /** O Admin Projeto cadastra outros Admins, mas não dá resgate a ninguém. */
    public function test_admin_projeto_nao_libera_resgate(): void
    {
        $adminProjeto = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->projeto->id,
            'password_set_at' => now(),
        ]);

        $this->cadastrar($adminProjeto)->assertSessionHasErrors('resgata');
        $this->assertDatabaseMissing('users', ['email' => 'ana@acme.com.br']);

        $this->actingAs($adminProjeto)
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn (Assert $p) => $p->where('permissions.grant_admin_redemption', false));
    }

    public function test_item_fora_do_pedido_nao_cria_o_admin(): void
    {
        $alheio = Product::create(['omie_code' => 'FORA', 'name' => 'Fora']);

        $this->cadastrar($this->adminCliente(), ['product_ids' => [$alheio->id]])
            ->assertSessionHasErrors('product_ids');

        $this->assertDatabaseMissing('users', ['email' => 'ana@acme.com.br']);
    }

    /** Pela lista de Colaboradores, sai só o resgate: a conta de Admin fica. */
    public function test_tirar_da_lista_de_colaboradores_mantem_o_admin(): void
    {
        $admin = $this->adminCliente();
        $this->cadastrar($admin);
        $ana = $this->ana();

        $this->actingAs($admin)
            ->delete(route('projects.collaborators.destroy', [$this->projeto, $ana]))
            ->assertSessionHas('status');

        $this->assertSame(0, ProjectEntitlement::where('user_id', $ana->id)->count());
        $ana->refresh();
        $this->assertSame(User::ROLE_PROJECT_ADMIN, $ana->role);
        $this->assertTrue($ana->active);
    }

    /** Admin cadastrado sem a opção: o Admin Cliente liga depois, pela aba Admin. */
    public function test_admin_cliente_permite_o_resgate_de_admin_existente(): void
    {
        $admin = $this->adminCliente();
        $this->cadastrar($admin, ['resgata' => false]);
        $ana = $this->ana();

        $this->actingAs($admin)
            ->post(route('projects.admins.redemption.grant', [$this->projeto, $ana]), [
                'product_ids' => [$this->kit->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(1, ProjectEntitlement::where('user_id', $ana->id)->count());
        Mail::assertNotQueued(ProjectReleasedMail::class);

        // De novo, não duplica: avisa que já resgata.
        $this->actingAs($admin)
            ->post(route('projects.admins.redemption.grant', [$this->projeto, $ana]), [
                'product_ids' => [$this->caneca->id],
            ])
            ->assertSessionHasErrors('resgate');

        $this->assertSame(1, ProjectEntitlement::where('user_id', $ana->id)->count());
    }

    public function test_so_admin_cliente_da_empresa_e_wegift_permitem_ou_tiram(): void
    {
        $this->cadastrar($this->adminCliente(), ['resgata' => false]);
        $ana = $this->ana();

        $adminProjeto = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->projeto->id,
            'password_set_at' => now(),
        ]);
        $outra = Company::create(['name' => 'Beta', 'cnpj' => '99888777000166']);
        $deFora = User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $outra->id]);

        foreach ([$adminProjeto, $ana, $deFora] as $ator) {
            $this->actingAs($ator)
                ->post(route('projects.admins.redemption.grant', [$this->projeto, $ana]), ['product_ids' => [$this->kit->id]])
                ->assertForbidden();
            $this->actingAs($ator)
                ->delete(route('projects.admins.redemption.revoke', [$this->projeto, $ana]))
                ->assertForbidden();
        }

        $this->assertSame(0, ProjectEntitlement::where('user_id', $ana->id)->count());

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('projects.admins.redemption.grant', [$this->projeto, $ana]), ['product_ids' => [$this->kit->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ProjectEntitlement::where('user_id', $ana->id)->count());
    }

    /** Só para Admin DESTE Projeto: Colaborador comum não passa por aqui. */
    public function test_permitir_so_vale_para_admin_do_projeto(): void
    {
        $colaborador = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresa->id]);

        $this->actingAs($this->adminCliente())
            ->post(route('projects.admins.redemption.grant', [$this->projeto, $colaborador]), ['product_ids' => [$this->kit->id]])
            ->assertNotFound();
    }

    public function test_tirar_o_resgate_pela_aba_admin_mantem_o_admin(): void
    {
        $admin = $this->adminCliente();
        $this->cadastrar($admin);
        $ana = $this->ana();

        $this->actingAs($admin)
            ->delete(route('projects.admins.redemption.revoke', [$this->projeto, $ana]))
            ->assertSessionHas('status');

        $this->assertSame(0, ProjectEntitlement::where('user_id', $ana->id)->count());
        $this->assertSame(User::ROLE_PROJECT_ADMIN, $ana->fresh()->role);
        $this->assertTrue($ana->fresh()->active);
    }

    /** Tirar o Admin do Projeto leva junto o presente dele. */
    public function test_tirar_o_admin_do_projeto_leva_o_resgate(): void
    {
        $this->cadastrar($this->adminCliente());
        $ana = $this->ana();

        $this->actingAs(User::where('email', 'admin@welog.local')->firstOrFail())
            ->delete(route('projects.admins.destroy', [$this->projeto, $ana]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ProjectEntitlement::where('user_id', $ana->id)->count());
    }
}
