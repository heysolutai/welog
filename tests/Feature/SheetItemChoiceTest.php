<?php

namespace Tests\Feature;

use App\Jobs\FluxoA\ImportEntitlementRows;
use App\Models\Company;
use App\Models\EntitlementImport;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\FluxoA\BatchRegisterEndCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Itens da importação em massa: a planilha traz só os dados das pessoas, e
 * o que cada uma recebe é escolhido na tela, uma vez, para o arquivo todo.
 * Garantido = recebe; Escolha = escolhe 1 entre os marcados assim.
 */
class SheetItemChoiceTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = "nome,email\nAna,ana@sotreq.com.br\nBia,bia@sotreq.com.br\n";

    private User $operador;

    private Project $projeto;

    /** @var array<string, int> nome => id */
    private array $itens = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();
        $empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);

        $this->projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);

        foreach (['Caneca', 'Boné', 'Bolsa', 'Mochila'] as $i => $nome) {
            $produto = Product::create([
                'omie_code' => "ITEM-{$i}", 'name' => $nome, 'active' => true,
                'stock_qty' => 100, 'stock_synced_at' => now(),
            ]);
            $this->projeto->products()->attach($produto->id, ['quantity' => 10]);
            $this->itens[$nome] = $produto->id;
        }
    }

    private function arquivo(string $csv = self::CSV): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, $csv);

        return new UploadedFile($tmp, 'lista.csv', 'text/csv', null, true);
    }

    /** @param  array<int, string>  $garantidos  @param  array<int, string>  $opcoes */
    private function importar(array $garantidos = [], array $opcoes = [], string $csv = self::CSV)
    {
        return $this->actingAs($this->operador)->post(route('projects.entitlements.batch', $this->projeto), [
            'file' => $this->arquivo($csv),
            'garantidos' => array_map(fn ($n) => $this->itens[$n], $garantidos),
            'opcoes' => array_map(fn ($n) => $this->itens[$n], $opcoes),
        ]);
    }

    private function autorizacoes(string $email)
    {
        return ProjectEntitlement::where('user_id', User::where('email', $email)->value('id'))
            ->get()
            ->keyBy('product_id');
    }

    public function test_garantido_e_escolha_valem_para_todos_do_arquivo(): void
    {
        $this->importar(['Caneca'], ['Boné', 'Bolsa', 'Mochila'])->assertSessionHasNoErrors();

        foreach (['ana@sotreq.com.br', 'bia@sotreq.com.br'] as $email) {
            $doc = $this->autorizacoes($email);

            $this->assertCount(4, $doc);
            $this->assertNull($doc[$this->itens['Caneca']]->choice_group);

            $grupo = $doc[$this->itens['Boné']]->choice_group;
            $this->assertNotNull($grupo);
            $this->assertSame($grupo, $doc[$this->itens['Bolsa']]->choice_group);
            $this->assertSame($grupo, $doc[$this->itens['Mochila']]->choice_group);
        }
    }

    /** Item que não foi marcado em nenhum dos dois não é dado. */
    public function test_item_fora_da_escolha_nao_e_dado(): void
    {
        $this->importar(['Caneca'], ['Bolsa', 'Mochila'])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->itens['Caneca'], $this->itens['Bolsa'], $this->itens['Mochila']],
            $this->autorizacoes('ana@sotreq.com.br')->keys()->all(),
        );
    }

    /** Sem escolha enviada: todos os itens garantidos, como a planilha sempre fez. */
    public function test_sem_escolha_todos_os_itens_sao_garantidos(): void
    {
        $this->importar()->assertSessionHasNoErrors();

        $ana = $this->autorizacoes('ana@sotreq.com.br');

        $this->assertCount(4, $ana);
        $this->assertTrue($ana->every(fn ($e) => $e->choice_group === null));
    }

    /** Escolha de um item só não existe: a importação é recusada inteira. */
    public function test_escolha_de_um_item_so_e_recusada(): void
    {
        $this->importar(['Caneca'], ['Boné'])->assertSessionHasErrors('batch');

        $this->assertNull(User::where('email', 'ana@sotreq.com.br')->first());
    }

    public function test_item_de_outro_projeto_e_recusado(): void
    {
        $alheio = Product::create(['omie_code' => 'ALHEIO', 'name' => 'Alheio', 'active' => true]);

        $this->actingAs($this->operador)->post(route('projects.entitlements.batch', $this->projeto), [
            'file' => $this->arquivo(),
            'garantidos' => [$alheio->id],
        ])->assertSessionHasErrors('batch');

        $this->assertNull(User::where('email', 'ana@sotreq.com.br')->first());
    }

    /** O modelo traz só os dados das pessoas, e a prévia não estranha nada. */
    public function test_modelo_so_tem_dados_pessoais(): void
    {
        $this->assertSame(
            ['nome', 'email', 'funcao', 'cpf', 'telefone'],
            BatchRegisterEndCustomers::templateColumns($this->projeto),
        );

        $this->actingAs($this->operador)
            ->postJson(route('projects.entitlements.batch.validate', $this->projeto), ['file' => $this->arquivo()])
            ->assertOk()
            ->assertJson(['prontas' => 2, 'com_erro' => 0, 'cabecalho' => [], 'previa_colunas' => ['nome', 'email']]);
    }

    /** Planilha antiga, com as colunas X/O: as colunas são ignoradas, vale a tela. */
    public function test_planilha_antiga_com_colunas_de_item_segue_a_tela(): void
    {
        $antiga = "nome,email,Caneca,Boné,Bolsa,Mochila\nAna,ana@sotreq.com.br,X,O,O,O\n";

        $this->actingAs($this->operador)
            ->postJson(route('projects.entitlements.batch.validate', $this->projeto), ['file' => $this->arquivo($antiga)])
            ->assertJson(['prontas' => 1, 'com_erro' => 0])
            ->assertJsonPath('cabecalho.0.tipo', 'aviso');

        $this->importar(['Caneca', 'Boné', 'Bolsa', 'Mochila'], [], $antiga)->assertSessionHasNoErrors();

        $this->assertTrue($this->autorizacoes('ana@sotreq.com.br')->every(fn ($e) => $e->choice_group === null));
    }

    /** Planilha grande vai para a fila levando a escolha junto. */
    public function test_a_fila_leva_a_escolha(): void
    {
        Queue::fake();

        $csv = "nome,email\n";
        foreach (range(1, 201) as $n) {
            $csv .= "Pessoa {$n},pessoa{$n}@sotreq.com.br\n";
        }

        $this->importar(['Caneca'], ['Bolsa', 'Mochila'], $csv)->assertSessionHasNoErrors();

        Queue::assertPushed(ImportEntitlementRows::class, fn (ImportEntitlementRows $job) => $job->itens === [
            'garantidos' => [$this->itens['Caneca']],
            'opcoes' => [$this->itens['Bolsa'], $this->itens['Mochila']],
        ]);

        // E o job, rodando, aplica a escolha.
        $job = new ImportEntitlementRows(
            EntitlementImport::firstOrFail()->id,
            [['nome' => 'Caio', 'email' => 'caio@sotreq.com.br']],
            2,
            ['garantidos' => [$this->itens['Caneca']], 'opcoes' => [$this->itens['Bolsa'], $this->itens['Mochila']]],
        );
        app()->call([$job, 'handle']);

        $this->assertCount(3, $this->autorizacoes('caio@sotreq.com.br'));
    }
}
