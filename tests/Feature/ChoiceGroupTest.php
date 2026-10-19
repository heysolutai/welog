<?php

namespace Tests\Feature;

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
 * Opções de escolha (inclusão UAT): a pessoa pode ter N opções e UM resgate.
 *
 * `mode = choose` agrupa as autorizações num `choice_group`; resgatar uma
 * consome o grupo — as irmãs somem do alcance (bloqueio na leitura do portal
 * e na tentativa). `mode = all` segue criando autorizações independentes.
 */
class ChoiceGroupTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = [
        'recipient_name' => 'João Colaborador',
        'cpf' => '529.982.247-25',
        'zip' => '80010-000',
        'street' => 'Rua XV de Novembro',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'Curitiba',
        'state' => 'PR',
    ];

    private Project $project;

    /** @var array<int, Product> */
    private array $kits = [];

    protected function setUp(): void
    {
        parent::setUp();

        // O resgate dispara a baixa de estoque no gateway fake da Omie, que
        // persiste estado em storage — o fake isola a suíte do disco real.
        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->project = Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto Escolha',
            'quantity' => 30,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);

        foreach (['KIT-OPCAO-1', 'KIT-OPCAO-2', 'KIT-OPCAO-3'] as $code) {
            $kit = Product::create([
                'omie_code' => $code, 'name' => 'Opção '.$code,
                'stock_qty' => 100, 'stock_synced_at' => now(),
            ]);
            $this->project->products()->attach($kit->id, ['quantity' => 10]);
            $this->kits[] = $kit;
        }
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    /** Cadastra a pessoa com as 3 opções no modo informado e devolve o Usuário. */
    private function registrar(string $mode): User
    {
        $this->actingAs($this->operador())
            ->post(route('projects.entitlements.store', $this->project), [
                'name' => 'João Colaborador',
                'email' => 'joao@acme.com.br',
                'product_ids' => collect($this->kits)->pluck('id')->all(),
                'mode' => $mode,
            ])
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'joao@acme.com.br')->firstOrFail();
        $user->forceFill(['password_set_at' => now()])->save();

        return $user;
    }

    public function test_modo_choose_cria_as_opcoes_no_mesmo_grupo(): void
    {
        $this->registrar('choose');

        $entitlements = $this->project->entitlements()->get();

        $this->assertCount(3, $entitlements);
        $this->assertNotNull($entitlements->first()->choice_group);
        // Todas no MESMO grupo: são alternativas, não três brindes
        $this->assertSame(1, $entitlements->pluck('choice_group')->unique()->count());
    }

    public function test_modo_all_cria_autorizacoes_independentes(): void
    {
        $user = $this->registrar('all');

        $entitlements = $this->project->entitlements()->get();
        $this->assertCount(3, $entitlements);
        $this->assertTrue($entitlements->every(fn ($e) => $e->choice_group === null));

        // Independentes de verdade: dá para resgatar duas
        foreach ($entitlements->take(2) as $entitlement) {
            $this->actingAs($user)
                ->post(route('portal.redeem', $entitlement), self::ADDRESS)
                ->assertRedirect(route('portal.redemptions'));
        }

        $this->assertDatabaseCount('redemptions', 2);
    }

    public function test_resgatar_uma_opcao_consome_o_grupo(): void
    {
        $user = $this->registrar('choose');
        [$escolhida, $irma] = $this->project->entitlements()->get();

        $this->actingAs($user)
            ->post(route('portal.redeem', $escolhida), self::ADDRESS)
            ->assertRedirect(route('portal.redemptions'));

        // Tentativa na irmã: barrada com a mensagem de escolha feita
        $this->actingAs($user)
            ->post(route('portal.redeem', $irma), self::ADDRESS)
            ->assertSessionHasErrors('redeem');

        $this->assertStringContainsString(
            'Você já escolheu seu brinde neste projeto.',
            session('errors')->get('redeem')[0],
        );
        $this->assertDatabaseCount('redemptions', 1);
    }

    /** Corrida dupla (duplo clique em opções diferentes): só a primeira passa. */
    public function test_corrida_entre_duas_opcoes_do_grupo_gera_um_unico_resgate(): void
    {
        $user = $this->registrar('choose');
        $entitlements = $this->project->entitlements()->get();

        $primeira = $this->actingAs($user)->post(route('portal.redeem', $entitlements[0]), self::ADDRESS);
        $segunda = $this->actingAs($user)->post(route('portal.redeem', $entitlements[1]), self::ADDRESS);
        $terceira = $this->actingAs($user)->post(route('portal.redeem', $entitlements[2]), self::ADDRESS);

        $primeira->assertRedirect(route('portal.redemptions'));
        $segunda->assertSessionHasErrors('redeem');
        $terceira->assertSessionHasErrors('redeem');

        $this->assertDatabaseCount('redemptions', 1);
    }

    public function test_leitura_do_portal_bloqueia_a_irma_depois_da_escolha(): void
    {
        $user = $this->registrar('choose');
        [$escolhida, $irma] = $this->project->entitlements()->get();

        $this->actingAs($user)->post(route('portal.redeem', $escolhida), self::ADDRESS);

        // O formulário de resgate da irmã nem abre: volta ao catálogo
        $this->actingAs($user)
            ->get(route('portal.redeem.form', $irma))
            ->assertRedirect(route('portal.catalog'));
    }

    public function test_catalogo_agrupa_as_opcoes_e_marca_as_travadas(): void
    {
        $user = $this->registrar('choose');
        $entitlements = $this->project->entitlements()->get();
        $group = $entitlements->first()->choice_group;

        // Antes da escolha: grupo presente, nada travado
        $this->actingAs($user)
            ->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p
                ->has('gifts', 3)
                ->where('gifts.0.choice_group', $group)
                ->where('gifts.0.choice_locked', false)
                ->where('gifts.1.choice_group', $group)
                ->where('gifts.2.choice_group', $group));

        $this->actingAs($user)->post(route('portal.redeem', $entitlements[0]), self::ADDRESS);

        // Depois: a escolhida aparece resgatada, as irmãs travadas
        $this->actingAs($user)
            ->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('gifts.0.redeemed', true)
                ->where('gifts.0.choice_locked', false)
                ->where('gifts.1.redeemed', false)
                ->where('gifts.1.choice_locked', true)
                ->where('gifts.2.choice_locked', true));
    }

    /** Retrocompatibilidade: `product_id` singular segue criando UMA autorização sem grupo. */
    public function test_product_id_singular_continua_aceito(): void
    {
        $this->actingAs($this->operador())
            ->post(route('projects.entitlements.store', $this->project), [
                'name' => 'Maria Silva',
                'email' => 'maria@acme.com.br',
                'product_id' => $this->kits[0]->id,
            ])
            ->assertSessionHasNoErrors();

        $entitlement = ProjectEntitlement::whereHas('user', fn ($q) => $q->where('email', 'maria@acme.com.br'))
            ->firstOrFail();

        $this->assertSame($this->kits[0]->id, $entitlement->product_id);
        $this->assertNull($entitlement->choice_group);
    }

    /** Grupo com um item só não é escolha: nasce independente, sem grupo. */
    public function test_choose_com_um_unico_item_nao_cria_grupo(): void
    {
        $this->actingAs($this->operador())
            ->post(route('projects.entitlements.store', $this->project), [
                'name' => 'Um Só',
                'email' => 'umso@acme.com.br',
                'product_ids' => [$this->kits[0]->id],
                'mode' => 'choose',
            ])
            ->assertSessionHasNoErrors();

        $entitlement = ProjectEntitlement::whereHas('user', fn ($q) => $q->where('email', 'umso@acme.com.br'))
            ->firstOrFail();

        $this->assertNull($entitlement->choice_group);
    }
}
