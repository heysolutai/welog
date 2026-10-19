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
use Tests\TestCase;

/**
 * Cadastrar de novo, como Colaborador, um e-mail que já teve conta.
 *
 * A conta nasce presa à empresa do primeiro Projeto. Quando a pessoa saiu de
 * todos os Projetos dela, o e-mail não pode ficar bloqueado para sempre: a
 * conta passa para a empresa nova. Quem ainda está num Projeto da outra
 * empresa continua recusado, e a mensagem diz qual é a empresa.
 */
class CollaboratorEmailReuseTest extends TestCase
{
    use RefreshDatabase;

    private Company $sotreq;

    private Company $santa;

    private Project $projeto;

    private Product $kit;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->sotreq = Company::create(['name' => 'Sotreq S.A.', 'cnpj' => '12345678000190']);
        $this->santa = Company::create(['name' => 'Santa Origem', 'cnpj' => '98765432000110']);

        $this->projeto = Project::create(['company_id' => $this->sotreq->id, 'name' => 'Sotreq 85 anos', 'quantity' => 10]);
        $this->kit = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit']);
        $this->projeto->products()->attach($this->kit->id, ['quantity' => 10]);
    }

    private function cadastrar(string $email)
    {
        return $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('projects.entitlements.store', $this->projeto), [
                'name' => 'Pessoa Teste', 'email' => $email,
                'product_ids' => [$this->kit->id],
                'mode' => 'all',
            ]);
    }

    public function test_colaborador_de_outra_empresa_sem_projeto_passa_para_esta(): void
    {
        $conta = User::factory()->create([
            'email' => 'colaborador@example.com',
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->santa->id,
        ]);

        $this->cadastrar('colaborador@example.com')->assertSessionHasNoErrors();

        $this->assertSame($this->sotreq->id, $conta->fresh()->company_id);
        $this->assertTrue(ProjectEntitlement::where('user_id', $conta->id)->where('project_id', $this->projeto->id)->exists());
        $this->assertSame(1, User::where('email', 'colaborador@example.com')->count());
    }

    public function test_colaborador_num_projeto_de_outra_empresa_continua_recusado(): void
    {
        $conta = User::factory()->create([
            'email' => 'vendas@logicsell-ai.com',
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->santa->id,
        ]);
        $daSanta = Project::create(['company_id' => $this->santa->id, 'name' => 'Campanha Santa']);
        ProjectEntitlement::create(['project_id' => $daSanta->id, 'user_id' => $conta->id, 'product_id' => $this->kit->id]);

        $this->cadastrar('vendas@logicsell-ai.com')->assertSessionHasErrors();

        $this->assertSame($this->santa->id, $conta->fresh()->company_id);
        $this->assertStringContainsString('Santa Origem', session('errors')->first());
    }

    /** Admin desta empresa tirado e desativado volta como Colaborador. */
    public function test_admin_desativado_da_empresa_volta_como_colaborador(): void
    {
        $conta = User::factory()->create([
            'email' => 'ex.admin@sotreq.com.br',
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->sotreq->id,
            'admin_project_id' => $this->projeto->id,
            'active' => false,
        ]);

        $this->cadastrar('ex.admin@sotreq.com.br')->assertSessionHasNoErrors();

        $conta->refresh();
        $this->assertSame(User::ROLE_END_CUSTOMER, $conta->role);
        $this->assertNull($conta->admin_project_id);
        $this->assertTrue($conta->active);
    }

    /** Admin ATIVO continua prendendo o e-mail: uma conta tem um papel só. */
    public function test_admin_ativo_continua_recusado(): void
    {
        User::factory()->create([
            'email' => 'admin.ativo@sotreq.com.br',
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->sotreq->id,
        ]);

        $this->cadastrar('admin.ativo@sotreq.com.br')->assertSessionHasErrors();

        $this->assertSame(User::ROLE_ADMIN_CLIENT, User::where('email', 'admin.ativo@sotreq.com.br')->value('role'));
    }
}
