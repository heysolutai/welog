<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Solicitação de kits (inclusão UAT): N unidades de um item do pedido, sem o
 * limite de "um por pessoa", limitada ao saldo do Projeto.
 *
 * Só a WeGift crítica solicita, em nome do Cliente. O Admin Cliente e o Admin
 * de Projeto já puderam; a operação decidiu deixar no controle da WeGift.
 */
class ProjectRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = [
        'quantity' => 30,
        'recipient_name' => 'Recepção Matriz',
        'cpf' => '529.982.247-25',
        'zip' => '80010-000',
        'street' => 'Rua XV de Novembro',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'Curitiba',
        'state' => 'PR',
        'notes' => 'Evento de fim de ano.',
    ];

    private Company $empresa;

    private Project $projeto;

    private Project $outroProjeto;

    private Product $kit;

    protected function setUp(): void
    {
        parent::setUp();

        // A solicitação empurra a baixa para o gateway fake da Omie (estado em
        // storage): o fake evita vazamento entre execuções.
        Storage::fake('local');
        $this->seed();

        $this->empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->kit = Product::create([
            'omie_code' => 'KIT-A', 'name' => 'Kit A',
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);

        $this->projeto = $this->projetoLiberado('Projeto Um');
        $this->outroProjeto = $this->projetoLiberado('Projeto Dois');
    }

    private function projetoLiberado(string $nome): Project
    {
        $projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => $nome,
            'quantity' => 100,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);
        $projeto->products()->attach($this->kit->id, ['quantity' => 100]);

        return $projeto;
    }

    private function adminCliente(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresa->id,
        ]);
    }

    private function payload(array $override = []): array
    {
        return $override + self::PAYLOAD + ['product_id' => $this->kit->id];
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    public function test_wegift_solicita_30_unidades_em_nome_do_cliente(): void
    {
        $admin = $this->operador();

        $this->actingAs($admin)
            ->post(route('projects.requests.store', $this->projeto), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('redemptions', [
            'project_id' => $this->projeto->id,
            'product_id' => $this->kit->id,
            'quantity' => 30,
            'requested_by' => $admin->id,
            'cpf' => '52998224725',
            'notes' => 'Evento de fim de ano.',
        ]);

        // A baixa é do saldo DO PROJETO, na WeLog: 100 contratados, 30
        // solicitados, 70 restantes.
        $this->assertSame(70.0, (float) $this->projeto->products()
            ->where('products.id', $this->kit->id)
            ->value('project_products.stock_qty'));

        // E o número da Omie não é tocado: ele é do produto inteiro, somado
        // entre os projetos que usam o mesmo kit, e virou só referência.
        $this->assertSame(100.0, (float) $this->kit->fresh()->stock_qty);
    }

    /** O lado do Cliente não solicita, nem no Projeto que administra. */
    public function test_admin_cliente_e_admin_de_projeto_nao_solicitam(): void
    {
        $projectAdmin = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->projeto->id,
        ]);

        foreach ([$this->adminCliente(), $projectAdmin] as $ator) {
            $this->actingAs($ator)
                ->post(route('projects.requests.store', $this->projeto), $this->payload(['quantity' => 5]))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('redemptions', 0);
    }

    public function test_admin_de_outra_empresa_recebe_403(): void
    {
        $outraEmpresa = Company::create(['name' => 'Beta', 'cnpj' => '22222222000192']);
        $adminDeFora = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $outraEmpresa->id,
        ]);

        $this->actingAs($adminDeFora)
            ->post(route('projects.requests.store', $this->projeto), $this->payload())
            ->assertForbidden();
    }

    public function test_cliente_final_recebe_403(): void
    {
        $endCustomer = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
            'password_set_at' => now(),
        ]);

        $this->actingAs($endCustomer)
            ->post(route('projects.requests.store', $this->projeto), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('redemptions', 0);
    }

    /** O Admin WeGift também solicita (não só o N1). */
    public function test_admin_wegift_tambem_solicita(): void
    {
        $operador = User::where('email', 'admin@welog.local')->firstOrFail();

        $this->actingAs($operador)
            ->post(route('projects.requests.store', $this->projeto), $this->payload(['quantity' => 2]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('redemptions', [
            'project_id' => $this->projeto->id,
            'quantity' => 2,
            'requested_by' => $operador->id,
        ]);
    }

    public function test_quantidade_acima_do_saldo_espelhado_e_recusada(): void
    {
        $this->actingAs($this->operador())
            ->post(route('projects.requests.store', $this->projeto), $this->payload(['quantity' => 101]))
            ->assertSessionHasErrors('request');

        $this->assertDatabaseCount('redemptions', 0);
        $this->assertSame(100.0, (float) $this->kit->fresh()->stock_qty);
    }

    /**
     * A WeGift abre a solicitação com o que chegou por telefone ou e-mail, e o
     * CPF costuma vir junto da nota. Exigir na hora fazia a operação inventar
     * um número para o formulário passar — e CPF inventado vai para a NF.
     */
    public function test_wegift_solicita_sem_cpf_e_a_pendencia_fica_na_linha(): void
    {
        $operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->actingAs($operador)
            ->post(route('projects.requests.store', $this->projeto), $this->payload(['cpf' => null]))
            ->assertSessionHasNoErrors();

        $resgate = Redemption::where('project_id', $this->projeto->id)->firstOrFail();
        $this->assertNull($resgate->cpf);

        // E se completa na lista de Resgates, sem recriar nada.
        $this->actingAs($operador)
            ->patch(route('redemptions.cpf', $resgate), ['cpf' => '529.982.247-25'])
            ->assertSessionHasNoErrors();

        $this->assertSame('52998224725', $resgate->fresh()->cpf);
    }

    /** CPF inválido continua recusado, mesmo para a WeGift. */
    public function test_cpf_invalido_e_recusado_tambem_para_a_wegift(): void
    {
        $operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->actingAs($operador)
            ->post(route('projects.requests.store', $this->projeto), $this->payload(['cpf' => '111.111.111-11']))
            ->assertSessionHasErrors('cpf');
    }

    /** Informar CPF é ação de WeGift: o Admin Cliente não alcança a rota. */
    public function test_admin_cliente_nao_informa_cpf_pela_lista(): void
    {
        $operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->actingAs($operador)
            ->post(route('projects.requests.store', $this->projeto), $this->payload(['cpf' => null]));

        $resgate = Redemption::where('project_id', $this->projeto->id)->firstOrFail();

        $this->actingAs($this->adminCliente())
            ->patch(route('redemptions.cpf', $resgate), ['cpf' => '529.982.247-25'])
            ->assertForbidden();
    }

    public function test_projeto_nao_liberado_ou_fora_de_estoque_recusa(): void
    {
        $admin = $this->operador();

        $this->projeto->update(['released_at' => null]);
        $this->actingAs($admin)
            ->post(route('projects.requests.store', $this->projeto), $this->payload())
            ->assertSessionHasErrors('request');

        $this->projeto->update(['released_at' => now(), 'pipeline_status' => Project::STATUS_ASSEMBLY]);
        $this->actingAs($admin)
            ->post(route('projects.requests.store', $this->projeto), $this->payload())
            ->assertSessionHasErrors('request');

        $this->assertDatabaseCount('redemptions', 0);
    }

    public function test_item_fora_do_pedido_e_recusado(): void
    {
        $alheio = Product::create(['omie_code' => 'FORA', 'name' => 'Fora do Pedido', 'stock_qty' => 50]);

        $this->actingAs($this->operador())
            ->post(route('projects.requests.store', $this->projeto), $this->payload(['product_id' => $alheio->id]))
            ->assertSessionHasErrors('request');

        $this->assertDatabaseCount('redemptions', 0);
    }
}
