<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Filtro `q` da lista de Projetos sem diferenciar maiúsculas de minúsculas
 * (falha do UAT: "acme" não achava a empresa "ACME" no Postgres, cujo LIKE é
 * case-sensitive — ao contrário do sqlite dos testes, que escondia o bug).
 */
class ProjectSearchFilterTest extends TestCase
{
    use RefreshDatabase;

    private Project $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Kit Boas-Vindas',
            'omie_order_number' => 'PED-9001',
        ]);

        $kit = Product::create(['omie_code' => 'KIT-BV', 'name' => 'Mochila Executiva', 'is_kit' => true]);
        $this->projeto->products()->attach($kit->id, ['quantity' => 10]);

        // Ruído: um Projeto que nenhuma das buscas abaixo deve trazer.
        $outra = Company::create(['name' => 'Umbrella', 'cnpj' => '98765432000109']);
        Project::create(['company_id' => $outra->id, 'name' => 'Projeto Alheio']);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    private function buscar(string $termo): TestResponse
    {
        return $this->actingAs($this->admin())
            ->get(route('projects.index', ['q' => $termo]));
    }

    public function test_busca_pelo_nome_da_empresa_ignora_caixa(): void
    {
        $this->buscar('acme')->assertInertia(fn (Assert $p) => $p
            ->component('Projects/Index')
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $this->projeto->id)
            ->where('projects.data.0.company', 'ACME'));
    }

    public function test_busca_pelo_nome_do_projeto_ignora_caixa(): void
    {
        $this->buscar('BOAS-VINDAS')->assertInertia(fn (Assert $p) => $p
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $this->projeto->id));
    }

    public function test_busca_pelo_kit_e_pelo_pedido_ignora_caixa(): void
    {
        $this->buscar('MOCHILA exec')->assertInertia(fn (Assert $p) => $p
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $this->projeto->id));

        $this->buscar('ped-9001')->assertInertia(fn (Assert $p) => $p
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $this->projeto->id));
    }

    public function test_termo_sem_correspondencia_devolve_lista_vazia(): void
    {
        $this->buscar('inexistente')->assertInertia(fn (Assert $p) => $p
            ->has('projects.data', 0)
            ->where('projects.total', 0));
    }
}
