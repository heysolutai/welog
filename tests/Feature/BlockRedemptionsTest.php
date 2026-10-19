<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\FluxoA\RedeemKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Freio de mão do resgate, só do lado da WeGift (definição da operação):
 *
 *   Bloquear    → WeGift crítica (Admin WeGift e N1).
 *   Desbloquear → só o Admin WeGift.
 *
 * O Admin Cliente e o Admin de Projeto já puderam bloquear; a operação tirou
 * deles. O cliente que vir um problema avisa a WeGift.
 */
class BlockRedemptionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $project;

    private ProjectEntitlement $entitlement;

    private User $pessoa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();

        $this->empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $kit = Product::create([
            'omie_code' => 'KIT-BLK-01', 'name' => 'Kit Bloqueio',
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);

        $this->project = Project::create([
            'company_id' => $this->empresa->id,
            'name' => '66906 - ACME',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);
        $this->project->products()->attach($kit->id, ['quantity' => 10]);

        $this->pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
            'password_set_at' => now(),
        ]);
        $this->entitlement = ProjectEntitlement::create([
            'project_id' => $this->project->id,
            'user_id' => $this->pessoa->id,
            'product_id' => $kit->id,
        ]);
    }

    private function wegift(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function adminCliente(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresa->id,
            'password_set_at' => now(),
        ]);
    }

    private function adminProjeto(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->project->id,
            'password_set_at' => now(),
        ]);
    }

    private function bloquear(User $ator, ?string $motivo = null)
    {
        return $this->actingAs($ator)->post(
            route('projects.redemptions.block', $this->project),
            $motivo === null ? [] : ['reason' => $motivo],
        );
    }

    public function test_wegift_bloqueia_e_o_resgate_para_na_hora(): void
    {
        $this->bloquear($this->wegift('operador@welog.local'), 'Endereços errados na planilha')
            ->assertRedirect()
            ->assertSessionHas('status');

        $project = $this->project->fresh();
        $this->assertTrue($project->isRedemptionBlocked());
        $this->assertSame('Endereços errados na planilha', $project->redemptions_block_reason);

        // O portal barra na leitura, antes de a pessoa preencher o endereço
        $this->actingAs($this->pessoa)
            ->get(route('portal.redeem.form', $this->entitlement))
            ->assertRedirect(route('portal.catalog'))
            ->assertSessionHasErrors('redeem');

        // E o serviço recusa mesmo que alguém chame direto
        $this->expectException(DomainException::class);
        app(RedeemKit::class)->handle($this->entitlement, [
            'recipient_name' => 'Bruno', 'cpf' => '52998224725',
            'zip' => '80010-000', 'street' => 'Rua XV', 'number' => '10',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ], $this->pessoa);
    }

    /** O lado do Cliente não bloqueia, nem no Projeto que administra. */
    public function test_admin_cliente_e_admin_de_projeto_nao_bloqueiam(): void
    {
        foreach ([$this->adminCliente(), $this->adminProjeto()] as $ator) {
            $this->bloquear($ator)->assertForbidden();
            $this->assertFalse($this->project->fresh()->isRedemptionBlocked());
        }

        $this->bloquear($this->wegift('admin@welog.local'))->assertSessionHasNoErrors();
        $this->assertTrue($this->project->fresh()->isRedemptionBlocked());
    }

    public function test_usuario_do_portal_nao_bloqueia(): void
    {
        $this->bloquear($this->pessoa)->assertForbidden();
        $this->assertFalse($this->project->fresh()->isRedemptionBlocked());
    }

    /** Admin de OUTRA empresa não mexe na fila alheia. */
    public function test_admin_de_outra_empresa_nao_bloqueia(): void
    {
        $outra = Company::create(['name' => 'Beta', 'cnpj' => '99888777000166']);
        $estranho = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $outra->id,
            'password_set_at' => now(),
        ]);

        $this->bloquear($estranho)->assertForbidden();
        $this->assertFalse($this->project->fresh()->isRedemptionBlocked());
    }

    public function test_so_admin_wegift_desbloqueia(): void
    {
        $this->bloquear($this->wegift('operador@welog.local'))->assertSessionHasNoErrors();

        // Quem bloqueou (o N1) NÃO solta; o lado do Cliente também não
        foreach ([$this->adminCliente(), $this->adminProjeto(), $this->wegift('operador@welog.local')] as $ator) {
            $this->actingAs($ator)
                ->post(route('projects.redemptions.unblock', $this->project))
                ->assertForbidden();

            $this->assertTrue($this->project->fresh()->isRedemptionBlocked());
        }

        $this->actingAs($this->wegift('admin@welog.local'))
            ->post(route('projects.redemptions.unblock', $this->project))
            ->assertSessionHas('status');

        $project = $this->project->fresh();
        $this->assertFalse($project->isRedemptionBlocked());
        $this->assertNull($project->redemptions_blocked_by);
        // Desbloquear não desfaz a liberação: ninguém recebe e-mail de novo.
        $this->assertTrue($project->isReleased());
    }

    /** A solicitação do admin para na mesma trava do resgate individual. */
    public function test_solicitacao_de_kits_fica_suspensa_enquanto_bloqueado(): void
    {
        $operador = $this->wegift('operador@welog.local');
        $this->bloquear($operador)->assertSessionHasNoErrors();

        $this->actingAs($operador)
            ->post(route('projects.requests.store', $this->project), [
                'product_id' => $this->project->products->first()->id,
                'quantity' => 5,
                'recipient_name' => 'Recepção ACME',
                'cpf' => '529.982.247-25',
                'zip' => '80010-000', 'street' => 'Rua XV', 'number' => '100',
                'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
            ])
            ->assertSessionHasErrors('request');
    }

    public function test_bloqueio_duplicado_e_recusado_com_mensagem(): void
    {
        $this->bloquear($this->wegift('operador@welog.local'))->assertSessionHasNoErrors();
        $this->bloquear($this->wegift('operador@welog.local'))->assertSessionHasErrors('block');
    }

    /** A tela precisa saber quem puxou o freio e se este perfil pode soltar. */
    public function test_a_tela_mostra_o_bloqueio_e_as_permissoes(): void
    {
        $this->bloquear($this->wegift('operador@welog.local'), 'Kit com defeito');

        $this->actingAs($this->wegift('admin@welog.local'))
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn ($page) => $page
                ->where('project.redemptions_blocked', true)
                ->where('project.redemptions_block_reason', 'Kit com defeito')
                ->where('permissions.unblock_redemptions', true)
                ->etc()
            );

        $this->actingAs($this->adminCliente())
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn ($page) => $page
                ->where('project.redemptions_blocked', true)
                ->where('permissions.unblock_redemptions', false)
                ->where('permissions.block_redemptions', false)
                ->etc()
            );
    }
}
