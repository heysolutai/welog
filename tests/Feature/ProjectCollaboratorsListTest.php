<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A aba Colaboradores recebe uma linha por pessoa, já resumida pelo servidor.
 *
 * Antes ela recebia cada autorização e agrupava no navegador; num Projeto de
 * nove mil pessoas com o Colete em sete tamanhos, a página estourou a memória
 * do servidor montando dezenas de milhares de autorizações.
 */
class ProjectCollaboratorsListTest extends TestCase
{
    use RefreshDatabase;

    public function test_uma_linha_por_pessoa_com_o_resumo_dos_itens(): void
    {
        Storage::fake('local');
        $this->seed();

        $empresa = Company::create(['name' => 'Sotreq S.A.', 'cnpj' => '12345678000190']);
        $projeto = Project::create(['company_id' => $empresa->id, 'name' => 'Campanha', 'quantity' => 10]);
        $item = fn (string $codigo) => Product::create(['omie_code' => $codigo, 'name' => "Item {$codigo}"]);
        [$a, $b, $c, $d, $e] = [$item('A'), $item('B'), $item('C'), $item('D'), $item('E')];

        $ana = User::factory()->create([
            'name' => 'Ana', 'role' => User::ROLE_END_CUSTOMER, 'company_id' => $empresa->id, 'password_set_at' => null,
        ]);
        $beto = User::factory()->create([
            'name' => 'Beto', 'role' => User::ROLE_END_CUSTOMER, 'company_id' => $empresa->id, 'password_set_at' => now(),
        ]);

        // Ana: dois garantidos e um "escolhe 1 de 3". Beto: um item só.
        foreach ([$a, $b] as $produto) {
            ProjectEntitlement::create(['project_id' => $projeto->id, 'user_id' => $ana->id, 'product_id' => $produto->id]);
        }
        foreach ([$c, $d, $e] as $produto) {
            ProjectEntitlement::create([
                'project_id' => $projeto->id, 'user_id' => $ana->id, 'product_id' => $produto->id, 'choice_group' => 'grupo-ana',
            ]);
        }
        ProjectEntitlement::create(['project_id' => $projeto->id, 'user_id' => $beto->id, 'product_id' => $a->id]);

        // Ana já escolheu uma opção do grupo: o grupo conta como resgatado.
        Redemption::create([
            'project_id' => $projeto->id, 'user_id' => $ana->id, 'product_id' => $d->id,
            'status' => 'confirmed', 'recipient_name' => 'Ana', 'zip' => '80010000',
            'street' => 'Rua', 'number' => '1', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);

        $admin = User::where('email', 'admin@welog.local')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('projects.show', $projeto))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('project.entitlements')
                ->where('project.entitlements_count', 6)
                ->has('colaboradores.data', 2)
                ->where('colaboradores.data.0.user.name', 'Ana')
                ->where('colaboradores.data.0.itens', 5)
                ->where('colaboradores.data.0.itemsLabel', '2 itens + 1 de 3')
                ->where('colaboradores.data.0.itemsTitle', 'Item A, Item B, Item C, Item D, Item E')
                ->where('colaboradores.data.0.unidades', 3)
                ->where('colaboradores.data.0.resgatadas', 1)
                ->where('colaboradores.data.0.redeemed', false)
                ->where('colaboradores.data.0.access_pending', true)
                ->where('colaboradores.data.1.user.name', 'Beto')
                ->where('colaboradores.data.1.itemsLabel', 'Item A')
                ->where('colaboradores.data.1.unidades', 1)
                ->where('colaboradores.data.1.access_pending', false)
                ->where('colaboradores.total', 2)
                // Os números da lista inteira, para as abas e o card de situação.
                ->where('project.pessoas.total', 2)
                ->where('project.pessoas.acesso_pendente', 1)
                ->where('project.pessoas.resgataram', 1)
                ->where('project.resgates_resumo.total', 1)
                ->missing('project.redemptions'));
    }

    /**
     * Num Projeto de milhares de pessoas a tela recebe UMA página: a lista
     * inteira eram megabytes a cada abertura e a cada volta para a tela.
     */
    public function test_a_lista_vem_paginada_e_a_busca_vai_ao_servidor(): void
    {
        Storage::fake('local');
        $this->seed();

        $empresa = Company::create(['name' => 'Sotreq S.A.', 'cnpj' => '12345678000190']);
        $projeto = Project::create(['company_id' => $empresa->id, 'name' => 'Campanha', 'quantity' => 40]);
        $item = Product::create(['omie_code' => 'A', 'name' => 'Item A']);

        foreach (range(1, 30) as $n) {
            $pessoa = User::factory()->create([
                'name' => $n === 27 ? 'João Conceição' : "Pessoa {$n}",
                'email' => "pessoa{$n}@sotreq.com.br",
                'role' => User::ROLE_END_CUSTOMER,
                'company_id' => $empresa->id,
            ]);
            ProjectEntitlement::create(['project_id' => $projeto->id, 'user_id' => $pessoa->id, 'product_id' => $item->id]);
        }

        $admin = User::where('email', 'admin@welog.local')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('projects.show', $projeto))
            ->assertInertia(fn (Assert $page) => $page
                ->has('colaboradores.data', 25)
                ->where('colaboradores.total', 30)
                ->where('colaboradores.pagina', 1)
                ->where('colaboradores.data.0.user.name', 'Pessoa 1')
                ->where('project.pessoas.total', 30));

        $this->actingAs($admin)
            ->get(route('projects.show', ['project' => $projeto, 'pagina' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('colaboradores.data', 5)
                ->where('colaboradores.pagina', 2)
                ->where('colaboradores.data.0.user.name', 'Pessoa 26'));

        // Página além da última cai na última, não numa lista vazia.
        $this->actingAs($admin)
            ->get(route('projects.show', ['project' => $projeto, 'pagina' => 9]))
            ->assertInertia(fn (Assert $page) => $page->where('colaboradores.pagina', 2));

        // Sem diferenciar maiúscula nem acento.
        $this->actingAs($admin)
            ->get(route('projects.show', ['project' => $projeto, 'busca' => 'JOÃO']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('colaboradores.data', 1)
                ->where('colaboradores.total', 1)
                ->where('colaboradores.busca', 'JOÃO')
                ->where('colaboradores.data.0.user.name', 'João Conceição')
                ->where('project.pessoas.total', 30));

        $this->actingAs($admin)
            ->get(route('projects.show', ['project' => $projeto, 'busca' => 'pessoa1@']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('colaboradores.total', 1)
                ->where('colaboradores.data.0.user.email', 'pessoa1@sotreq.com.br'));
    }

    /** A recarga parcial de uma lista não remonta o Projeto (e vice-versa). */
    public function test_recarga_parcial_monta_so_a_lista_pedida(): void
    {
        Storage::fake('local');
        $this->seed();

        $empresa = Company::create(['name' => 'Sotreq S.A.', 'cnpj' => '12345678000190']);
        $projeto = Project::create(['company_id' => $empresa->id, 'name' => 'Campanha', 'quantity' => 1]);
        $admin = User::where('email', 'admin@welog.local')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('projects.show', $projeto))
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('colaboradores', fn (Assert $reload) => $reload
                    ->has('colaboradores')
                    ->missing('project')
                    ->missing('resgates')));
    }
}
