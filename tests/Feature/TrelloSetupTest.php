<?php

namespace Tests\Feature;

use App\Contracts\Integrations\TrelloGateway;
use App\Models\Company;
use App\Models\Project;
use App\Models\TrelloListMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `welog:trello:setup` — mapeia as listas do quadro real pelo nome e registra o webhook. */
class TrelloSetupTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD = [
        ['id' => 'l0', 'name' => '0. Template de card'],
        ['id' => 'l1', 'name' => 'Design'],
        ['id' => 'l2', 'name' => 'Aguardando Pedido e/ou Pagamento'],
        ['id' => 'l3', 'name' => 'Compras'],
        ['id' => 'l4', 'name' => 'Recebimento'],
        ['id' => 'l5', 'name' => 'Pré - Montagem'],
        ['id' => 'l6', 'name' => 'Montagem'],
        ['id' => 'l7', 'name' => 'Produção hoje'],
        ['id' => 'l8', 'name' => 'Envio'],
        ['id' => 'l9', 'name' => 'Envio hoje'],
        ['id' => 'l10', 'name' => 'Em estoque'],
        ['id' => 'l11', 'name' => 'Concluído'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.trello.driver' => 'api',
            'integrations.trello.board_id' => 'board1',
            'integrations.trello.webhook_callback_url' => 'https://welog.test/webhooks/trello',
            'integrations.trello.secret' => 's3cret',
        ]);
        TrelloListMapping::create(['trello_list_id' => 'old', 'trello_list_name' => 'Picking', 'pipeline_status' => Project::STATUS_ASSEMBLY]);
    }

    public function test_simulacao_lista_sem_gravar(): void
    {
        $this->mock(TrelloGateway::class)->shouldReceive('getBoardLists')->once()->andReturn(self::BOARD);

        $this->artisan('welog:trello:setup', ['--dry-run' => true])
            ->expectsOutputToContain('Simulação')
            ->assertSuccessful();

        $this->assertSame(['old'], TrelloListMapping::pluck('trello_list_id')->all());
    }

    public function test_mapeia_pelo_nome_registra_webhook_e_cria_cards_que_faltam(): void
    {
        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $fake = Project::create(['company_id' => $empresa->id, 'name' => 'Fake em produção', 'quantity' => 1, 'pipeline_status' => Project::STATUS_PRODUCTION, 'trello_card_id' => 'fake-card-7', 'omie_order_number' => '66906', 'client_order_number' => 'ACME Onboarding']);
        $estoque = Project::create(['company_id' => $empresa->id, 'name' => 'Em estoque', 'quantity' => 1, 'pipeline_status' => Project::STATUS_IN_STOCK, 'trello_card_id' => 'fake-card-8']);

        $gateway = $this->mock(TrelloGateway::class);
        $gateway->shouldReceive('getBoardLists')->once()->andReturn(self::BOARD);
        $gateway->shouldReceive('registerWebhook')->once()
            ->with('https://welog.test/webhooks/trello', 'board1')
            ->andReturn(['id' => 'wh1']);
        // Card real na lista do estágio atual (l3 = Compras → production); Em Estoque não ganha card.
        // Título no formato combinado com a operação: "pedido - nome do cliente"
        // (campo Nº do pedido do cliente da Omie), não o nome editável do Projeto.
        // Sem modelo configurado, o card é criado do zero: três argumentos.
        $gateway->shouldReceive('createCard')->once()
            ->with(\Mockery::anyOf('l2', 'l3', 'l4'), '66906 - ACME Onboarding', \Mockery::type('string'))
            ->andReturn(['id' => 'real-1', 'url' => 'https://trello.com/c/real-1']);

        $this->artisan('welog:trello:setup')
            ->expectsOutputToContain('Webhook registrado (wh1)')
            ->assertSuccessful();

        $map = TrelloListMapping::pluck('pipeline_status', 'trello_list_id')->all();

        $this->assertSame([
            'l1' => 'design',
            'l2' => 'production', 'l3' => 'production', 'l4' => 'production',
            'l5' => 'assembly', 'l6' => 'assembly', 'l7' => 'assembly',
        ], $map);
        // Envio (l8/l9) saiu do quadro: quem move o Projeto para lá é a
        // expedição dos resgates. Em estoque, Concluído e Templates nunca
        // entraram; o mapeamento antigo some.
        $this->assertArrayNotHasKey('l8', $map);
        $this->assertArrayNotHasKey('l9', $map);
        $this->assertArrayNotHasKey('l10', $map);
        $this->assertArrayNotHasKey('old', $map);

        $this->assertSame('real-1', $fake->fresh()->trello_card_id);
        $this->assertSame(Project::STATUS_PRODUCTION, $fake->fresh()->pipeline_status);
        $this->assertSame('fake-card-8', $estoque->fresh()->trello_card_id);
    }

    public function test_map_manual_sobrescreve_de_para(): void
    {
        $this->mock(TrelloGateway::class)->shouldReceive('getBoardLists')->once()
            ->andReturn([['id' => 'x1', 'name' => 'Lista Nova']]);

        $this->artisan('welog:trello:setup', ['--map' => ['Lista Nova=production']])
            ->assertSuccessful();

        $this->assertSame('production', TrelloListMapping::statusForList('x1'));
    }

    /**
     * O quadro governa três movimentações. Apontar uma lista para Envio (ou
     * Estoque/Concluído) à mão traria de volta as duas fontes disputando o
     * campo — o `--map` recusa em vez de aceitar em silêncio.
     */
    public function test_map_manual_recusa_etapa_fora_do_quadro(): void
    {
        $this->mock(TrelloGateway::class)->shouldReceive('getBoardLists')->once()
            ->andReturn([['id' => 'x1', 'name' => 'Expedição']]);

        $this->artisan('welog:trello:setup', ['--map' => ['Expedição=shipping']])
            ->expectsOutputToContain('--map ignorado')
            ->assertSuccessful();

        $this->assertNull(TrelloListMapping::statusForList('x1'));
    }

    public function test_driver_fake_nao_faz_nada_e_sai_ok(): void
    {
        config(['integrations.trello.driver' => 'fake']);
        $this->artisan('welog:trello:setup')->expectsOutputToContain('desligado')->assertSuccessful();
    }

    public function test_webhook_ja_registrado_nao_e_erro(): void
    {
        $gateway = $this->mock(TrelloGateway::class);
        $gateway->shouldReceive('getBoardLists')->once()->andReturn(self::BOARD);
        $gateway->shouldReceive('registerWebhook')->once()
            ->andThrow(new \RuntimeException('A webhook with that callback, model, and token already exists'));

        $this->artisan('welog:trello:setup')
            ->expectsOutputToContain('Webhook já registrado')
            ->assertSuccessful();
    }

    /** Base povoada: ligar o Trello não pode despejar dezenas de cards no quadro. */
    public function test_sem_cards_mapeia_e_registra_webhook_sem_criar_card(): void
    {
        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $projeto = Project::create(['company_id' => $empresa->id, 'name' => 'P1', 'quantity' => 1, 'pipeline_status' => Project::STATUS_PRODUCTION]);

        $gateway = $this->mock(TrelloGateway::class);
        $gateway->shouldReceive('getBoardLists')->once()->andReturn(self::BOARD);
        $gateway->shouldReceive('registerWebhook')->once()->andReturn(['id' => 'wh1']);
        $gateway->shouldNotReceive('createCard');

        $this->artisan('welog:trello:setup', ['--sem-cards' => true])
            ->expectsOutputToContain('Pulado (--sem-cards)')
            ->assertSuccessful();

        $this->assertNull($projeto->fresh()->trello_card_id);
        $this->assertSame('production', TrelloListMapping::statusForList('l3'));
    }

    /** O teste da operação começa por UM pedido: --pedido restringe a criação. */
    public function test_pedido_cria_card_so_do_projeto_indicado(): void
    {
        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $alvo = Project::create(['company_id' => $empresa->id, 'name' => 'Alvo', 'quantity' => 1, 'pipeline_status' => Project::STATUS_PRODUCTION, 'omie_order_number' => '66906', 'client_order_number' => 'ACME Onboarding']);
        $outro = Project::create(['company_id' => $empresa->id, 'name' => 'Outro', 'quantity' => 1, 'pipeline_status' => Project::STATUS_PRODUCTION, 'omie_order_number' => '77777']);

        $gateway = $this->mock(TrelloGateway::class);
        $gateway->shouldReceive('getBoardLists')->once()->andReturn(self::BOARD);
        $gateway->shouldReceive('registerWebhook')->once()->andReturn(['id' => 'wh1']);
        $gateway->shouldReceive('createCard')->once()
            ->with(\Mockery::anyOf('l2', 'l3', 'l4'), '66906 - ACME Onboarding', \Mockery::type('string'))
            ->andReturn(['id' => 'real-1', 'url' => 'https://trello.com/c/real-1']);

        $this->artisan('welog:trello:setup', ['--pedido' => ['66906']])
            ->assertSuccessful();

        $this->assertSame('real-1', $alvo->fresh()->trello_card_id);
        $this->assertNull($outro->fresh()->trello_card_id);
    }
}
