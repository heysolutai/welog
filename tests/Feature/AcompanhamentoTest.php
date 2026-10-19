<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Acompanhamento: a jornada por PESSOA (cadastrados, faltam resgatar, em
 * separação, a caminho, entregues) e a lista de quem está em cada etapa.
 *
 * É a situação de hoje, sem período, e cada um vê só o próprio recorte.
 */
class AcompanhamentoTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    private Product $polo;

    private Product $caneca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '11111111000191']);
        $this->projeto = Project::create(['company_id' => $this->empresa->id, 'name' => '85 anos', 'quantity' => 10]);
        $this->polo = Product::create(['omie_code' => 'POLO', 'name' => 'Polo']);
        $this->caneca = Product::create(['omie_code' => 'CANECA', 'name' => 'Caneca']);

        $this->pessoa('Ana Falta');
        $this->pessoa('Bia Separacao', [Redemption::STATUS_CONFIRMED]);
        $this->pessoa('Caio Caminho', [Redemption::STATUS_SHIPPED]);
        $this->pessoa('Duda Entregue', [Redemption::STATUS_DELIVERED]);
        // Cancelado reabre a autorização: volta para "faltam resgatar".
        $this->pessoa('Edu Cancelado', [Redemption::STATUS_CANCELED]);
        // Dois itens: só é entregue quando tudo chegou; fica no mais atrasado.
        $this->pessoa('Fabi Dois Itens', [Redemption::STATUS_DELIVERED, Redemption::STATUS_SHIPPED]);

        // Outra empresa: nunca aparece para o Admin Cliente da Sotreq.
        $outra = Company::create(['name' => 'Outra', 'cnpj' => '22222222000191']);
        $deFora = Project::create(['company_id' => $outra->id, 'name' => 'De fora']);
        $this->pessoa('Zeca De Fora', [], $deFora);
    }

    /** @param  array<int, string>  $resgates  status de cada item resgatado */
    private function pessoa(string $nome, array $resgates = [], ?Project $projeto = null): User
    {
        $projeto ??= $this->projeto;
        $user = User::factory()->create([
            'name' => $nome, 'role' => User::ROLE_END_CUSTOMER, 'company_id' => $projeto->company_id,
        ]);

        foreach ([$this->polo, $this->caneca] as $produto) {
            ProjectEntitlement::create(['project_id' => $projeto->id, 'user_id' => $user->id, 'product_id' => $produto->id]);
        }

        foreach (array_values($resgates) as $i => $status) {
            Redemption::create([
                'project_id' => $projeto->id, 'user_id' => $user->id,
                'product_id' => [$this->polo, $this->caneca][$i]->id,
                'status' => $status, 'recipient_name' => $nome, 'zip' => '80010000',
                'street' => 'Rua', 'number' => '1', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
                'tracking_code' => $status === Redemption::STATUS_SHIPPED ? 'BR1'.$i : null,
            ]);
        }

        return $user;
    }

    private function adminCliente(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->empresa->id]);
    }

    public function test_o_funil_conta_pessoas_e_nao_resgates(): void
    {
        $this->actingAs($this->adminCliente())
            ->get(route('reports.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('funil.total', 6)
                ->where('funil.por_situacao', [
                    'falta' => 2, 'separacao' => 1, 'caminho' => 2, 'entregue' => 1,
                ]));
    }

    /** Filtrado no Projeto, a tela sabe para onde o "Voltar" leva. */
    public function test_com_projeto_escolhido_o_voltar_conhece_o_projeto(): void
    {
        $this->actingAs($this->adminCliente())
            ->get(route('reports.index', ['project' => $this->projeto->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projetoAtual', ['id' => $this->projeto->id, 'name' => '85 anos']));
    }

    /** Pelo menu, sem filtro: com um Projeto só na empresa, o "Voltar" já sabe qual é. */
    public function test_empresa_com_um_projeto_tem_voltar_mesmo_sem_filtro(): void
    {
        $this->actingAs($this->adminCliente())
            ->get(route('reports.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projetoAtual', ['id' => $this->projeto->id, 'name' => '85 anos']));

        Project::create(['company_id' => $this->empresa->id, 'name' => 'Outra campanha']);

        $this->actingAs($this->adminCliente())
            ->get(route('reports.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projetoAtual', null));
    }

    /** Vinte por página: oito mil pessoas não descem de uma vez. */
    public function test_a_lista_vem_de_vinte_em_vinte(): void
    {
        foreach (range(1, 21) as $i) {
            $this->pessoa(sprintf('Pessoa %02d', $i));
        }

        $admin = $this->adminCliente();

        $this->actingAs($admin)
            ->getJson(route('reports.people', ['situacao' => 'falta']))
            ->assertJsonPath('total', 23)
            ->assertJsonPath('paginas', 2)
            ->assertJsonCount(20, 'pessoas');

        $this->actingAs($admin)
            ->getJson(route('reports.people', ['situacao' => 'falta', 'pagina' => 2]))
            ->assertJsonCount(3, 'pessoas');
    }

    public function test_a_lista_traz_quem_falta_resgatar(): void
    {
        $resposta = $this->actingAs($this->adminCliente())
            ->getJson(route('reports.people', ['situacao' => 'falta']))
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->assertSame(['Ana Falta', 'Edu Cancelado'], array_column($resposta->json('pessoas'), 'nome'));
    }

    public function test_a_lista_mostra_os_itens_e_o_rastreio(): void
    {
        $fabi = $this->actingAs($this->adminCliente())
            ->getJson(route('reports.people', ['situacao' => 'caminho', 'busca' => 'fabi']))
            ->assertOk()
            ->json('pessoas.0');

        $this->assertSame('Fabi Dois Itens', $fabi['nome']);
        $this->assertSame(['Polo', 'Caneca'], $fabi['itens']);
        $this->assertSame('BR11', $fabi['rastreios'][0]['codigo']);
    }

    public function test_admin_cliente_nao_ve_pessoas_de_outra_empresa(): void
    {
        $nomes = array_column(
            $this->actingAs($this->adminCliente())->getJson(route('reports.people'))->json('pessoas'),
            'nome',
        );

        $this->assertNotContains('Zeca De Fora', $nomes);
        $this->assertCount(6, $nomes);
    }

    public function test_baixar_a_lista_traz_todas_as_pessoas_da_etapa(): void
    {
        $csv = $this->actingAs($this->adminCliente())
            ->get(route('reports.people', ['situacao' => 'falta', 'formato' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ana Falta', $csv);
        $this->assertStringContainsString('Edu Cancelado', $csv);
        $this->assertStringContainsString('Faltam resgatar', $csv);
        $this->assertStringNotContainsString('Bia Separacao', $csv);
    }

    /**
     * A exportação geral: TODAS as pessoas cadastradas, uma linha cada, tenham
     * resgatado ou não. Quem resgatou leva o endereço; quem não, vem em branco.
     */
    public function test_exportacao_geral_traz_todos_os_cadastrados_com_endereco(): void
    {
        $csv = $this->actingAs($this->adminCliente())
            ->get(route('reports.people', ['formato' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $linhas = array_values(array_filter(explode("\n", trim($csv))));
        $cabecalho = str_getcsv(ltrim($linhas[0], "\u{FEFF}"), ';');

        foreach (['CPF', 'Telefone', 'Destinatário', 'CEP', 'Endereço', 'Bairro', 'Cidade', 'UF'] as $coluna) {
            $this->assertContains($coluna, $cabecalho);
        }

        // Seis pessoas da Sotreq, com e sem resgate; a de outra empresa não.
        $this->assertCount(7, $linhas);
        $this->assertStringNotContainsString('Zeca De Fora', $csv);

        $porNome = collect(array_slice($linhas, 1))
            ->map(fn ($l) => array_combine($cabecalho, str_getcsv($l, ';')))
            ->keyBy('Pessoa');

        $this->assertSame('Rua, 1', $porNome['Bia Separacao']['Endereço']);
        $this->assertSame('80010-000', $porNome['Bia Separacao']['CEP']);
        $this->assertSame('Curitiba', $porNome['Bia Separacao']['Cidade']);
        $this->assertSame('', $porNome['Ana Falta']['Endereço']);
        $this->assertSame('Faltam resgatar', $porNome['Ana Falta']['Situação']);
    }
}
