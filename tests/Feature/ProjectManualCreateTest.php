<?php

namespace Tests\Feature;

use App\Mail\AccountInvitationMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Services\FluxoA\CreateTrelloCardForProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Cadastro manual de Projeto (fallback Omie).
 *
 * O Projeto não nasce sozinho: junto com ele nasce (ou se completa) a ficha do
 * cliente. Endereço é para onde a nota vai, e Admin Cliente é quem opera a
 * empresa do outro lado — sem os dois, sobrava um cliente pela metade que
 * ninguém voltava para completar.
 */
class ProjectManualCreateTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();

        // O card do Trello é assunto de outro teste; aqui só interessa a empresa.
        $this->mock(CreateTrelloCardForProject::class)->shouldReceive('handle');

        $this->product = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit 1', 'is_kit' => true, 'active' => true]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    private const ENDERECO = [
        'zip' => '80010-000',
        'street' => 'Rua XV de Novembro',
        'number' => '100',
        'district' => 'Centro',
        'city' => 'Curitiba',
        'state' => 'PR',
    ];

    private const ADMIN = [
        'name' => 'Clara Responsável',
        'email' => 'clara@nova-empresa.com.br',
    ];

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Nova Empresa Ltda',
            'company_cnpj' => '11.222.333/0001-81',
            'name' => 'Projeto Teste',
            'quantity' => 10,
            'product_ids' => [$this->product->id],
            'company' => self::ENDERECO,
            'admin' => self::ADMIN,
        ], $overrides);
    }

    /** Empresa existente e já completa: o Projeto não pede admin de novo. */
    private function empresaCompleta(string $nome = 'ACME Original'): Company
    {
        $company = Company::create(['name' => $nome, 'cnpj' => '11222333000181'] + self::ENDERECO);

        User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $company->id,
        ]);

        return $company;
    }

    public function test_cnpj_inexistente_cadastra_a_empresa_junto_com_o_projeto(): void
    {
        $this->assertDatabaseMissing('companies', ['cnpj' => '11222333000181']);

        $this->actingAs($this->admin())
            ->post(route('projects.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Nova Empresa Ltda'));

        $company = Company::where('cnpj', '11222333000181')->first();
        $this->assertNotNull($company);
        $this->assertSame('Nova Empresa Ltda', $company->name);
        $this->assertSame($company->id, Project::where('name', 'Projeto Teste')->value('company_id'));

        // A ficha vem inteira: endereço e responsável, com o convite enviado.
        $this->assertSame('Curitiba', $company->city);
        $this->assertSame('80010000', $company->zip);
        $this->assertDatabaseHas('users', [
            'email' => 'clara@nova-empresa.com.br',
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $company->id,
        ]);
        Mail::assertQueued(AccountInvitationMail::class);
    }

    public function test_cnpj_existente_vincula_a_empresa_sem_duplicar(): void
    {
        $existing = $this->empresaCompleta();

        $this->actingAs($this->admin())
            ->post(route('projects.store'), $this->payload([
                'company_name' => 'ACME digitada diferente',
                'admin' => null,
            ]))
            ->assertRedirect();

        $this->assertSame(1, Company::where('cnpj', '11222333000181')->count());
        $this->assertSame('ACME Original', $existing->fresh()->name);
        $this->assertSame($existing->id, Project::where('name', 'Projeto Teste')->value('company_id'));
    }

    public function test_empresa_existente_com_nome_placeholder_ganha_a_razao_social_digitada(): void
    {
        // Sync Omie/appnoz cria a empresa com o documento no lugar do nome
        // quando o ERP não devolve a razão social. O cadastro manual é a
        // primeira vez que alguém digita o nome de verdade: ele assume.
        $existing = Company::create(['name' => '11222333000181', 'cnpj' => '11222333000181']);

        $this->actingAs($this->admin())
            ->post(route('projects.store'), $this->payload(['company_name' => '  Nova Empresa Ltda  ']))
            ->assertRedirect();

        $this->assertSame(1, Company::where('cnpj', '11222333000181')->count());
        $this->assertSame('Nova Empresa Ltda', $existing->fresh()->name);
    }

    public function test_empresa_existente_com_cnpj_mascarado_no_nome_tambem_ganha_o_digitado(): void
    {
        $existing = Company::create(['name' => '11.222.333/0001-81', 'cnpj' => '11222333000181']);

        $this->actingAs($this->admin())
            ->post(route('projects.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame('Nova Empresa Ltda', $existing->fresh()->name);
    }

    public function test_empresa_existente_com_nome_real_nao_e_sobrescrita(): void
    {
        $existing = Company::create(['name' => 'ACME Original', 'cnpj' => '11222333000181']);

        $this->actingAs($this->admin())
            ->post(route('projects.store'), $this->payload(['company_name' => 'Tentativa de renomear']))
            ->assertRedirect();

        $this->assertSame('ACME Original', $existing->fresh()->name);
    }

    public function test_cnpj_invalido_e_recusado(): void
    {
        $this->actingAs($this->admin())
            ->from(route('projects.create'))
            ->post(route('projects.store'), $this->payload(['company_cnpj' => '00.000.000/0000-00']))
            ->assertRedirect(route('projects.create'))
            ->assertSessionHasErrors('company_cnpj');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_cpf_valido_e_aceito_como_documento(): void
    {
        $this->actingAs($this->admin())
            ->post(route('projects.store'), $this->payload(['company_cnpj' => '529.982.247-25']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['cnpj' => '52998224725']);
    }

    /**
     * Empresa não se conclui sem responsável: sem Admin Cliente, o Projeto
     * nasce e fica esperando alguém da WeGift perceber e cadastrar depois.
     */
    public function test_empresa_nova_sem_admin_cliente_e_recusada(): void
    {
        $this->actingAs($this->admin())
            ->from(route('projects.create'))
            ->post(route('projects.store'), $this->payload(['admin' => null]))
            ->assertSessionHasErrors('admin.name');

        $this->assertDatabaseCount('projects', 0);
    }

    /** Sem endereço não há para onde despachar nem para quem faturar. */
    public function test_empresa_sem_endereco_e_recusada(): void
    {
        $this->actingAs($this->admin())
            ->from(route('projects.create'))
            ->post(route('projects.store'), $this->payload([
                'company' => ['zip' => '', 'street' => '', 'number' => '', 'district' => '', 'city' => '', 'state' => ''],
            ]))
            ->assertSessionHasErrors(['company.zip', 'company.street', 'company.city']);

        $this->assertDatabaseCount('projects', 0);
    }

    /**
     * Empresa que já existe mas veio pela metade da Omie ganha o endereço
     * conferido na tela — é o dado mais fresco que alguém acabou de checar.
     */
    public function test_empresa_existente_incompleta_recebe_o_endereco_digitado(): void
    {
        $existing = Company::create(['name' => 'ACME Original', 'cnpj' => '11222333000181']);

        $this->actingAs($this->admin())
            ->post(route('projects.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $existing->refresh();
        $this->assertSame('Rua XV de Novembro', $existing->street);
        $this->assertSame('PR', $existing->state);
        // Nome real continua intocável: a ficha dela é editada em Clientes.
        $this->assertSame('ACME Original', $existing->name);
    }

    /** Segundo Admin Cliente não nasce daqui: a ficha do Cliente é o lugar. */
    public function test_empresa_que_ja_tem_admin_recusa_um_segundo(): void
    {
        $this->empresaCompleta();

        $this->actingAs($this->admin())
            ->from(route('projects.create'))
            ->post(route('projects.store'), $this->payload())
            ->assertSessionHasErrors('admin.email');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_email_ja_usado_por_outra_conta_e_recusado(): void
    {
        $existente = User::factory()->create(['role' => User::ROLE_END_CUSTOMER]);

        $this->actingAs($this->admin())
            ->from(route('projects.create'))
            ->post(route('projects.store'), $this->payload([
                'admin' => ['name' => 'Repetida', 'email' => $existente->email],
            ]))
            ->assertSessionHasErrors('admin.email');
    }

    /** @return array{0: Product, 1: Product, 2: Product} pai, tamanho G, tamanho M */
    private function grade(): array
    {
        return [
            Product::create(['omie_code' => 'COLETE', 'name' => 'Colete', 'active' => true]),
            Product::create(['omie_code' => 'COLETE_1', 'name' => 'Colete G', 'active' => true]),
            Product::create(['omie_code' => 'COLETE_2', 'name' => 'Colete M', 'active' => true]),
        ];
    }

    public function test_formulario_lista_so_o_pai_da_grade(): void
    {
        [$pai] = $this->grade();

        $this->actingAs($this->admin())
            ->get(route('projects.create'))
            ->assertInertia(fn ($page) => $page
                ->where('products', fn ($produtos) => collect($produtos)->pluck('omie_code')->sort()->values()->all() === ['COLETE', 'KIT-1'])
                ->where('products', fn ($produtos) => collect($produtos)->firstWhere('id', $pai->id)['grade'] === true));
    }

    public function test_tamanhos_marcados_viram_o_pai_com_a_quantidade_uma_vez_so(): void
    {
        [$pai, $g, $m] = $this->grade();

        $this->actingAs($this->admin())
            ->post(route('projects.store'), $this->payload([
                'quantity' => 9000,
                'product_ids' => [$g->id, $m->id],
            ]))
            ->assertRedirect();

        $projeto = Project::where('name', 'Projeto Teste')->firstOrFail();

        // 9.000 coletes, não 9.000 de cada tamanho.
        $this->assertSame(
            [$pai->id => 9000],
            $projeto->products->mapWithKeys(fn (Product $p) => [$p->id => (int) $p->pivot->quantity])->all(),
        );
    }
}
