<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Falha do UAT: a plataforma deixou habilitar (e resgatar) um ITEM DE DENTRO
 * do kit — o componente do BOM — quando o pedido só vendeu o kit completo.
 *
 * A regra: autorizável é somente o que está nas LINHAS do pedido
 * (project_products). Componente que só existe no BOM não entra; o mesmo
 * código sendo item do pedido E componente de um kit é o caso legítimo (o UAT
 * anterior tinha exatamente esse) e continua passando.
 */
class EntitlementOrderItemsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Product $kit;

    private Product $componenteSo;

    private Product $itemEComponente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Mail::fake();

        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->project = Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto Onboarding',
            'quantity' => 10,
        ]);

        $this->kit = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit Completo', 'is_kit' => true]);
        // Componente que SÓ existe dentro do kit (a caneca da falha do UAT)
        $this->componenteSo = Product::create(['omie_code' => 'COMP-CANECA', 'name' => 'Caneca do Kit']);
        // Item que é linha do pedido E TAMBÉM componente do kit (caso legítimo)
        $this->itemEComponente = Product::create(['omie_code' => 'MOCHILA-15', 'name' => 'Mochila Avulsa']);

        $this->kit->components()->attach([
            $this->componenteSo->id => ['quantity' => 1],
            $this->itemEComponente->id => ['quantity' => 1],
        ]);

        // Linhas do PEDIDO: o kit e a mochila avulsa. A caneca não.
        $this->project->products()->attach([
            $this->kit->id => ['quantity' => 10],
            $this->itemEComponente->id => ['quantity' => 5],
        ]);
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    public function test_componente_que_so_existe_no_bom_do_kit_e_recusado(): void
    {
        $this->actingAs($this->operador())
            ->post(route('projects.entitlements.store', $this->project), [
                'name' => 'João Colaborador',
                'email' => 'joao@acme.com.br',
                'product_id' => $this->componenteSo->id,
            ])
            ->assertSessionHasErrors('entitlement');

        $erro = session('errors')->get('entitlement')[0];
        $this->assertStringContainsString('componente de um kit', $erro);
        $this->assertSame(0, $this->project->entitlements()->count());
    }

    public function test_item_do_pedido_que_tambem_e_componente_de_kit_e_aceito(): void
    {
        $this->actingAs($this->operador())
            ->post(route('projects.entitlements.store', $this->project), [
                'name' => 'Maria Silva',
                'email' => 'maria@acme.com.br',
                'product_id' => $this->itemEComponente->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('project_entitlements', [
            'project_id' => $this->project->id,
            'product_id' => $this->itemEComponente->id,
        ]);
    }

    public function test_produto_estranho_ao_projeto_e_recusado_com_mensagem_generica(): void
    {
        $alheio = Product::create(['omie_code' => 'OUTRO-KIT', 'name' => 'Kit de Outro Projeto']);

        $this->actingAs($this->operador())
            ->post(route('projects.entitlements.store', $this->project), [
                'name' => 'Pedro Souza',
                'email' => 'pedro@acme.com.br',
                'product_id' => $alheio->id,
            ])
            ->assertSessionHasErrors('entitlement');

        $erro = session('errors')->get('entitlement')[0];
        $this->assertStringContainsString('não faz parte deste Projeto', $erro);
        $this->assertSame(0, $this->project->entitlements()->count());
    }

    /**
     * O lote autoriza as linhas do pedido, nunca um componente do BOM. Uma
     * planilha antiga com `codigo_kit` apontando para a caneca não muda isso:
     * a coluna é ignorada e a pessoa recebe os itens do Projeto.
     */
    public function test_lote_autoriza_os_itens_do_pedido_e_nunca_o_componente(): void
    {
        $csv = "nome,email,cpf,telefone,codigo_kit\n"
            ."Beto Lima,beto@acme.com.br,,,COMP-CANECA\n";

        $tmp = tempnam(sys_get_temp_dir(), 'welog-lote-');
        file_put_contents($tmp, $csv);

        $this->actingAs($this->operador())
            ->post(route('projects.entitlements.batch', $this->project), [
                'file' => new UploadedFile($tmp, 'lote.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->kit->id, $this->itemEComponente->id],
            $this->project->entitlements()->pluck('product_id')->all(),
        );
        $this->assertDatabaseMissing('project_entitlements', ['product_id' => $this->componenteSo->id]);

        @unlink($tmp);
    }
}
