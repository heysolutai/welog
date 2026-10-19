<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Inclusões do UAT no resgate: CPF obrigatório (válido, guardado só em
 * dígitos, exibido sempre com máscara) e observações opcionais.
 */
class RedemptionCpfNotesTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = [
        'recipient_name' => 'João Colaborador',
        'zip' => '80010-000',
        'street' => 'Rua XV de Novembro',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'Curitiba',
        'state' => 'PR',
    ];

    private ProjectEntitlement $entitlement;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $product = Product::create([
            'omie_code' => 'KIT-A', 'name' => 'Kit A',
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);
        $project = Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto A',
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);
        $project->products()->attach($product->id, ['quantity' => 10]);

        $this->user = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $company->id,
            'password_set_at' => now(),
        ]);

        $this->entitlement = ProjectEntitlement::create([
            'project_id' => $project->id,
            'user_id' => $this->user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_resgate_sem_cpf_e_recusado(): void
    {
        $this->actingAs($this->user)
            ->post(route('portal.redeem', $this->entitlement), self::ADDRESS)
            ->assertSessionHasErrors('cpf');

        $this->assertDatabaseCount('redemptions', 0);
    }

    public function test_resgate_com_cpf_invalido_e_recusado(): void
    {
        $this->actingAs($this->user)
            ->post(route('portal.redeem', $this->entitlement), self::ADDRESS + [
                'cpf' => '111.111.111-11',
            ])
            ->assertSessionHasErrors('cpf');

        $this->assertDatabaseCount('redemptions', 0);
    }

    public function test_resgate_grava_digitos_e_devolve_cpf_mascarado_com_observacoes(): void
    {
        $this->actingAs($this->user)
            ->post(route('portal.redeem', $this->entitlement), self::ADDRESS + [
                'cpf' => '529.982.247-25',
                'notes' => 'Entregar na portaria, com o zelador.',
            ])
            ->assertRedirect(route('portal.redemptions'));

        // No banco, só os dígitos
        $this->assertDatabaseHas('redemptions', [
            'cpf' => '52998224725',
            'notes' => 'Entregar na portaria, com o zelador.',
        ]);

        // Nas props do portal, a máscara 000.000.000-00
        $this->actingAs($this->user)
            ->get(route('portal.redemptions'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('redemptions.0.cpf', '529.982.247-25')
                ->where('redemptions.0.notes', 'Entregar na portaria, com o zelador.')
                ->where('redemptions.0.quantity', 1));
    }

    public function test_lista_da_operacao_e_tela_do_projeto_mostram_cpf_mascarado(): void
    {
        $this->actingAs($this->user)
            ->post(route('portal.redeem', $this->entitlement), self::ADDRESS + [
                'cpf' => '52998224725',
                'notes' => 'Cuidado, frágil.',
            ]);

        $operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->actingAs($operador)
            ->get(route('redemptions.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('redemptions.data.0.cpf', '529.982.247-25')
                ->where('redemptions.data.0.observacoes', 'Cuidado, frágil.')
                ->where('redemptions.data.0.quantidade', 1)
                ->where('redemptions.data.0.solicitacao', false));

        $this->actingAs($operador)
            ->get(route('projects.show', $this->entitlement->project_id))
            ->assertInertia(fn (Assert $p) => $p
                ->where('resgates.data.0.cpf', '529.982.247-25')
                ->where('resgates.data.0.notes', 'Cuidado, frágil.')
                ->where('resgates.data.0.is_request', false));
    }

    /**
     * Cliente que desmarcou o CPF nas Customizações não coleta CPF: o portal
     * não mostra o campo, o resgate passa sem ele, e o que vier é descartado.
     */
    public function test_cliente_sem_cpf_nas_customizacoes_resgata_sem_cpf(): void
    {
        $this->entitlement->project->company->update([
            'customizations' => ['import_fields' => true, 'import_columns' => ['nome', 'email', 'telefone']],
        ]);

        $this->actingAs($this->user)
            ->get(route('portal.redeem.form', $this->entitlement))
            ->assertInertia(fn (Assert $p) => $p->where('askCpf', false));

        $this->actingAs($this->user)
            ->post(route('portal.redeem', $this->entitlement), self::ADDRESS + ['cpf' => '111.111.111-11'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('portal.redemptions'));

        $this->assertDatabaseHas('redemptions', ['user_id' => $this->user->id, 'cpf' => null]);
    }

    public function test_cliente_padrao_continua_pedindo_cpf(): void
    {
        $this->actingAs($this->user)
            ->get(route('portal.redeem.form', $this->entitlement))
            ->assertInertia(fn (Assert $p) => $p->where('askCpf', true));
    }

    /** Resgates antigos (de antes da obrigatoriedade) não quebram a exibição. */
    public function test_resgate_antigo_sem_cpf_exibe_nulo(): void
    {
        Redemption::create([
            'project_id' => $this->entitlement->project_id,
            'user_id' => $this->user->id,
            'product_id' => $this->entitlement->product_id,
            'status' => Redemption::STATUS_CONFIRMED,
            'zip' => '80010000',
        ] + self::ADDRESS);

        $this->actingAs($this->user)
            ->get(route('portal.redemptions'))
            ->assertInertia(fn (Assert $p) => $p->where('redemptions.0.cpf', null));
    }
}
