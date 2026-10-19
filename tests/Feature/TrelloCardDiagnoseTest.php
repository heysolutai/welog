<?php

namespace Tests\Feature;

use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Trello\TrelloFakeGateway;
use App\Models\Company;
use App\Models\Project;
use App\Models\TrelloListMapping;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Não criou no Trello" é sintoma, não causa.
 *
 * As causas se parecem de fora e se resolvem de jeitos diferentes: o Projeto
 * já tinha card, o driver está simulado, a etapa não tem lista no quadro, o
 * webhook nem virou Projeto. O comando existe para responder qual delas é sem
 * ninguém precisar abrir log em produção.
 */
class TrelloCardDiagnoseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();

        config(['integrations.trello.board_id' => 'board1']);
        TrelloListMapping::firstOrCreate(
            ['trello_list_id' => 'list-design'],
            ['trello_list_name' => 'Design', 'pipeline_status' => Project::STATUS_DESIGN],
        );
    }

    private function projeto(array $atributos = []): Project
    {
        $company = Company::firstOrCreate(['cnpj' => '12345678000190'], ['name' => 'ACME']);

        return Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto de teste',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_DESIGN,
            'omie_order_number' => '69848',
            ...$atributos,
        ]);
    }

    /**
     * O caso que mais confunde: o Projeto já tem card, e reprocessar o webhook
     * não faz um segundo. Sem isso escrito, parece que a integração falhou.
     */
    public function test_projeto_que_ja_tem_card_explica_por_que_nada_novo_aparece(): void
    {
        $gateway = app(TrelloGateway::class);
        $this->assertInstanceOf(TrelloFakeGateway::class, $gateway);
        $gateway->seedCard('card-real-1', ['id' => 'card-real-1', 'idList' => 'list-design']);

        $this->projeto(['trello_card_id' => 'card-real-1', 'trello_card_url' => 'https://trello.com/c/card-real-1']);

        $this->artisan('welog:trello:card', ['pedido' => '69848'])
            ->expectsOutputToContain('JÁ tem card')
            ->assertSuccessful();
    }

    /** Card apagado à mão deixa o Projeto surdo: não há mais o que arrastar. */
    public function test_card_apagado_no_quadro_e_denunciado(): void
    {
        $this->projeto(['trello_card_id' => 'card-sumido']);

        $this->artisan('welog:trello:card', ['pedido' => '69848'])
            ->expectsOutputToContain('apagado à mão')
            ->assertSuccessful();
    }

    /**
     * Arquivado conta como ausente: o card sai do quadro e ninguém o arrasta.
     * É o caminho normal, porque a interface do Trello só oferece "excluir"
     * depois que o card já está arquivado.
     */
    public function test_card_arquivado_conta_como_ausente_e_pode_ser_refeito(): void
    {
        $gateway = app(TrelloGateway::class);
        $this->assertInstanceOf(TrelloFakeGateway::class, $gateway);
        $gateway->seedCard('card-arquivado', ['id' => 'card-arquivado', 'idList' => 'list-design', 'closed' => true]);

        $project = $this->projeto(['trello_card_id' => 'card-arquivado']);

        $this->artisan('welog:trello:card', ['pedido' => '69848', '--criar' => true])
            ->expectsOutputToContain('arquivado')
            ->expectsOutputToContain('Card criado')
            ->assertSuccessful();

        $this->assertNotSame('card-arquivado', $project->fresh()->trello_card_id);
    }

    /**
     * Configurado não é o mesmo que alcançável. O modelo pode estar no .env e
     * ainda assim ser recusado (arquivado, apagado, quadro sem acesso), e o
     * card nasce do zero sem ninguém entender por quê.
     */
    public function test_modelo_configurado_mas_ausente_do_quadro_e_denunciado(): void
    {
        config(['integrations.trello.card_templates' => 'com_estoque=Zmz776Yy']);
        $this->projeto(['template' => 'com_estoque']);

        $this->artisan('welog:trello:card', ['pedido' => '69848'])
            ->expectsOutputToContain('NÃO foi encontrado pelo token')
            ->assertSuccessful();
    }

    public function test_driver_simulado_e_apontado_como_causa(): void
    {
        $this->projeto();

        $this->artisan('welog:trello:card', ['pedido' => '69848'])
            ->expectsOutputToContain('TRELLO_DRIVER')
            ->assertSuccessful();
    }

    /** Em Estoque e Concluído são etapas do WeLog: não têm lista no quadro. */
    public function test_etapa_fora_do_trello_e_explicada(): void
    {
        $this->projeto(['pipeline_status' => Project::STATUS_IN_STOCK]);

        $this->artisan('welog:trello:card', ['pedido' => '69848'])
            ->expectsOutputToContain('não é do Trello')
            ->assertSuccessful();
    }

    /**
     * Apagar o card no quadro e rodar com --criar é como a operação confere o
     * modelo depois de mexer nele. Sem soltar a referência antiga, a criação
     * saía pela porta do "já tem card" e nada acontecia.
     */
    public function test_criar_refaz_o_card_que_foi_apagado_do_quadro(): void
    {
        $project = $this->projeto(['trello_card_id' => 'card-sumido', 'trello_card_url' => 'https://trello.com/c/sumido']);

        $this->artisan('welog:trello:card', ['pedido' => '69848', '--criar' => true])
            ->expectsOutputToContain('Card criado')
            ->assertSuccessful();

        $this->assertNotSame('card-sumido', $project->fresh()->trello_card_id);
    }

    public function test_criar_cria_o_card_que_faltava(): void
    {
        $project = $this->projeto();

        $this->artisan('welog:trello:card', ['pedido' => '69848', '--criar' => true])
            ->expectsOutputToContain('Card criado')
            ->assertSuccessful();

        $this->assertNotNull($project->fresh()->trello_card_id);
    }

    /** Sem Projeto, o desfecho está no evento do webhook — e ele é mostrado. */
    public function test_pedido_sem_projeto_mostra_o_desfecho_do_webhook(): void
    {
        WebhookEvent::create([
            'source' => 'omie',
            'external_event_id' => 'ev-1',
            'topic' => 'VendaProduto.Incluida',
            'payload' => ['event' => ['numeroPedido' => '69848']],
            'status' => 'ignored',
            'error' => 'Pedido do projeto Omie 7386090746, fora do filtro em vigor.',
        ]);

        $this->artisan('welog:trello:card', ['pedido' => '69848'])
            ->expectsOutputToContain('fora do filtro em vigor')
            ->assertFailed();
    }
}
