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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DELETE /projects/{project}/colaboradores/{user}: tirar a pessoa do Projeto
 * numa chamada só, com todas as autorizações dela (uma por item ou tamanho).
 *
 * A tela fazia uma chamada por autorização, em fila: vinte e tantas recargas
 * da página e o mesmo aviso repetido.
 */
class CollaboratorRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    /** @var array<int, Product> */
    private array $tamanhos = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();

        $this->empresa = Company::create(['name' => 'Sotreq S.A.', 'cnpj' => '12345678000190']);
        $this->projeto = Project::create(['company_id' => $this->empresa->id, 'name' => 'Sotreq 85 anos', 'quantity' => 30]);

        foreach (['_1', '_2', '_3'] as $sufixo) {
            $this->tamanhos[] = $produto = Product::create(['omie_code' => 'JAQ'.$sufixo, 'name' => 'Jaqueta'.$sufixo]);
            $this->projeto->products()->attach($produto->id, ['quantity' => 10]);
        }
    }

    private function pessoaComTodosOsTamanhos(?Project $projeto = null): User
    {
        $pessoa = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresa->id]);
        $grupo = (string) Str::uuid();

        foreach ($this->tamanhos as $produto) {
            ProjectEntitlement::create([
                'project_id' => ($projeto ?? $this->projeto)->id, 'user_id' => $pessoa->id,
                'product_id' => $produto->id, 'choice_group' => $grupo,
            ]);
        }

        return $pessoa;
    }

    private function remover(User $pessoa, ?User $quem = null)
    {
        return $this->actingAs($quem ?? User::where('email', 'admin@welog.local')->firstOrFail())
            ->delete(route('projects.collaborators.destroy', [$this->projeto, $pessoa]));
    }

    /** Sem outro Projeto, saem todas as autorizações, o resgate e a conta. */
    public function test_uma_chamada_tira_tudo_e_apaga_a_conta(): void
    {
        $pessoa = $this->pessoaComTodosOsTamanhos();
        Redemption::create([
            'project_id' => $this->projeto->id, 'user_id' => $pessoa->id, 'product_id' => $this->tamanhos[0]->id,
            'status' => 'confirmed', 'recipient_name' => $pessoa->name, 'zip' => '80010000',
            'street' => 'Rua', 'number' => '1', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);

        $this->remover($pessoa)->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertModelMissing($pessoa);
        $this->assertSame(0, ProjectEntitlement::where('user_id', $pessoa->id)->count());
        $this->assertSame(0, Redemption::where('user_id', $pessoa->id)->count());
    }

    /** Em outro Projeto, a conta fica e só as autorizações DESTE saem. */
    public function test_com_outro_projeto_a_conta_fica(): void
    {
        $outro = Project::create(['company_id' => $this->empresa->id, 'name' => 'Outra campanha']);
        $pessoa = $this->pessoaComTodosOsTamanhos();
        ProjectEntitlement::create(['project_id' => $outro->id, 'user_id' => $pessoa->id, 'product_id' => $this->tamanhos[0]->id]);

        $this->remover($pessoa)->assertSessionHasNoErrors();

        $this->assertModelExists($pessoa);
        $this->assertSame(0, ProjectEntitlement::where('project_id', $this->projeto->id)->where('user_id', $pessoa->id)->count());
        $this->assertSame(1, ProjectEntitlement::where('project_id', $outro->id)->where('user_id', $pessoa->id)->count());
    }

    /** Admin Cliente de outra empresa não tira ninguém daqui. */
    public function test_admin_de_outra_empresa_nao_remove(): void
    {
        $outra = Company::create(['name' => 'Outra', 'cnpj' => '98765432000110']);
        $adminDeFora = User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $outra->id]);
        $pessoa = $this->pessoaComTodosOsTamanhos();

        $this->remover($pessoa, $adminDeFora)->assertForbidden();

        $this->assertSame(3, ProjectEntitlement::where('user_id', $pessoa->id)->count());
    }
}
