<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Omie\OmieFakeGateway;
use App\Mail\ProjectReleasedMail;
use App\Mail\RedemptionConfirmedMail;
use App\Mail\ShipmentUpdateMail;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Services\FluxoA\BatchRegisterEndCustomers;
use App\Services\FluxoA\ReleaseProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Fluxo A ponta a ponta com gateways fake:
 * pedido Omie (webhook) → Projeto + card Trello → estoque → liberação →
 * cadastro de clientes finais → resgate no portal → expedição.
 */
class FluxoATest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
        $this->seed();
    }

    /** Cria um pedido no fake da Omie e devolve o id. */
    private function createOmieOrder(): string
    {
        $omie = app(OmieGateway::class);

        $result = $omie->createOrder([
            'codigo_pedido_integracao' => 'fluxo-a-1',
            'cabecalho' => [
                'numero_pedido' => 'PV-77777',
                'codigo_cliente' => 'CLI-00099',
                'data_previsao' => now()->addDays(30)->toDateString(),
            ],
            // Campo onde a operação digita o nome pelo qual o cliente é
            // chamado — é ele que dá nome ao card do Trello.
            'informacoes_adicionais' => ['numero_pedido_cliente' => 'ACME Onboarding'],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME Tecnologia LTDA'],
            'det' => [
                ['produto' => ['codigo' => 'KIT-ONBOARD-01', 'quantidade' => 50]],
            ],
        ]);

        return $result['omie_order_id'];
    }

    private function webhookOrder(string $orderId): void
    {
        $this->postJson('/webhooks/omie/'.self::TOKEN, [
            'messageId' => 'msg-order-'.$orderId,
            'topic' => 'VendaProduto.Incluida',
            'event' => ['idPedido' => $orderId],
        ])->assertOk();
    }

    public function test_webhook_de_pedido_cria_projeto_com_empresa_kits_e_card_trello(): void
    {
        $orderId = $this->createOmieOrder();
        $this->webhookOrder($orderId);

        $project = Project::where('omie_order_id', $orderId)->first();

        $this->assertNotNull($project);
        $this->assertSame('ACME Tecnologia LTDA', $project->company->name);
        // O nome do Projeto vem do "Nº do pedido do cliente" da Omie, que é
        // onde a operação escreve como a campanha se chama.
        $this->assertSame('PV-77777 - ACME Onboarding', $project->name);
        $this->assertSame(50, $project->quantity);
        $this->assertSame(Project::STATUS_DESIGN, $project->pipeline_status);
        $this->assertNotNull($project->trello_card_id);

        // Título do card no formato combinado: "número do pedido - nome do
        // cliente", com o nome vindo do campo Nº do pedido do cliente da Omie
        // — fixo, mesmo que o nome do Projeto seja editado depois.
        $card = app(TrelloGateway::class)->getCard($project->trello_card_id);
        $this->assertSame('PV-77777 - ACME Onboarding', $card['name']);

        // Kit espelhado com BOM
        $kit = $project->products->firstWhere('omie_code', 'KIT-ONBOARD-01');
        $this->assertNotNull($kit);
        $this->assertCount(3, $kit->components);
    }

    public function test_webhook_de_estoque_habilita_liberacao(): void
    {
        $orderId = $this->createOmieOrder();
        $this->webhookOrder($orderId);

        $project = Project::where('omie_order_id', $orderId)->first();
        $this->assertFalse($project->hasStockAvailable());

        $this->postJson('/webhooks/omie/'.self::TOKEN, [
            'messageId' => 'msg-stock-1',
            'topic' => 'Estoque.Alterado',
            'event' => ['codigo_produto' => 'KIT-ONBOARD-01'],
        ])->assertOk();

        $this->assertTrue($project->fresh()->hasStockAvailable());
        $this->assertTrue($project->fresh()->canBeReleased());
    }

    public function test_estoque_insuficiente_nao_habilita_liberacao(): void
    {
        $orderId = $this->createOmieOrder();
        $this->webhookOrder($orderId);

        /** @var OmieFakeGateway $omie */
        $omie = app(OmieGateway::class);
        $omie->setStock('KIT-ONBOARD-01', 10); // pedido exige 50

        $this->postJson('/webhooks/omie/'.self::TOKEN, [
            'messageId' => 'msg-stock-2',
            'topic' => 'Estoque.Alterado',
            'event' => ['codigo_produto' => 'KIT-ONBOARD-01'],
        ])->assertOk();

        $this->assertFalse(Project::where('omie_order_id', $orderId)->first()->hasStockAvailable());
    }

    /** Prepara projeto liberável com um cliente final cadastrado. */
    private function projectReadyToRelease(): array
    {
        $orderId = $this->createOmieOrder();
        $this->webhookOrder($orderId);

        $project = Project::where('omie_order_id', $orderId)->first();
        $project->update(['stock_available_at' => now(), 'pipeline_status' => Project::STATUS_IN_STOCK]);

        // A liberação exige saldo no espelho local: reflete aqui o "estoque
        // farto" que o fake da Omie responderia se o sinal tivesse rodado.
        $project->products->each->update(['stock_qty' => 1000, 'stock_synced_at' => now()]);

        $operator = User::where('email', 'operador@welog.local')->first();

        $this->actingAs($operator)
            ->post(route('projects.entitlements.store', $project), [
                'name' => 'João Colaborador',
                'email' => 'joao@acme.com.br',
                'cpf' => '111.222.333-44',
                'product_id' => $project->products->first()->id,
            ])->assertSessionHasNoErrors();

        return [$project->fresh(), $operator];
    }

    public function test_liberacao_notifica_clientes_finais(): void
    {
        Mail::fake();
        [$project, $operator] = $this->projectReadyToRelease();

        $this->actingAs($operator)
            ->post(route('projects.release', $project))
            ->assertSessionHasNoErrors();

        $this->assertTrue($project->fresh()->isReleased());
        Mail::assertQueued(ProjectReleasedMail::class, 1);
        $this->assertNotNull($project->entitlements()->first()->notified_at);
    }

    public function test_liberacao_concorrente_nao_duplica_email(): void
    {
        Mail::fake();
        [$project, $operator] = $this->projectReadyToRelease();

        // Segundo clique (ou request paralelo) sobre o mesmo Projeto
        $this->actingAs($operator)->post(route('projects.release', $project));
        $this->actingAs($operator)
            ->post(route('projects.release', $project))
            ->assertSessionHasErrors('release');

        Mail::assertQueued(ProjectReleasedMail::class, 1);
    }

    public function test_operador_n2_nao_pode_liberar_projeto(): void
    {
        [$project] = $this->projectReadyToRelease();
        $n2 = User::where('email', 'consulta@welog.local')->first();

        $this->actingAs($n2)
            ->post(route('projects.release', $project))
            ->assertForbidden();
    }

    public function test_cadastro_em_lote_por_csv(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        $csv = "nome,email,cpf,telefone\n"
            ."Maria Silva,maria@acme.com.br,52998224725,41999990000\n"
            ."Pedro Souza,pedro@acme.com.br,,\n"
            ."Linha Invalida,sem-email,,\n";

        $path = sys_get_temp_dir().'/lote.csv';
        file_put_contents($path, $csv);

        $file = new UploadedFile($path, 'lote.csv', 'text/csv', null, true);

        $response = $this->actingAs($operator)
            ->post(route('projects.entitlements.batch', $project), ['file' => $file]);

        // João (setup) + Maria + Pedro; linha inválida reportada
        $this->assertSame(3, $project->entitlements()->count());
        $response->assertSessionHasErrors('batch');
    }

    /**
     * A coluna "Função" faz a mesma planilha cadastrar os dois papéis do
     * Projeto. Quem administra entra junto com quem resgata — antes, era um
     * segundo cadastro manual depois da importação, e era o passo que ficava
     * para trás.
     */
    public function test_lote_cadastra_admin_de_projeto_pela_coluna_funcao(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents(
            $tmp,
            "nome,email,funcao,cpf,telefone\n"
            // Admin não recebe item: a linha dele vem só com nome e e-mail.
            ."Clara Gestora,clara@acme.com.br,Admin Projeto,,\n"
            // Coluna vazia continua sendo Colaborador, como sempre foi.
            ."Maria Silva,maria@acme.com.br,,52998224725,41999990000\n",
        );

        $this->actingAs($operator)
            ->post(route('projects.entitlements.batch', $project), [
                'file' => new UploadedFile($tmp, 'lote.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'clara@acme.com.br',
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $project->company_id,
            'admin_project_id' => $project->id,
        ]);

        // O admin não vira autorização: João (setup) + Maria, e só.
        $this->assertSame(2, $project->entitlements()->count());
        $this->assertDatabaseHas('users', [
            'email' => 'maria@acme.com.br',
            'role' => User::ROLE_END_CUSTOMER,
        ]);

        @unlink($tmp);
    }

    /**
     * "Gerente" na coluna é alguém tentando criar um admin com a palavra
     * errada. Cadastrar Colaborador em silêncio seria dar acesso de menos a
     * quem deveria administrar, sem ninguém perceber.
     */
    public function test_funcao_desconhecida_recusa_a_linha(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents(
            $tmp,
            "nome,email,funcao,cpf,telefone\n"
            ."Ana Souza,ana@acme.com.br,Gerente,,\n",
        );

        $response = $this->actingAs($operator)
            ->post(route('projects.entitlements.batch', $project), [
                'file' => new UploadedFile($tmp, 'lote.csv', 'text/csv', null, true),
            ]);

        $response->assertSessionHasErrors('batch');
        $this->assertStringContainsString('Gerente', session('errors')->get('batch')[0]);
        $this->assertDatabaseMissing('users', ['email' => 'ana@acme.com.br']);

        @unlink($tmp);
    }

    /** O Admin de Projeto importa no Projeto dele; num outro, a linha é recusada. */
    public function test_admin_de_projeto_nao_importa_admin_para_outro_projeto(): void
    {
        [$project] = $this->projectReadyToRelease();

        $outro = Project::create(['company_id' => $project->company_id, 'name' => 'Projeto Vizinho']);

        $adminDoOutro = User::factory()->create([
            'role' => User::ROLE_PROJECT_ADMIN,
            'company_id' => $project->company_id,
            'admin_project_id' => $outro->id,
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents(
            $tmp,
            "nome,email,funcao\n"
            ."Intrusa,intrusa@acme.com.br,Admin Projeto\n",
        );

        $this->actingAs($adminDoOutro)
            ->post(route('projects.entitlements.batch', $project), [
                'file' => new UploadedFile($tmp, 'lote.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasErrors('batch');

        $this->assertDatabaseMissing('users', ['email' => 'intrusa@acme.com.br']);

        @unlink($tmp);
    }

    /**
     * A tela do Projeto aninha o subitem sob o kit e, para não mostrar a mesma
     * coisa duas vezes, precisa reconhecer quando um item do pedido já aparece
     * dentro de outro. Ela casa os dois pelo `omie_code` do componente — sem
     * ele o subitem volta a aparecer solto no topo, ao lado do próprio kit.
     */
    public function test_componentes_do_kit_chegam_a_tela_com_codigo(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        $this->actingAs($operator)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('project.products.0.components.0.omie_code')
                ->has('project.products.0.components.0.name'),
            );
    }

    public function test_baixa_o_modelo_xlsx_do_cadastro_em_lote(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        $this->actingAs($operator)
            ->get(route('projects.entitlements.template', $project))
            ->assertOk()
            ->assertDownload('modelo-clientes-finais.xlsx');
    }

    // ------------------------------------------------------------------
    // Projeto de kit único: não há kit a escolher
    // ------------------------------------------------------------------

    /** Um kit só: o formulário não pergunta, e o cadastro tem de funcionar sem a resposta. */
    public function test_cadastro_individual_dispensa_o_kit_quando_o_projeto_so_tem_um(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        $this->assertSame(1, $project->products()->count());

        $this->actingAs($operator)
            ->post(route('projects.entitlements.store', $project), [
                'name' => 'Maria Silva',
                'email' => 'maria@acme.com.br',
            ])
            ->assertSessionHasNoErrors();

        $entitlement = ProjectEntitlement::whereHas('user', fn ($q) => $q->where('email', 'maria@acme.com.br'))
            ->firstOrFail();

        $this->assertSame($project->products()->value('products.id'), $entitlement->product_id);
    }

    /**
     * Com vários itens, o modelo ganha uma coluna por item (X/O). Mas planilha
     * antiga, sem essas colunas, continua valendo: cada Colaborador dela
     * recebe todos os itens do pedido.
     */
    public function test_lote_sem_colunas_de_item_autoriza_todos_os_itens(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        $segundo = Product::create([
            'omie_code' => 'BRINDE-EXTRA', 'name' => 'Brinde Extra', 'active' => true,
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);
        $project->products()->attach($segundo->id, ['quantity' => 5]);

        $colunas = BatchRegisterEndCustomers::templateColumns($project->fresh());
        // A planilha é só de dados pessoais: os itens não viram coluna.
        $this->assertSame(['nome', 'email', 'funcao', 'cpf', 'telefone'], $colunas);

        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, "nome,email,cpf,telefone\n"
            ."Ana Souza,ana@acme.com.br,52998224725,41999990000\n");

        $this->actingAs($operator)
            ->post(route('projects.entitlements.batch', $project), [
                'file' => new UploadedFile($tmp, 'lote.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasNoErrors();

        $autorizados = ProjectEntitlement::whereHas('user', fn ($q) => $q->where('email', 'ana@acme.com.br'))
            ->pluck('product_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($project->products()->pluck('products.id')->sort()->values()->all(), $autorizados);

        @unlink($tmp);
    }

    /**
     * O upload real chega em caminho temporário SEM extensão — o leitor
     * precisa reconhecer o CSV pela assinatura, não pelo nome do arquivo.
     */
    public function test_cadastro_em_lote_com_upload_real_sem_extensao_no_tmp(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        // Excel em pt-BR exporta com ponto e vírgula
        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents(
            $tmp,
            "nome;email;cpf;telefone\n"
            ."Maria Silva;maria@acme.com.br;52998224725;41999990000\n",
        );

        $file = new UploadedFile($tmp, 'lote.csv', 'text/csv', null, true);

        $this->actingAs($operator)
            ->post(route('projects.entitlements.batch', $project), ['file' => $file])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $project->entitlements()->count()); // João + Maria
        $this->assertDatabaseHas('users', ['email' => 'maria@acme.com.br']);

        @unlink($tmp);
    }

    /**
     * Arquivo corrompido tem de virar mensagem para o operador — nunca 500.
     * (O caminho interno do leitor está coberto em SpreadsheetReaderTest.)
     */
    public function test_arquivo_invalido_no_lote_vira_mensagem_e_nao_erro_500(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();

        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, "PK\x03\x04conteúdo corrompido"); // finge ser xlsx

        $file = new UploadedFile($tmp, 'lote.xlsx', null, null, true);

        $response = $this->actingAs($operator)
            ->post(route('projects.entitlements.batch', $project), ['file' => $file]);

        $response->assertSessionHasErrors();
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertSame(1, $project->entitlements()->count()); // só o João do setup

        @unlink($tmp);
    }

    public function test_resgate_no_portal_e_expedicao(): void
    {
        Mail::fake();
        [$project, $operator] = $this->projectReadyToRelease();
        app(ReleaseProject::class)->handle($project, $operator);

        $entitlement = ProjectEntitlement::first();
        $endCustomer = $entitlement->user;

        // Cliente final vê o catálogo e resgata
        $this->actingAs($endCustomer)->get(route('portal.catalog'))->assertOk();

        $this->actingAs($endCustomer)
            ->post(route('portal.redeem', $entitlement), [
                'recipient_name' => 'João Colaborador',
                'cpf' => '529.982.247-25',
                'zip' => '80010-000',
                'street' => 'Rua XV de Novembro',
                'number' => '100',
                'district' => 'Centro',
                'city' => 'Curitiba',
                'state' => 'PR',
            ])->assertRedirect(route('portal.redemptions'));

        $redemption = Redemption::first();
        $this->assertSame(Redemption::STATUS_CONFIRMED, $redemption->status);
        Mail::assertQueued(RedemptionConfirmedMail::class, 1);

        // Resgate duplo bloqueado
        $this->actingAs($endCustomer)
            ->post(route('portal.redeem', $entitlement), [
                'recipient_name' => 'João', 'cpf' => '52998224725',
                'zip' => '80010000', 'street' => 'X',
                'number' => '1', 'district' => 'C', 'city' => 'Cwb', 'state' => 'PR',
            ])->assertSessionHasErrors('redeem');

        // Operador expede via Correios com prepostagem automática (fake)
        config(['integrations.correios.prepostagem_enabled' => true]);

        $this->actingAs($operator)
            ->post(route('redemptions.ship', $redemption), ['carrier' => 'correios'])
            ->assertSessionHasNoErrors();

        $redemption->refresh();
        $this->assertSame(Redemption::STATUS_SHIPPED, $redemption->status);
        $this->assertStringStartsWith('BR', $redemption->tracking_code);
        Mail::assertQueued(ShipmentUpdateMail::class, 1);

        // MRB/DDM exigem rastreio manual
        $other = Redemption::create($redemption->only([
            'project_id', 'user_id', 'product_id', 'recipient_name', 'zip', 'street',
            'number', 'district', 'city', 'state',
        ]) + ['status' => Redemption::STATUS_CONFIRMED]);

        $this->actingAs($operator)
            ->post(route('redemptions.ship', $other), ['carrier' => 'mrb'])
            ->assertSessionHasErrors('ship');
    }

    public function test_cliente_final_nao_resgata_autorizacao_de_outro(): void
    {
        [$project, $operator] = $this->projectReadyToRelease();
        app(ReleaseProject::class)->handle($project->fresh(), $operator);

        $entitlement = ProjectEntitlement::first();
        $intruder = User::factory()->create(['role' => User::ROLE_END_CUSTOMER]);

        $this->actingAs($intruder)
            ->get(route('portal.redeem.form', $entitlement))
            ->assertForbidden();
    }
}
