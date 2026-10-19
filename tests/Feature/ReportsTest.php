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
 * Relatórios (inclusão UAT): resumo em tela + CSVs de resgates e Projetos.
 *
 * O que importa aqui é o RECORTE por papel — WeGift vê tudo (N2 incluso),
 * Admin Cliente só a empresa dele, Admin de Projeto só o Projeto dele — e o
 * formato do arquivo: BOM UTF-8, ponto e vírgula, rótulos e datas pt-BR.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresaA;

    private Company $empresaB;

    private Project $projetoA;

    private Project $projetoB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        // Empresa Alfa: Projeto em Design com um resgate confirmado hoje.
        $this->empresaA = Company::create(['name' => 'Empresa Alfa', 'cnpj' => '11111111000191']);
        $this->projetoA = Project::create([
            'company_id' => $this->empresaA->id,
            'name' => 'Projeto Alfa',
            'omie_order_number' => 'PV-100',
            'client_order_number' => 'OC-9',
            'pipeline_status' => Project::STATUS_DESIGN,
            'ordered_at' => today()->subDays(10),
            'delivery_deadline' => today()->addDays(20),
            'quantity' => 10,
        ]);

        $kitA = Product::create(['omie_code' => 'KIT-ALFA', 'name' => 'Kit Alfa']);
        $ana = User::create([
            'name' => 'Ana Cliente', 'email' => 'ana@alfa.com.br', 'password' => 'x',
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresaA->id,
            'cpf' => '52998224725', 'active' => true,
        ]);
        ProjectEntitlement::create([
            'project_id' => $this->projetoA->id, 'user_id' => $ana->id, 'product_id' => $kitA->id,
        ]);
        $this->resgate($this->projetoA, $ana, $kitA, Redemption::STATUS_CONFIRMED);

        // Empresa Beta: Projeto liberado, com um resgate entregue hoje e um
        // expedido há 60 dias (fora da janela padrão de 30 dias).
        $this->empresaB = Company::create(['name' => 'Empresa Beta', 'cnpj' => '22222222000191']);
        $this->projetoB = Project::create([
            'company_id' => $this->empresaB->id,
            'name' => 'Projeto Beta',
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now()->subDays(6),
            'released_at' => now()->subDays(5),
            'quantity' => 5,
        ]);

        $kitB = Product::create(['omie_code' => 'KIT-BETA', 'name' => 'Kit Beta']);
        $bia = User::create([
            'name' => 'Bia Beta', 'email' => 'bia@beta.com.br', 'password' => 'x',
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresaB->id, 'active' => true,
        ]);

        $this->resgate($this->projetoB, $bia, $kitB, Redemption::STATUS_DELIVERED, [
            'tracking_code' => 'BB-123', 'delivered_at' => now(),
        ]);

        $antigo = $this->resgate($this->projetoB, $bia, $kitB, Redemption::STATUS_SHIPPED, [
            'tracking_code' => 'OLD-123',
        ]);
        $antigo->created_at = now()->subDays(60);
        $antigo->save();
    }

    private function resgate(Project $projeto, User $pessoa, Product $kit, string $status, array $extras = []): Redemption
    {
        return Redemption::create(array_merge([
            'project_id' => $projeto->id, 'user_id' => $pessoa->id, 'product_id' => $kit->id,
            'status' => $status,
            'recipient_name' => $pessoa->name, 'zip' => '80010000', 'street' => 'Rua XV',
            'number' => '10', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ], $extras));
    }

    /** N2 é consulta: relatório faz parte do que ele pode ver. */
    private function operadorN2(): User
    {
        return User::where('email', 'consulta@welog.local')->firstOrFail();
    }

    private function adminCliente(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->empresaA->id,
        ]);
    }

    private function adminDeProjeto(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $this->empresaA->id,
            'admin_project_id' => $this->projetoA->id,
        ]);
    }

    public function test_wegift_ve_o_resumo_de_todas_as_empresas(): void
    {
        $this->actingAs($this->operadorN2())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Reports/Index')
                // O resgate de 60 dias atrás fica fora da janela padrão de 30.
                ->where('resumo.resgates.total', 2)
                ->where('resumo.resgates.por_status.confirmed', 1)
                ->where('resumo.resgates.por_status.delivered', 1)
                ->where('resumo.resgates.por_status.shipped', 0)
                ->where('resumo.projetos.total', 2)
                ->where('resumo.projetos.por_etapa.design', 1)
                ->where('resumo.projetos.por_etapa.in_stock', 1)
                ->has('empresas', 2)
                ->where('escopoFixo.empresa', null));
    }

    public function test_admin_cliente_sai_escopado_a_propria_empresa(): void
    {
        $this->actingAs($this->adminCliente())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('resumo.resgates.total', 1)
                ->where('resumo.resgates.por_status.confirmed', 1)
                ->where('resumo.resgates.por_status.delivered', 0)
                ->where('resumo.projetos.total', 1)
                ->where('resumo.projetos.por_etapa.design', 1)
                // Empresa fixa: não há select de empresas para o cliente.
                ->where('empresas', null)
                ->where('escopoFixo.empresa', 'Empresa Alfa')
                ->has('projetos', 1));
    }

    public function test_admin_de_projeto_sai_cravado_no_proprio_projeto(): void
    {
        // Mesmo pedindo outro Projeto na querystring, o escopo não muda.
        $this->actingAs($this->adminDeProjeto())
            ->get(route('reports.index', ['project' => $this->projetoB->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('resumo.resgates.total', 1)
                ->where('resumo.projetos.total', 1)
                ->where('resumo.projetos.por_etapa.design', 1)
                ->where('resumo.projetos.por_etapa.in_stock', 0)
                ->where('projetos', null)
                ->where('escopoFixo.empresa', 'Empresa Alfa')
                ->where('escopoFixo.projeto', 'Projeto Alfa'));
    }

    public function test_cliente_final_recebe_403_nas_tres_rotas(): void
    {
        $cliente = User::where('email', 'ana@alfa.com.br')->firstOrFail();

        $this->actingAs($cliente)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($cliente)->get(route('reports.redemptions'))->assertForbidden();
        $this->actingAs($cliente)->get(route('reports.projects'))->assertForbidden();
    }

    public function test_visitante_e_redirecionado_para_o_login(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_csv_de_resgates_sai_com_bom_separador_e_rotulos_pt_br(): void
    {
        $resposta = $this->actingAs($this->operadorN2())
            ->get(route('reports.redemptions'))
            ->assertOk()
            ->assertDownload('resgates-'.now()->format('Y-m-d').'.csv');

        $conteudo = $resposta->streamedContent();

        // BOM UTF-8: sem ele o Excel pt-BR estraga os acentos.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $conteudo);

        // O fputcsv cita campos com espaço — o Excel lê igual, o teste espelha.
        $this->assertStringContainsString(
            'Projeto;"Pedido Omie";Empresa;Pessoa;E-mail;CPF;Item;Cor/Tamanho;Qtde;Status;"Data do resgate";"Código de rastreio";"Entregue em"',
            $conteudo,
        );

        // Resgate confirmado: CPF mascarado, rótulo pt-BR, data dd/mm/yyyy.
        $this->assertStringContainsString(
            '"Projeto Alfa";PV-100;"Empresa Alfa";"Ana Cliente";ana@alfa.com.br;529.982.247-25;"Kit Alfa";;1;Confirmado;'.now()->format('d/m/Y').';;',
            $conteudo,
        );

        // Resgate entregue: rastreio e data de entrega preenchidos.
        $this->assertStringContainsString(
            '"Bia Beta";bia@beta.com.br;;"Kit Beta";;1;Entregue;'.now()->format('d/m/Y').';BB-123;'.now()->format('d/m/Y'),
            $conteudo,
        );
    }

    public function test_csv_de_resgates_respeita_o_periodo(): void
    {
        // Janela padrão (30 dias): o resgate de 60 dias atrás fica de fora.
        $padrao = $this->actingAs($this->operadorN2())
            ->get(route('reports.redemptions'))
            ->streamedContent();

        $this->assertStringNotContainsString('OLD-123', $padrao);

        // Alargando o período, ele entra.
        $largo = $this->actingAs($this->operadorN2())
            ->get(route('reports.redemptions', [
                'from' => now()->subDays(90)->format('Y-m-d'),
                'to' => now()->format('Y-m-d'),
            ]))
            ->streamedContent();

        $this->assertStringContainsString('OLD-123', $largo);
        $this->assertStringContainsString('Em transporte', $largo);
    }

    /** Pontas trocadas não estouram 422: o período é normalizado. */
    public function test_periodo_invertido_e_tolerado(): void
    {
        $this->actingAs($this->operadorN2())
            ->get(route('reports.index', [
                'from' => now()->format('Y-m-d'),
                'to' => now()->subDays(90)->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                // Mesmo recorte dos 90 dias na ordem certa: os 3 resgates entram.
                ->where('resumo.resgates.total', 3)
                ->where('filtros.from', now()->subDays(90)->format('Y-m-d'))
                ->where('filtros.to', now()->format('Y-m-d')));
    }

    public function test_csv_de_resgates_do_admin_cliente_nao_vaza_outra_empresa(): void
    {
        $conteudo = $this->actingAs($this->adminCliente())
            ->get(route('reports.redemptions'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ana Cliente', $conteudo);
        $this->assertStringNotContainsString('Bia Beta', $conteudo);
        $this->assertStringNotContainsString('Empresa Beta', $conteudo);
    }

    public function test_csv_de_projetos_traz_etapas_e_contagens(): void
    {
        $resposta = $this->actingAs($this->operadorN2())
            ->get(route('reports.projects'))
            ->assertOk()
            ->assertDownload('projetos-'.now()->format('Y-m-d').'.csv');

        $conteudo = $resposta->streamedContent();

        // O fputcsv cita campos com espaço — o Excel lê igual, o teste espelha.
        $this->assertStringContainsString(
            'Projeto;"Pedido Omie";"Nº pedido do cliente";Empresa;Etapa;"Data da compra";"Prazo de entrega";"Liberado em";"Usuários cadastrados";Resgates;Entregues',
            $conteudo,
        );

        // Projeto Alfa: etapa pt-BR, datas dd/mm/yyyy, 1 Usuário e 1 resgate.
        $this->assertStringContainsString(implode(';', [
            '"Projeto Alfa"', 'PV-100', 'OC-9', '"Empresa Alfa"', 'Design',
            today()->subDays(10)->format('d/m/Y'), today()->addDays(20)->format('d/m/Y'), '',
            '1', '1', '0',
        ]), $conteudo);

        // Projeto Beta: liberado, com 2 resgates ativos e 1 entrega.
        $this->assertStringContainsString(implode(';', [
            '"Projeto Beta"', '', '', '"Empresa Beta"', '"Estoque: pronto para resgate/envio"',
            '', '', now()->subDays(5)->format('d/m/Y'),
            '0', '2', '1',
        ]), $conteudo);
    }

    public function test_csv_de_projetos_do_admin_de_projeto_traz_so_o_projeto_dele(): void
    {
        $conteudo = $this->actingAs($this->adminDeProjeto())
            ->get(route('reports.projects'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Projeto Alfa', $conteudo);
        $this->assertStringNotContainsString('Projeto Beta', $conteudo);
    }

    /** Atalho "tudo" alcança o resgate de 60 dias atrás; "7 dias", não. */
    public function test_atalhos_de_periodo(): void
    {
        $this->actingAs($this->operadorN2())
            ->get(route('reports.index', ['periodo' => 'tudo']))
            ->assertInertia(fn (Assert $p) => $p
                ->where('filtros.periodo', 'tudo')
                ->where('resumo.resgates.total', 3));

        $this->actingAs($this->operadorN2())
            ->get(route('reports.index', ['periodo' => '7']))
            ->assertInertia(fn (Assert $p) => $p
                ->where('filtros.periodo', '7')
                ->where('filtros.from', today()->subDays(7)->format('Y-m-d'))
                ->where('resumo.resgates.total', 2));
    }

    /** O andamento dos Projetos respeita o recorte do cliente. */
    public function test_andamento_do_admin_cliente(): void
    {
        $this->actingAs($this->adminCliente())
            ->get(route('reports.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->has('atualizado_em')
                ->has('andamento', 1)
                ->where('andamento.0.nome', 'Projeto Alfa')
                ->where('andamento.0.pessoas', 1)
                ->where('andamento.0.resgates', 1));
    }

    /** Excel de verdade: um .xlsx (zip), com as mesmas linhas do CSV. */
    public function test_resgates_saem_em_planilha_do_excel(): void
    {
        $resposta = $this->actingAs($this->operadorN2())
            ->get(route('reports.redemptions', ['formato' => 'xlsx']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString('.xlsx', $resposta->headers->get('content-disposition'));

        $arquivo = $resposta->baseResponse->getFile()->getPathname();
        $this->assertSame("PK\x03\x04", file_get_contents($arquivo, length: 4));

        $leitor = new \OpenSpout\Reader\XLSX\Reader;
        $leitor->open($arquivo);
        $linhas = [];
        foreach ($leitor->getSheetIterator() as $aba) {
            foreach ($aba->getRowIterator() as $linha) {
                $linhas[] = $linha->toArray();
            }
        }
        $leitor->close();

        $this->assertSame('Projeto', $linhas[0][0]);
        $this->assertCount(3, $linhas); // cabeçalho + 2 resgates da janela padrão
    }

    public function test_formato_desconhecido_e_recusado(): void
    {
        $this->actingAs($this->operadorN2())
            ->get(route('reports.projects', ['formato' => 'pdf']))
            ->assertSessionHasErrors('formato');
    }

    public function test_admin_cliente_nao_filtra_projeto_de_outra_empresa(): void
    {
        $this->actingAs($this->adminCliente())
            ->get(route('reports.index', ['project' => $this->projetoB->id]))
            ->assertForbidden();

        $this->actingAs($this->adminCliente())
            ->get(route('reports.redemptions', ['project' => $this->projetoB->id]))
            ->assertForbidden();
    }
}
