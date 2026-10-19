<?php

namespace Tests\Feature;

use App\Jobs\FluxoA\ImportEntitlementRows;
use App\Jobs\FluxoA\NotifyProjectRelease;
use App\Mail\ProjectReleasedMail;
use App\Models\Company;
use App\Models\EntitlementImport;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\FluxoA\BatchRegisterEndCustomers;
use App\Services\FluxoA\ReleaseProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Os dois caminhos que quebram numa campanha grande.
 *
 * Os dois faziam o trabalho inteiro DENTRO da requisição, uma volta por
 * pessoa, e cada volta gasta um hash bcrypt (~60ms) no token de primeiro
 * acesso. Com nove mil colaboradores isso passa de dez minutos numa
 * requisição que o nginx corta aos cinco: metade era atendida, o operador via
 * 504 e não tinha como saber quem tinha ficado para trás — no caso da
 * liberação, pior, porque o `notified_at` era gravado de uma vez para todos e
 * escondia justamente isso.
 */
class MassScaleTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Product $kit;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->kit = Product::create([
            'omie_code' => 'KIT-A', 'name' => 'Kit A',
            'stock_qty' => 10000, 'stock_synced_at' => now(),
        ]);

        $this->project = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha grande',
            'quantity' => 9000,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
        ]);
        $this->project->products()->attach($this->kit->id, ['quantity' => 9000]);

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function autorizar(int $quantos): void
    {
        foreach (range(1, $quantos) as $n) {
            $pessoa = User::factory()->create([
                'role' => User::ROLE_END_CUSTOMER,
                'company_id' => $this->project->company_id,
            ]);

            ProjectEntitlement::create([
                'project_id' => $this->project->id,
                'user_id' => $pessoa->id,
                'product_id' => $this->kit->id,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Liberação
    // ------------------------------------------------------------------

    /**
     * A requisição do operador só libera e enfileira. O laço de e-mails, que
     * era o que estourava o tempo, saiu dela.
     */
    public function test_liberar_nao_manda_email_dentro_da_requisicao(): void
    {
        Queue::fake();
        $this->autorizar(3);

        app(ReleaseProject::class)->handle($this->project, $this->operador);

        $this->assertTrue($this->project->fresh()->isReleased());
        Queue::assertPushed(NotifyProjectRelease::class);

        // Nada de e-mail nem de carimbo aqui: isso é trabalho do job.
        Mail::assertNothingQueued();
        $this->assertSame(3, ProjectEntitlement::whereNull('notified_at')->count());
    }

    /** O job avisa, carimba e se reenfileira até não sobrar ninguém. */
    public function test_o_anuncio_avisa_todo_mundo_em_lotes(): void
    {
        $this->autorizar(3);
        app(ReleaseProject::class)->handle($this->project, $this->operador);

        Mail::assertQueued(ProjectReleasedMail::class, 3);
        $this->assertSame(0, ProjectEntitlement::whereNull('notified_at')->count());
    }

    /**
     * O carimbo é a fila de trabalho, não um registro escrito de antemão:
     * quem já recebeu não recebe de novo quando o job volta.
     */
    public function test_o_anuncio_nao_repete_para_quem_ja_recebeu(): void
    {
        $this->autorizar(3);
        app(ReleaseProject::class)->handle($this->project, $this->operador);

        Mail::fake(); // zera a contagem

        // Uma segunda passada do mesmo job — o que acontece quando ele falha e
        // a fila o devolve.
        (new NotifyProjectRelease($this->project->id))->handle();

        Mail::assertNothingQueued();
    }

    /** Falha de um e-mail não pode segurar os outros oito mil e novecentos. */
    public function test_um_email_que_falha_nao_trava_a_fila(): void
    {
        $this->autorizar(2);
        $this->project->update(['released_at' => now(), 'released_by' => $this->operador->id]);

        // Um dos dois sem e-mail válido: o Mail::to() estoura.
        ProjectEntitlement::where('project_id', $this->project->id)
            ->first()->user()->update(['email' => '']);

        (new NotifyProjectRelease($this->project->id))->handle();

        // O que quebrou também sai da fila de trabalho, senão o job volta
        // eternamente no mesmo registro e nunca alcança os seguintes.
        $this->assertSame(0, ProjectEntitlement::whereNull('notified_at')->count());
    }

    // ------------------------------------------------------------------
    // Importação em massa
    // ------------------------------------------------------------------

    private function planilha(int $linhas): UploadedFile
    {
        $csv = "nome,email,cpf,telefone\n";

        foreach (range(1, $linhas) as $n) {
            $csv .= "Pessoa {$n},pessoa{$n}@acme.com.br,,\n";
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, $csv);

        return new UploadedFile($tmp, 'lote.csv', 'text/csv', null, true);
    }

    /** Planilha pequena continua respondendo na hora, com o resultado pronto. */
    public function test_planilha_pequena_e_cadastrada_na_requisicao(): void
    {
        Queue::fake();

        $this->actingAs($this->operador)
            ->post(route('projects.entitlements.batch', $this->project), ['file' => $this->planilha(3)])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, ProjectEntitlement::where('project_id', $this->project->id)->count());
        Queue::assertNotPushed(ImportEntitlementRows::class);

        // Fica registrada, já encerrada: é o que guarda as recusadas dela para
        // baixar. Mas não vira painel de progresso na tela do Projeto — o
        // resultado já veio na resposta.
        $import = EntitlementImport::sole();
        $this->assertFalse($import->isRunning());
        $this->assertSame(3, $import->created);

        $this->actingAs($this->operador)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn ($p) => $p->where('import', null));
    }

    /** Acima do corte, a requisição só recebe o arquivo e devolve a tela. */
    public function test_planilha_grande_vai_para_a_fila_em_lotes(): void
    {
        Queue::fake();

        $this->actingAs($this->operador)
            ->post(route('projects.entitlements.batch', $this->project), ['file' => $this->planilha(250)])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '250 linhas recebidas'));

        // Nada cadastrado ainda: quem cadastra é a fila.
        $this->assertSame(0, ProjectEntitlement::where('project_id', $this->project->id)->count());

        $import = EntitlementImport::firstOrFail();
        $this->assertSame(250, $import->total);
        $this->assertTrue($import->isRunning());

        // 250 linhas / 50 por lote.
        Queue::assertPushed(ImportEntitlementRows::class, 5);
    }

    /**
     * O número da linha do erro é o da PLANILHA, não o do lote: "linha 17" num
     * lote que começa na 201 manda o operador procurar no lugar errado.
     */
    public function test_o_erro_aponta_a_linha_da_planilha(): void
    {
        $import = EntitlementImport::create([
            'project_id' => $this->project->id,
            'uploaded_by' => $this->operador->id,
            'filename' => 'lote.csv',
            'total' => 2,
        ]);

        (new ImportEntitlementRows($import->id, [
            ['nome' => 'Boa', 'email' => 'boa@acme.com.br', 'cpf' => '', 'telefone' => ''],
            ['nome' => 'Ruim', 'email' => 'sem-arroba', 'cpf' => '', 'telefone' => ''],
        ], 201))->handle(app(BatchRegisterEndCustomers::class));

        $import->refresh();

        $this->assertSame(2, $import->processed);
        $this->assertSame(1, $import->created);
        $this->assertSame(202, $import->errors[0]['row']);
        // Conta fechada: o carimbo de fim é o que tira a barra da tela.
        $this->assertFalse($import->isRunning());
    }

    /** A tela do Projeto mostra a importação em andamento. */
    public function test_a_tela_do_projeto_mostra_o_progresso(): void
    {
        EntitlementImport::create([
            'project_id' => $this->project->id,
            'uploaded_by' => $this->operador->id,
            'filename' => 'nove-mil.csv',
            'total' => 9000,
            'processed' => 3150,
        ]);

        $this->actingAs($this->operador)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn ($p) => $p
                ->where('import.total', 9000)
                ->where('import.processed', 3150)
                ->where('import.percent', 35)
                ->where('import.running', true));
    }
}
