<?php

namespace Tests\Feature;

use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\PartnerSku;
use App\Models\Product;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Resgate mandado pela URL da Reconhece.
 *
 * É a Reconhece que manda catálogo E resgate, pela mesma URL. Antes, o resgate
 * caía no cadastro de produto, voltava "nenhum produto reconhecido" e não
 * virava pedido. O corpo decide: envelope de lote é resgate, `sku` é produto.
 */
class ReconheceResgateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste-da-reconhece';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();

        config()->set('integrations.reconhece.webhook_token', self::TOKEN);

        // O vínculo procura em Produtos (o espelho da Omie); o seeder não
        // espelha o kit que o gateway fake conhece.
        Product::firstOrCreate(
            ['omie_code' => 'SUV-PINCEL-01'],
            ['name' => 'Kit Pincéis Profissional Suvinil', 'stock_qty' => 100, 'stock_synced_at' => now()],
        );
    }

    /** As colunas da planilha da Reconhece, opcionais inclusive. */
    private function resgate(string $id = '125635', string $produto = 'SUV-PINCEL-01'): array
    {
        return [
            'ID Produto' => $produto,
            'Nome do Produto' => 'Kit Pincéis',
            'Valor do Produto' => '220.000',
            'ID Solicitação' => $id,
            'Data de solicitação' => '22/09/2026 às 23:09',
            'Status' => 'solicitado',
            'Usuário solicitante' => 'Fulano de Tal',
            'CPF' => '529.982.247-25',
            'Atividade' => 'Vendedores/Balconista',
            'Telefone' => '(45)99999-1234',
            'Email' => 'teste@welog.local',
            'Data Nascimento' => '31/01/1997',
            'Rua' => 'Rua Exemplo',
            'Número' => '43',
            'Complemento' => 'casa 2',
            'Bairro' => 'Morumbi',
            'CEP' => '80000-000',
            'Cidade' => 'Cascavel',
            'Estado' => 'Paraná',
            'Tags' => 'Todos,Vendedores/Balconista',
        ];
    }

    private function enviar(array $corpo)
    {
        return $this->postJson('/webhooks/reconhece/'.self::TOKEN, $corpo);
    }

    public function test_resgate_pela_url_da_reconhece_vira_pedido_na_omie(): void
    {
        $this->enviar(['lote' => 'REC-001', 'resgates' => [$this->resgate()]])
            ->assertOk()
            ->assertJson(['received' => true, 'lote' => 'REC-001', 'resgates' => 1]);

        $evento = WebhookEvent::firstOrFail();
        $this->assertSame(WebhookEvent::SOURCE_RECONHECE, $evento->source);
        $this->assertSame('Resgate.Lote', $evento->topic);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $evento->status);

        $import = AppnozImport::firstOrFail();
        $this->assertSame('reconhece-REC-001', $import->filename);

        $row = $import->rows()->firstOrFail();
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);
        $this->assertSame('125635', $row->external_id);
        $this->assertSame('PR', $row->state);
        $this->assertNotNull($row->omie_order_number);

        // Resgate não é cadastro de produto: nada entra no catálogo.
        $this->assertDatabaseCount('partner_skus', 0);
    }

    /** O SKU da vitrine é traduzido pelo vínculo do catálogo. */
    public function test_resgate_com_sku_da_reconhece_acha_o_produto_pelo_vinculo(): void
    {
        $this->enviar([
            'sku' => '1034',
            'product' => ['parentCode' => 'SUV-PINCEL-01', 'name' => 'Pincel'],
        ])->assertOk()->assertJson(['vinculados' => 1]);

        $this->assertSame('1034', PartnerSku::firstOrFail()->sku);

        $this->enviar(['lote' => 'REC-002', 'resgates' => [$this->resgate('125636', '1034')]])->assertOk();

        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, AppnozImportRow::firstOrFail()->status);
    }

    public function test_o_mesmo_lote_reenviado_nao_duplica(): void
    {
        $corpo = ['lote' => 'REC-003', 'resgates' => [$this->resgate()]];

        $this->enviar($corpo)->assertOk();
        $this->enviar($corpo)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, AppnozImportRow::count());
    }

    /** Lista pura de itens sem `sku` também é resgate. */
    public function test_lista_pura_sem_sku_e_resgate(): void
    {
        $this->enviar([$this->resgate()])->assertOk()->assertJson(['resgates' => 1]);

        $this->assertSame(1, AppnozImportRow::count());
    }

    /** O cadastro de produto continua igual pela mesma URL. */
    public function test_cadastro_de_produto_continua_funcionando(): void
    {
        $this->enviar(['sku' => 'SUV-PINCEL-01', 'product' => ['name' => 'Kit Pincéis']])
            ->assertOk()
            ->assertJson(['itens' => 1]);

        $this->assertDatabaseCount('appnoz_imports', 0);
    }
}
