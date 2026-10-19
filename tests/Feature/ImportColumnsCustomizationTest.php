<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Services\FluxoA\BatchRegisterEndCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O Cliente escolhe quais colunas a planilha de importação usa.
 *
 * Desmarcar uma coluna quer dizer "não coletamos isso". Por isso o que estes
 * testes guardam não é só o modelo baixado, é a importação: coluna desmarcada
 * tem de ser ignorada mesmo quando vem preenchida numa planilha antiga.
 */
class ImportColumnsCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $empresa;

    private Project $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->admin = User::where('role', User::ROLE_ADMIN_WEGIFT)->firstOrFail();
        $this->empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);

        $kit = Product::create([
            'omie_code' => 'KIT-COLUNAS', 'name' => 'Kit', 'active' => true,
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);

        $this->projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => 'Campanha',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $this->projeto->products()->attach($kit->id, ['quantity' => 10]);
    }

    private function marcar(array $colunas)
    {
        return $this->actingAs($this->admin)
            ->put(route('admin.companies.import-columns.update', $this->empresa), ['columns' => $colunas]);
    }

    public function test_padrao_e_todas_marcadas(): void
    {
        $this->assertSame(['nome', 'email', 'funcao', 'cpf', 'telefone'], $this->empresa->colunasDaPlanilha());
        $this->assertSame(
            ['nome', 'email', 'funcao', 'cpf', 'telefone'],
            BatchRegisterEndCustomers::templateColumns($this->projeto),
        );
    }

    public function test_desmarcar_tira_a_coluna_do_modelo(): void
    {
        $this->marcar(['funcao', 'telefone'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['nome', 'email', 'funcao', 'telefone'],
            BatchRegisterEndCustomers::templateColumns($this->projeto->fresh()),
        );
    }

    /**
     * Nome e e-mail não saem, nem se o pedido disser que sim. Sem e-mail não
     * existe conta, e o cadastro exige o nome: toda linha seria recusada.
     */
    public function test_nome_e_email_nao_podem_ser_desmarcados(): void
    {
        $this->marcar([])->assertSessionHasNoErrors();

        $this->assertSame(['nome', 'email'], $this->empresa->fresh()->colunasDaPlanilha());
    }

    /**
     * O ponto que importa: CPF desmarcado não entra no cadastro, mesmo vindo
     * preenchido numa planilha antiga. "Não coletamos CPF" tem de valer para
     * quem ainda usa o modelo de antes.
     */
    public function test_coluna_desmarcada_e_ignorada_na_importacao(): void
    {
        $this->marcar(['funcao', 'telefone']);

        $this->actingAs($this->admin)
            ->post(route('projects.entitlements.batch', $this->projeto), [
                'file' => $this->planilha("nome,email,cpf,telefone\nAna,ana@sotreq.com.br,529.982.247-25,(11) 90000-0000\n"),
            ])
            ->assertSessionHasNoErrors();

        $ana = User::where('email', 'ana@sotreq.com.br')->firstOrFail();
        $this->assertNull($ana->cpf);
        // Telefone é gravado sempre como +55DDDNÚMERO.
        $this->assertSame('+5511900000000', $ana->phone);
    }

    /** Função desmarcada: todo mundo entra como Colaborador. */
    public function test_funcao_desmarcada_cadastra_todos_como_colaborador(): void
    {
        $this->marcar(['cpf', 'telefone']);

        $this->actingAs($this->admin)
            ->post(route('projects.entitlements.batch', $this->projeto), [
                'file' => $this->planilha("nome,email,funcao\nBeto,beto@sotreq.com.br,Admin Projeto\n"),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(User::ROLE_END_CUSTOMER, User::where('email', 'beto@sotreq.com.br')->value('role'));
    }

    /**
     * Com vários itens, o modelo continua só com as colunas do Cliente: o que
     * cada pessoa recebe é escolhido na tela de importação.
     */
    public function test_modelo_nao_tem_coluna_de_item_mesmo_com_varios_itens(): void
    {
        $outro = Product::create([
            'omie_code' => 'KIT-DOIS', 'name' => 'Kit 2', 'active' => true,
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);
        $this->projeto->products()->attach($outro->id, ['quantity' => 5]);
        $this->marcar(['telefone']);

        $projeto = $this->projeto->fresh();

        $this->assertSame(['nome', 'email', 'telefone'], BatchRegisterEndCustomers::templateColumns($projeto));
        $this->assertCount(3, BatchRegisterEndCustomers::templateExample($projeto));
    }

    /** Desmarcar uma coluna não apaga as outras customizações do Cliente. */
    public function test_nao_apaga_as_outras_customizacoes(): void
    {
        $this->empresa->update(['customizations' => ['welog_sends_emails' => false]]);

        $this->marcar(['cpf']);

        $this->assertFalse($this->empresa->fresh()->customizacao('welog_sends_emails'));
    }

    public function test_a_tela_do_cliente_mostra_as_colunas(): void
    {
        $this->marcar(['cpf']);

        $this->actingAs($this->admin)
            ->get(route('admin.companies.show', $this->empresa))
            ->assertInertia(fn ($p) => $p
                ->where('company.import_columns', ['nome', 'email', 'cpf'])
                ->has('import_columns_catalog', 5));
    }

    private function planilha(string $csv): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, $csv);

        return new UploadedFile($tmp, 'colaboradores.csv', 'text/csv', null, true);
    }
}
