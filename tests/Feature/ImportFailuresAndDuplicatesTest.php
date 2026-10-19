<?php

namespace Tests\Feature;

use App\Jobs\Auth\SendAccountInvitation;
use App\Jobs\FluxoA\ImportEntitlementRows;
use App\Jobs\FluxoA\NotifyProjectRelease;
use App\Jobs\FluxoA\SendReleaseNotice;
use App\Mail\AccountInvitationMail;
use App\Models\Company;
use App\Models\EntitlementImport;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Services\FluxoA\BatchRegisterEndCustomers;
use App\Services\Support\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Planilha de Colaboradores: e-mail repetido não entra duas vezes, quem já
 * está no Projeto é pulado, o nome chega com a primeira letra maiúscula e as
 * linhas recusadas saem numa planilha para corrigir e subir de novo.
 */
class ImportFailuresAndDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private Project $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();
        $empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);

        $kit = Product::create([
            'omie_code' => 'KIT-IMP', 'name' => 'Kit', 'active' => true,
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);

        $this->projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $this->projeto->products()->attach($kit->id, ['quantity' => 10]);
    }

    private function importar(string $csv)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, $csv);

        return $this->actingAs($this->operador)->post(
            route('projects.entitlements.batch', $this->projeto),
            ['file' => new UploadedFile($tmp, 'lista.csv', 'text/csv', null, true)],
        );
    }

    public function test_email_repetido_na_planilha_entra_uma_vez_so(): void
    {
        $resposta = $this->importar(
            "nome,email\n"
            ."Maria Silva,maria@sotreq.com.br\n"
            ."Maria S.,MARIA@sotreq.com.br\n",
        );

        $this->assertSame(1, User::where('email', 'maria@sotreq.com.br')->count());
        $this->assertSame(1, $this->projeto->entitlements()->count());

        $resposta->assertSessionHasErrors('batch');
        $this->assertStringContainsString('Linha 3: E-mail repetido na planilha (também na linha 2)', session('errors')->get('batch')[0]);
    }

    public function test_quem_ja_esta_no_projeto_e_pulado_sem_novo_convite(): void
    {
        $csv = "nome,email\nAna Lima,ana@sotreq.com.br\nBeto Reis,beto@sotreq.com.br\n";

        $this->importar($csv)->assertSessionHasNoErrors();
        $convites = count(Mail::queued(\App\Mail\AccountInvitationMail::class));

        $this->importar($csv)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 já estavam no Projeto'));

        $this->assertSame(2, $this->projeto->entitlements()->count());
        $this->assertCount($convites, Mail::queued(\App\Mail\AccountInvitationMail::class));
        $this->assertSame(2, EntitlementImport::latest('id')->first()->ignored);
    }

    public function test_nome_chega_com_a_primeira_letra_maiuscula(): void
    {
        $this->importar("nome,email\nMARIA DA SILVA,maria@sotreq.com.br\njoão dos santos,joao@sotreq.com.br\n")
            ->assertSessionHasNoErrors();

        $this->assertSame('Maria da Silva', User::where('email', 'maria@sotreq.com.br')->value('name'));
        $this->assertSame('João dos Santos', User::where('email', 'joao@sotreq.com.br')->value('name'));
    }

    public function test_recusadas_saem_numa_planilha_para_subir_de_novo(): void
    {
        $this->importar(
            "nome,email,cpf\n"
            ."Ana Lima,ana@sotreq.com.br,\n"
            ."Sem Arroba,sem-arroba,52998224725\n",
        );

        $url = session('import_failures_url');
        $this->assertNotNull($url);

        $arquivo = $this->actingAs($this->operador)->get($url)->assertOk()->baseResponse->getFile()->getPathname();
        $linhas = app(SpreadsheetReader::class)->read($arquivo);

        // Só a recusada, com os dados como vieram e o motivo por último.
        $this->assertCount(1, $linhas);
        $this->assertSame('Sem Arroba', $linhas[0]['nome']);
        $this->assertSame('sem-arroba', $linhas[0]['email']);
        $this->assertSame('52998224725', $linhas[0]['cpf']);
        $this->assertStringStartsWith('Linha 3:', $linhas[0]['motivo_da_recusa']);
    }

    public function test_repeticao_em_outro_lote_da_fila_tambem_e_recusada(): void
    {
        $import = EntitlementImport::create([
            'project_id' => $this->projeto->id,
            'uploaded_by' => $this->operador->id,
            'filename' => 'grande.csv',
            'total' => 3,
        ]);

        $linhas = BatchRegisterEndCustomers::marcarRepetidos([
            ['nome' => 'Ana Lima', 'email' => 'ana@sotreq.com.br'],
            ['nome' => 'Beto Reis', 'email' => 'beto@sotreq.com.br'],
            ['nome' => 'Ana de Novo', 'email' => 'ana@sotreq.com.br'],
        ]);

        // Dois lotes, como a fila corta: a repetição cai no segundo.
        (new ImportEntitlementRows($import->id, array_slice($linhas, 0, 2), 2))->handle(app(BatchRegisterEndCustomers::class));
        (new ImportEntitlementRows($import->id, array_slice($linhas, 2), 4))->handle(app(BatchRegisterEndCustomers::class));

        $import->refresh();

        $this->assertSame(2, $import->created);
        $this->assertSame(1, $import->failed);
        $this->assertFalse($import->isRunning());

        $falha = $import->failures()->sole();
        $this->assertSame(4, $falha->linha);
        $this->assertSame('Ana de Novo', $falha->dados['nome']);
        // A marca interna não vai para a planilha das recusadas.
        $this->assertArrayNotHasKey(BatchRegisterEndCustomers::REPETIDA_DA_LINHA, $falha->dados);
    }

    /**
     * O bcrypt (0,2 a 0,4s na VPS) limitava a planilha a umas 200 linhas por
     * minuto. A senha provisória sai uma vez por lote, e o token do convite
     * vai para a fila de e-mail.
     */
    public function test_lote_nao_gasta_um_bcrypt_por_linha(): void
    {
        $this->projeto->company->update(['customizations' => ['welog_sends_emails' => true]]);
        Queue::fake();

        $this->importar("nome,email\nAna Lima,ana@sotreq.com.br\nBeto Reis,beto@sotreq.com.br\n")
            ->assertSessionHasNoErrors();

        $senhas = User::whereIn('email', ['ana@sotreq.com.br', 'beto@sotreq.com.br'])->pluck('password');

        // O mesmo hash de um segredo aleatório, que não abre a conta.
        $this->assertCount(1, $senhas->unique());
        $this->assertFalse(Hash::check('', $senhas->first()));

        // O convite sai pela fila de e-mail, que gera o token na hora do envio.
        Queue::assertPushedOn(config('mail.queue'), SendAccountInvitation::class);
        Queue::assertPushed(SendAccountInvitation::class, 2);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /**
     * Cliente que entra pelo link de primeiro acesso: com o projeto já
     * liberado, a planilha só cria as contas. Nada de token (bcrypt) nem de
     * e-mail, que não sairia mesmo.
     */
    public function test_entrada_pelo_primeiro_acesso_so_cria_as_contas(): void
    {
        $this->projeto->company->update(['customizations' => ['welog_sends_emails' => false]]);
        $this->projeto->update(['released_at' => now(), 'released_by' => $this->operador->id]);
        Queue::fake();

        $this->importar("nome,email\nAna Lima,ana@sotreq.com.br\nBeto Reis,beto@sotreq.com.br\n")
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->projeto->entitlements()->whereNotNull('notified_at')->count());
        Queue::assertNotPushed(SendReleaseNotice::class);
        Queue::assertNotPushed(SendAccountInvitation::class);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /** Com a WeLog avisando, o aviso de liberação e o link saem na fila de e-mail. */
    public function test_projeto_liberado_avisa_pela_fila_de_email(): void
    {
        $this->projeto->company->update(['customizations' => ['welog_sends_emails' => true]]);
        $this->projeto->update(['released_at' => now(), 'released_by' => $this->operador->id]);
        Queue::fake();

        $this->importar("nome,email\nAna Lima,ana@sotreq.com.br\n")->assertSessionHasNoErrors();

        Queue::assertPushedOn(config('mail.queue'), SendReleaseNotice::class);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /** A liberação em massa também não gera token para quem entra pelo link. */
    public function test_liberacao_com_primeiro_acesso_nao_gera_token(): void
    {
        $this->projeto->company->update(['customizations' => ['welog_sends_emails' => false]]);
        $this->importar("nome,email\nAna Lima,ana@sotreq.com.br\nBeto Reis,beto@sotreq.com.br\n")
            ->assertSessionHasNoErrors();

        $this->projeto->update(['released_at' => now(), 'released_by' => $this->operador->id]);
        (new NotifyProjectRelease($this->projeto->id))->handle();

        $this->assertSame(0, $this->projeto->entitlements()->whereNull('notified_at')->count());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Mail::assertNothingQueued();
    }

    public function test_o_convite_da_fila_gera_o_token_e_manda_o_email(): void
    {
        $pessoa = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'password_set_at' => null]);

        (new SendAccountInvitation($pessoa->id))->handle();

        Mail::assertQueued(AccountInvitationMail::class, fn ($m) => $m->user->is($pessoa) && $m->token !== '');
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $pessoa->email]);
    }

    public function test_cancelar_descarta_os_lotes_que_faltam_e_tira_da_tela(): void
    {
        $import = EntitlementImport::create([
            'project_id' => $this->projeto->id,
            'uploaded_by' => $this->operador->id,
            'filename' => 'errada.csv',
            'total' => 300,
            'processed' => 50,
            'created' => 50,
        ]);

        $this->actingAs($this->operador)
            ->post(route('projects.imports.cancel', [$this->projeto, $import]))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'cancelada') && str_contains($s, '50 pessoas'));

        $import->refresh();
        $this->assertNotNull($import->cancelled_at);
        $this->assertFalse($import->isRunning());

        // O lote que ainda estava na fila não cadastra ninguém.
        (new ImportEntitlementRows($import->id, [['nome' => 'Depois', 'email' => 'depois@sotreq.com.br']], 52))
            ->handle(app(BatchRegisterEndCustomers::class));
        $this->assertDatabaseMissing('users', ['email' => 'depois@sotreq.com.br']);

        // Nem painel nem aviso flutuante mostram a importação parada.
        $this->actingAs($this->operador)
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn ($p) => $p->where('import', null));
        $this->actingAs($this->operador)
            ->getJson(route('imports.track'))
            ->assertJsonCount(0, 'importacoes');
    }

    /** Encerrada no meio (à mão, no servidor): "0 de 8.815" não aparece. */
    public function test_importacao_encerrada_no_meio_nao_aparece(): void
    {
        EntitlementImport::create([
            'project_id' => $this->projeto->id,
            'uploaded_by' => $this->operador->id,
            'filename' => 'modelo-clientes-finais-sotreq.xlsx',
            'total' => 8815,
            'processed' => 0,
            'finished_at' => now(),
        ]);

        $this->actingAs($this->operador)
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn ($p) => $p->where('import', null));
    }

    public function test_o_aviso_flutuante_acompanha_a_importacao_da_fila(): void
    {
        $import = EntitlementImport::create([
            'project_id' => $this->projeto->id,
            'uploaded_by' => $this->operador->id,
            'filename' => 'grande.csv',
            'total' => 300,
            'processed' => 100,
        ]);

        $this->actingAs($this->operador)
            ->getJson(route('imports.track'))
            ->assertOk()
            ->assertJsonPath('importacoes.0.id', $import->id)
            ->assertJsonPath('importacoes.0.running', true)
            ->assertJsonPath('importacoes.0.percent', 33)
            ->assertJsonPath('importacoes.0.project_name', 'Campanha');

        // Quem não subiu a planilha não recebe o aviso.
        $outro = User::factory()->create(['role' => User::ROLE_WEGIFT_N1]);

        $this->actingAs($outro)
            ->getJson(route('imports.track'))
            ->assertOk()
            ->assertJsonCount(0, 'importacoes');
    }
}
