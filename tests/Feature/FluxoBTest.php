<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Jobs\FluxoB\PollFlashTracking;
use App\Jobs\FluxoB\SyncOmieInvoices;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\User;
use App\Services\FluxoB\ImportAppnozFile;
use App\Services\FluxoB\ProcessAppnozRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fluxo B ponta a ponta com gateways fake: importação do relatório appnoz →
 * validações → pedido na Omie → NF → despacho Flash → rastreio até entrega.
 */
class FluxoBTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
    }

    private function makeCsv(array $lines): string
    {
        $header = 'cpf,nome,email,telefone,codigo_produto,produto,quantidade,cep,endereco,numero,complemento,bairro,cidade,uf';
        $path = sys_get_temp_dir().'/appnoz-'.uniqid().'.csv';
        file_put_contents($path, implode("\n", [$header, ...$lines])."\n");

        return $path;
    }

    private function validLine(string $cpf = '52998224725', string $product = 'SUV-PINCEL-01'): string
    {
        return "{$cpf},José Pintor,jose@pintores.com.br,41988887777,{$product},Kit Pincéis,2,80010000,Rua das Tintas,45,,Centro,Curitiba,PR";
    }

    public function test_importacao_cria_pedido_na_omie_com_cliente_automatico_e_beneficio(): void
    {
        $path = $this->makeCsv([$this->validLine()]);

        $import = app(ImportAppnozFile::class)->handle($path, AppnozImport::SOURCE_UPLOAD);

        $this->assertSame(AppnozImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->rows_created);

        $row = $import->rows()->first();
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);
        $this->assertTrue($row->customer_auto_created);
        $this->assertNotNull($row->omie_order_id);
        $this->assertSame('PR830015', $row->benefit_code); // parametrização do seed
        $this->assertNotNull($row->trello_card_id);
        $this->assertSame('assembly', $row->trello_stage);

        // Cliente ficou cadastrado na Omie (fake)
        $omie = app(OmieGateway::class);
        $this->assertNotNull($omie->findCustomerByDocument('52998224725'));

        // Pedido marcado como Importação
        $order = $omie->getOrder($row->omie_order_id);
        $this->assertSame('Importação', $order['marcador']);
    }

    public function test_cliente_ja_cadastrado_nao_e_recriado(): void
    {
        app(OmieGateway::class)->upsertCustomer([
            'codigo_cliente_integracao' => '52998224725',
            'cnpj_cpf' => '52998224725',
            'razao_social' => 'José Pintor',
        ]);

        $import = app(ImportAppnozFile::class)->handle(
            $this->makeCsv([$this->validLine()]),
            AppnozImport::SOURCE_UPLOAD,
        );

        $this->assertFalse($import->rows()->first()->customer_auto_created);
    }

    public function test_produto_nao_cadastrado_bloqueia_apenas_a_linha(): void
    {
        $path = $this->makeCsv([
            $this->validLine(),
            $this->validLine('15350946056', 'PRODUTO-INEXISTENTE'),
        ]);

        $import = app(ImportAppnozFile::class)->handle($path, AppnozImport::SOURCE_UPLOAD);

        $this->assertSame(AppnozImport::STATUS_COMPLETED_WITH_BLOCKS, $import->status);
        $this->assertSame(1, $import->rows_created);
        $this->assertSame(1, $import->rows_blocked);

        $blocked = $import->rows()->where('status', AppnozImportRow::STATUS_BLOCKED_PRODUCT)->first();
        $this->assertStringContainsString('PRODUTO-INEXISTENTE', $blocked->block_reason);

        // A outra linha seguiu normalmente (única condição bloqueante é por linha)
        $this->assertSame(
            AppnozImportRow::STATUS_ORDER_CREATED,
            $import->rows()->where('cpf', '52998224725')->first()->status,
        );
    }

    public function test_erro_estrutural_bloqueia_linha_com_mensagem(): void
    {
        // CPF inválido (não tem 11 dígitos) e sem cidade
        $path = $this->makeCsv(['123,Fulano,,,SUV-PINCEL-01,Kit,1,80010000,Rua A,10,,Centro,,PR']);

        $import = app(ImportAppnozFile::class)->handle($path, AppnozImport::SOURCE_UPLOAD);

        $row = $import->rows()->first();
        $this->assertSame(AppnozImportRow::STATUS_BLOCKED_STRUCTURAL, $row->status);
        $this->assertNotNull($row->block_reason);
    }

    public function test_reimportar_mesmo_arquivo_e_rejeitado(): void
    {
        $path = $this->makeCsv([$this->validLine()]);

        app(ImportAppnozFile::class)->handle($path, AppnozImport::SOURCE_UPLOAD);

        $this->expectExceptionMessage('já foi importado');
        app(ImportAppnozFile::class)->handle($path, AppnozImport::SOURCE_UPLOAD);
    }

    public function test_reprocessamento_nao_duplica_pedido_na_omie(): void
    {
        $import = app(ImportAppnozFile::class)->handle(
            $this->makeCsv([$this->validLine()]),
            AppnozImport::SOURCE_UPLOAD,
        );

        $row = $import->rows()->first();
        $firstOrderId = $row->omie_order_id;

        // Reprocessa a mesma linha: idempotência por codigo_pedido_integracao
        app(ProcessAppnozRow::class)->handle($row);

        $this->assertSame($firstOrderId, $row->fresh()->omie_order_id);
    }

    public function test_ciclo_fiscal_e_flash_ate_entrega(): void
    {
        $import = app(ImportAppnozFile::class)->handle(
            $this->makeCsv([$this->validLine()]),
            AppnozImport::SOURCE_UPLOAD,
        );
        $row = $import->rows()->first();

        // NF emitida pela Omie é vinculada à linha
        (new SyncOmieInvoices)->handle(app(OmieGateway::class));
        $row->refresh();
        $this->assertSame(AppnozImportRow::STATUS_INVOICED, $row->status);
        $this->assertNotNull($row->nf_key);

        // Sem envio de XML: o rastreio começa no faturamento. A primeira
        // movimentação da Flash põe o pedido em transporte.
        $flash = app(\App\Contracts\Integrations\FlashGateway::class);
        (new PollFlashTracking)->handle($flash);
        $row->refresh();
        $this->assertSame(AppnozImportRow::STATUS_DISPATCHED, $row->status);
        $this->assertNotNull($row->tracking_code);

        // Fake avança um estágio por consulta até a entrega
        (new PollFlashTracking)->handle($flash);
        (new PollFlashTracking)->handle($flash);

        $row->refresh();
        $this->assertSame(AppnozImportRow::STATUS_DELIVERED, $row->status);
        $this->assertNotNull($row->delivered_at);
        // Três estágios, três eventos: o mesmo evento em consultas seguidas
        // não pode ser gravado de novo.
        $this->assertSame(3, $row->trackingEvents()->count());
    }
}
