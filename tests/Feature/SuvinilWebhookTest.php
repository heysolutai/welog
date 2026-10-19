<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieApiException;
use App\Jobs\Integrations\ProcessSuvinilWebhook;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\WebhookEvent;
use App\Services\FluxoB\IngestPartnerRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A segunda porta das Importações Parceiros: em vez de o operador exportar o
 * relatório e salvar na pasta, a Suvinil chama a nossa URL no instante do
 * resgate.
 *
 * O que estes testes protegem não é o caminho feliz, é o reenvio: webhook de
 * parceiro reenvia por timeout, por retry automático e por engano humano, e
 * cada reenvio não travado é um Pedido de Venda duplicado na Omie — brinde de
 * verdade saindo duas vezes.
 */
class SuvinilWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste-da-suvinil';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();

        config()->set('integrations.suvinil.webhook_token', self::TOKEN);
    }

    private function resgate(string $cpf = '52998224725', string $produto = 'SUV-PINCEL-01'): array
    {
        return [
            'cpf' => $cpf,
            'nome' => 'José Pintor',
            'email' => 'jose@pintores.com.br',
            'telefone' => '41988887777',
            'codigo_produto' => $produto,
            'produto' => 'Kit Pincéis',
            'quantidade' => 2,
            'cep' => '80010000',
            'endereco' => 'Rua das Tintas',
            'numero' => '45',
            'complemento' => '',
            'bairro' => 'Centro',
            'cidade' => 'Curitiba',
            'uf' => 'PR',
        ];
    }

    private function enviar(array $corpo, string $token = self::TOKEN)
    {
        return $this->postJson("/webhooks/suvinil/{$token}", $corpo);
    }

    // ------------------------------------------------------------------
    // A porta
    // ------------------------------------------------------------------

    public function test_token_errado_e_recusado(): void
    {
        $this->enviar(['lote' => 'A', 'resgates' => [$this->resgate()]], 'token-errado')
            ->assertForbidden();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    /**
     * Sem token configurado a rota tem de recusar TUDO. Do contrário um
     * ambiente onde ninguém preencheu o .env fica com a porta aberta — e a
     * string vazia da configuração casaria com a string vazia da URL.
     */
    public function test_sem_token_configurado_a_rota_fica_fechada(): void
    {
        config()->set('integrations.suvinil.webhook_token', '');

        $this->enviar(['resgates' => [$this->resgate()]])->assertForbidden();
    }

    /** O parceiro testa a URL antes de ligar o disparo. */
    public function test_a_url_responde_a_verificacao(): void
    {
        $this->getJson('/webhooks/suvinil/'.self::TOKEN)
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->getJson('/webhooks/suvinil/token-errado')->assertForbidden();
    }

    // ------------------------------------------------------------------
    // O corpo
    // ------------------------------------------------------------------

    /**
     * Corpo que não reconhecemos é GUARDADO, não recusado.
     *
     * Enquanto o formato do parceiro não está fechado, um 422 não deixa nada
     * para olhar deste lado e a conversa vira "mandamos" / "não chegou". O
     * payload cru em /admin/webhooks é o que encerra essa discussão.
     */
    public function test_corpo_nao_reconhecido_e_guardado_e_nao_recusado(): void
    {
        $this->enviar(['pedidos' => [['algo' => 'diferente']], 'origem' => 'suvinil'])
            ->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('resgates', 0)
            ->assertJsonPath('campos_recebidos', ['pedidos', 'origem']);

        $event = WebhookEvent::firstOrFail();
        $this->assertSame(WebhookEvent::STATUS_IGNORED, $event->status);
        $this->assertSame('suvinil', $event->payload['origem']);

        // Nada foi processado: só registrado.
        $this->assertDatabaseCount('appnoz_imports', 0);
    }

    /** Corpo que nem JSON é continua deixando evidência do que chegou. */
    public function test_corpo_que_nao_e_json_fica_registrado_cru(): void
    {
        $this->call(
            'POST',
            '/webhooks/suvinil/'.self::TOKEN,
            content: 'cpf=52998224725&nome=Teste',
            server: ['CONTENT_TYPE' => 'text/plain'],
        )->assertOk();

        $this->assertStringContainsString(
            'cpf=52998224725',
            WebhookEvent::firstOrFail()->payload['corpoCru'],
        );
    }

    /** Lote grande estoura o timeout do parceiro e volta em reenvio eterno. */
    public function test_lote_acima_do_teto_e_recusado(): void
    {
        config()->set('integrations.suvinil.max_rows', 2);

        $this->enviar(['lote' => 'A', 'resgates' => array_fill(0, 3, $this->resgate())])
            ->assertStatus(422);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    /** Lista pura, sem envelope: alguns parceiros mandam só o array. */
    public function test_aceita_a_lista_pura_de_resgates(): void
    {
        Queue::fake();

        $this->enviar([$this->resgate()])->assertOk();

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessSuvinilWebhook::class);
    }

    // ------------------------------------------------------------------
    // O processamento
    // ------------------------------------------------------------------

    /** A requisição só grava e enfileira: quem fala com a Omie é o job. */
    public function test_a_requisicao_nao_processa_nada(): void
    {
        Queue::fake();

        $this->enviar(['lote' => 'SUV-001', 'resgates' => [$this->resgate()]])
            ->assertOk()
            ->assertJson(['received' => true, 'lote' => 'SUV-001', 'resgates' => 1]);

        $this->assertDatabaseCount('appnoz_imports', 0);
        Queue::assertPushed(ProcessSuvinilWebhook::class);
    }

    public function test_o_lote_vira_uma_importacao_parceiros_com_pedido_na_omie(): void
    {
        $this->enviar(['lote' => 'SUV-002', 'resgates' => [$this->resgate()]])->assertOk();

        $import = AppnozImport::firstOrFail();
        $this->assertSame(AppnozImport::SOURCE_WEBHOOK, $import->source);
        $this->assertSame(AppnozImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->rows_created);

        $row = $import->rows()->first();
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);
        $this->assertNotNull($row->omie_order_id);

        // Sem cabeçalho no corpo, o primeiro resgate é a linha 1.
        $this->assertSame(1, $row->row_number);

        $this->assertNotNull(app(OmieGateway::class)->findCustomerByDocument('52998224725'));
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::firstOrFail()->status);
    }

    /**
     * O reenvio é o caso que custa dinheiro: dois Pedidos de Venda para o
     * mesmo resgate viram dois brindes despachados.
     */
    public function test_o_mesmo_lote_reenviado_nao_duplica_o_pedido(): void
    {
        $corpo = ['lote' => 'SUV-003', 'resgates' => [$this->resgate()]];

        $this->enviar($corpo)->assertOk();
        $this->enviar($corpo)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('appnoz_imports', 1);
        $this->assertSame(1, AppnozImportRow::count());
    }

    /**
     * Reenvio com uma linha a mais tem outro hash de corpo. Se a trava fosse
     * o conteúdo, o lote inteiro entraria de novo.
     */
    public function test_a_trava_e_o_lote_e_nao_o_conteudo(): void
    {
        $this->enviar(['lote' => 'SUV-004', 'resgates' => [$this->resgate()]])->assertOk();

        $this->enviar([
            'lote' => 'SUV-004',
            'resgates' => [$this->resgate(), $this->resgate('15350946056')],
        ])->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, AppnozImportRow::count());
    }

    /** Linha inválida bloqueia só a si mesma, como na planilha. */
    public function test_linha_invalida_bloqueia_apenas_a_si_mesma(): void
    {
        $ruim = $this->resgate('123');
        $ruim['cidade'] = '';

        $this->enviar(['lote' => 'SUV-005', 'resgates' => [$this->resgate(), $ruim]])->assertOk();

        $import = AppnozImport::firstOrFail();
        $this->assertSame(AppnozImport::STATUS_COMPLETED_WITH_BLOCKS, $import->status);
        $this->assertSame(1, $import->rows_created);
        $this->assertSame(1, $import->rows_blocked);

        $bloqueada = $import->rows()->where('status', AppnozImportRow::STATUS_BLOCKED_STRUCTURAL)->first();
        $this->assertSame(2, $bloqueada->row_number);
        $this->assertNotNull($bloqueada->block_reason);
    }

    /**
     * O webhook fala a mesma língua do relatório: o parceiro manda os campos
     * com os nomes da appnoz e entra igual. Uma porta que aceitasse só o nosso
     * template obrigaria a Suvinil a manter duas tradções do mesmo dado.
     */
    public function test_aceita_os_campos_com_os_nomes_da_appnoz(): void
    {
        $this->enviar(['lote' => 'SUV-007', 'resgates' => [[
            'ID Produto' => 'SUV-PINCEL-01',
            'Nome do Produto' => 'Kit Pincéis',
            'ID Solicitação' => '125635',
            'Usuário solicitante' => 'Fulano de Tal',
            'CPF' => '314.159.265-90',
            'Telefone' => '(45)99999-1234',
            'Email' => 'cliente@example.com',
            'Rua' => 'Rua Exemplo',
            'Número' => '43',
            'Complemento' => 'casa 2',
            'Bairro' => 'Morumbi',
            'CEP' => '80000-000',
            'Cidade' => 'Cascavel',
            'Estado' => 'Paraná',
        ]]])->assertOk();

        $row = AppnozImportRow::firstOrFail();
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status, (string) $row->block_reason);
        $this->assertSame('PR', $row->state);
        $this->assertSame('31415926590', $row->cpf);
        $this->assertSame('casa 2', $row->complement);
        $this->assertSame('125635', $row->external_id);
        $this->assertSame(1, $row->quantity);
    }

    /**
     * A trava do ID Solicitação vale entre as duas portas: resgate que já
     * entrou pelo relatório não entra de novo pelo webhook.
     */
    public function test_solicitacao_ja_conhecida_nao_entra_pelo_webhook(): void
    {
        $resgate = $this->resgate() + ['id_solicitacao' => '125635'];

        $this->enviar(['lote' => 'SUV-008', 'resgates' => [$resgate]])->assertOk();
        $this->enviar(['lote' => 'SUV-009', 'resgates' => [$resgate]])->assertOk();

        $this->assertSame(1, AppnozImportRow::count());
    }

    /**
     * A Omie recusa no meio ("Consumo redundante, aguarde 59 segundos"): a
     * nova tentativa da fila CONTINUA o lote e o resgate vira pedido. Antes
     * ela via o lote aberto, dava o evento por processado e o resgate ficava
     * em "Recebido" para sempre.
     */
    public function test_retentativa_depois_de_recusa_da_omie_cria_o_pedido(): void
    {
        Queue::fake();
        $this->enviar(['lote' => 'SUV-007', 'resgates' => [$this->resgate()]])->assertOk();
        $evento = WebhookEvent::firstOrFail();

        $chamadas = 0;
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('findProductByCode')->andReturn(['omie_code' => 'SUV-PINCEL-01', 'name' => 'Kit Pincéis']);
        $omie->shouldReceive('findCustomerByDocument')->andReturn(null);
        $omie->shouldReceive('upsertCustomer')->andReturn(['omie_customer_code' => '7788']);
        $omie->shouldReceive('createOrder')->andReturnUsing(function () use (&$chamadas) {
            if (++$chamadas === 1) {
                throw new OmieApiException('Omie ListarClientes falhou: ERROR: Consumo redundante detectado. Aguarde 59 segundos para tentar novamente (REDUNDANT).');
            }

            return ['omie_order_id' => '1', 'omie_order_number' => 'PV-1'];
        });

        try {
            (new ProcessSuvinilWebhook($evento->id))->handle(app(IngestPartnerRows::class));
            $this->fail('A recusa da Omie deveria derrubar a primeira tentativa.');
        } catch (OmieApiException) {
        }

        $this->assertSame(AppnozImportRow::STATUS_PENDING, AppnozImportRow::firstOrFail()->status);
        $this->assertSame(WebhookEvent::STATUS_FAILED, $evento->fresh()->status);

        // A fila tenta de novo depois do intervalo (IntegrationJob::backoff).
        (new ProcessSuvinilWebhook($evento->id))->handle(app(IngestPartnerRows::class));

        $row = AppnozImportRow::firstOrFail();
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);
        $this->assertSame('PV-1', $row->omie_order_number);
        $this->assertSame(1, AppnozImportRow::count());
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $evento->fresh()->status);
        $this->assertNull(AppnozImport::firstOrFail()->error);
    }

    /** Retentativa do job sobre um lote já ingerido não reprocessa nada. */
    public function test_retentativa_do_job_nao_reprocessa_o_lote(): void
    {
        $this->enviar(['lote' => 'SUV-006', 'resgates' => [$this->resgate()]])->assertOk();

        (new ProcessSuvinilWebhook(WebhookEvent::firstOrFail()->id))
            ->handle(app(IngestPartnerRows::class));

        $this->assertDatabaseCount('appnoz_imports', 1);
        $this->assertSame(1, AppnozImportRow::count());
    }
}
