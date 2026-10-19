<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achar uma pessoa pelo e-mail, em qualquer papel, e saber ONDE ela está.
 *
 * A busca da tela de Clientes só enxergava razão social e CNPJ, então
 * procurar alguém pelo e-mail não devolvia nada — e às vezes devolvia a
 * empresa errada, porque um dígito solto do termo casava com o CNPJ.
 *
 * O caminho devolvido (empresa, e o Projeto quando houver) é o que permite à
 * árvore abrir já no lugar certo: achar e ainda ter de clicar três níveis
 * para chegar na pessoa é o mesmo que não ter achado.
 */
class ClientPeopleSearchTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->empresa = Company::create(['name' => 'Webox Presentes', 'cnpj' => '51785932000128']);
        $this->projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => '69848 - Projeto Teste',
            'quantity' => 5,
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);
    }

    private function buscar(string $termo)
    {
        $admin = User::where('email', 'admin@welog.local')->first();

        return $this->actingAs($admin)->getJson(route('admin.companies.people.search', ['q' => $termo]));
    }

    public function test_acha_colaborador_pelo_email_e_devolve_o_caminho(): void
    {
        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
            'email' => 'yagocandidom2@empresa.com.br',
        ]);

        $produto = Product::create(['omie_code' => 'PRD1', 'name' => 'Kit']);
        ProjectEntitlement::create([
            'project_id' => $this->projeto->id,
            'user_id' => $pessoa->id,
            'product_id' => $produto->id,
        ]);

        $resposta = $this->buscar('yagocandidom2')->assertSuccessful();

        $resposta->assertJsonPath('people.0.email', 'yagocandidom2@empresa.com.br');
        $resposta->assertJsonPath('people.0.role', 'Colaborador');
        $resposta->assertJsonPath('people.0.projects.0.id', $this->projeto->id);

        // O caminho que a árvore usa para abrir sozinha
        $this->assertSame([$this->empresa->id], $resposta->json('company_ids'));
        $this->assertSame([$this->projeto->id], $resposta->json('project_ids'));
    }

    public function test_acha_admin_empresas_pelo_email(): void
    {
        User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresa->id,
            'email' => 'rh@webox.com.br',
        ]);

        $resposta = $this->buscar('rh@webox')->assertSuccessful();

        $resposta->assertJsonPath('people.0.role', 'Admin Cliente');
        $this->assertSame([$this->empresa->id], $resposta->json('company_ids'));
        // Admin da empresa não é de Projeto nenhum: nada a expandir embaixo.
        $this->assertSame([], $resposta->json('project_ids'));
    }

    public function test_admin_de_projeto_traz_o_projeto_que_administra(): void
    {
        User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresa->id,
            'admin_project_id' => $this->projeto->id,
            'email' => 'coordenador@webox.com.br',
        ]);

        $resposta = $this->buscar('coordenador')->assertSuccessful();

        $resposta->assertJsonPath('people.0.role', 'Admin Projeto');
        $this->assertSame([$this->projeto->id], $resposta->json('project_ids'));
    }

    /** Termo curto casaria com quase tudo e traria a base inteira. */
    public function test_termo_curto_nao_busca(): void
    {
        User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresa->id]);

        $this->buscar('ya')->assertSuccessful()->assertJsonPath('people', []);
    }

    /** A equipe WeGift não é gente de cliente e não aparece nesta consulta. */
    public function test_nao_devolve_a_equipe_wegift(): void
    {
        $this->buscar('admin@welog')->assertSuccessful()->assertJsonPath('people', []);
    }
}
