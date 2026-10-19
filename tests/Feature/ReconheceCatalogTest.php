<?php

namespace Tests\Feature;

use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\PartnerSku;
use App\Models\Product;
use App\Models\WebhookEvent;
use App\Services\FluxoB\ImportAppnozFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O catálogo que a Reconhece cadastra e nos avisa.
 *
 * É o par do webhook de resgates, na direção oposta: por lá entram os pedidos,
 * por aqui entra o cadastro que dá sentido a eles. O resgate chega com o código
 * da Reconhece, e é o vínculo guardado aqui que diz qual kit da Omie expedir.
 *
 * Sem isso, todo resgate de um catálogo com código próprio trava em "produto
 * não cadastrado" — o único bloqueio do fluxo, e o que aparece no pior momento
 * possível, com a campanha já rodando.
 */
class ReconheceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste-da-reconhece';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();

        config()->set('integrations.reconhece.webhook_token', self::TOKEN);

        /*
         * O kit precisa existir nos DOIS lugares, e eles são diferentes.
         *
         * O vínculo procura na tabela `products` (o espelho da Omie); a criação
         * do Pedido de Venda pergunta ao gateway. `SUV-PINCEL-01` já vem no
         * catálogo do gateway fake, mas o seeder não o espelha — e foi
         * exatamente isso que quebrou estes testes da primeira vez.
         */
        $this->produto = Product::firstOrCreate(
            ['omie_code' => 'SUV-PINCEL-01'],
            [
                'name' => 'Kit Pincéis Profissional Suvinil',
                'stock_qty' => 100,
                'stock_synced_at' => now(),
            ],
        );
    }

    private Product $produto;

    private function sku(array $extra = []): array
    {
        return array_replace_recursive([
            'sku' => 'SUV-PINCEL-01',
            'enabled' => true,
            'product' => [
                'parentCode' => 'SUV-PINCEL-01',
                'name' => 'Kit Pincéis Profissional Suvinil',
                'description' => 'Conjunto com três pincéis profissionais.',
                'productType' => 'Casa e Construção',
                'department' => 'Ferramentas',
            ],
            'images' => ['https://cdn.reconhece.com.br/suv-pincel-01.jpg'],
        ], $extra);
    }

    private function enviar(array $corpo, string $token = self::TOKEN)
    {
        return $this->postJson("/webhooks/reconhece/{$token}", $corpo);
    }

    // ------------------------------------------------------------------
    // A porta
    // ------------------------------------------------------------------

    public function test_token_errado_e_recusado(): void
    {
        $this->enviar($this->sku(), 'errado')->assertForbidden();

        $this->assertDatabaseCount('partner_skus', 0);
    }

    public function test_sem_token_configurado_a_rota_fica_fechada(): void
    {
        config()->set('integrations.reconhece.webhook_token', '');

        $this->enviar($this->sku())->assertForbidden();
    }

    public function test_a_url_responde_a_verificacao(): void
    {
        $this->getJson('/webhooks/reconhece/'.self::TOKEN)
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    /** A chave no header, como a Reconhece pediu, sem token na URL. */
    public function test_chave_no_header_e_aceita(): void
    {
        $this->postJson('/webhooks/reconhece', $this->sku(), ['X-API-Key' => self::TOKEN])->assertOk();
        $this->postJson('/webhooks/reconhece', $this->sku(), ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();
        $this->postJson('/webhooks/reconhece', $this->sku(), ['Authorization' => self::TOKEN])->assertOk();

        $this->assertDatabaseCount('partner_skus', 1);
    }

    public function test_chave_errada_ou_ausente_no_header_e_recusada(): void
    {
        $this->postJson('/webhooks/reconhece', $this->sku(), ['X-API-Key' => 'errado'])->assertForbidden();
        $this->postJson('/webhooks/reconhece', $this->sku())->assertForbidden();

        $this->assertDatabaseCount('partner_skus', 0);
    }

    public function test_verificacao_com_a_chave_no_header(): void
    {
        $this->getJson('/webhooks/reconhece', ['X-API-Key' => self::TOKEN])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->getJson('/webhooks/reconhece')->assertForbidden();
    }

    // ------------------------------------------------------------------
    // O cadastro
    // ------------------------------------------------------------------

    public function test_o_cadastro_e_guardado_com_categoria_e_imagem(): void
    {
        $this->enviar($this->sku())->assertOk()->assertJson(['itens' => 1]);

        $item = PartnerSku::firstOrFail();
        $this->assertSame('SUV-PINCEL-01', $item->sku);
        $this->assertSame('Kit Pincéis Profissional Suvinil', $item->name);
        $this->assertSame('Casa e Construção', $item->product_type);
        $this->assertSame('Ferramentas', $item->department);
        $this->assertSame(['https://cdn.reconhece.com.br/suv-pincel-01.jpg'], $item->images);
        $this->assertTrue($item->enabled);
        $this->assertTrue($item->isAvulso());

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::firstOrFail()->status);
    }

    /** Uma chamada pode trazer o catálogo inteiro. */
    public function test_aceita_uma_lista_de_itens(): void
    {
        $this->enviar(['skus' => [
            $this->sku(),
            $this->sku(['sku' => 'SUV-TRENA-01', 'product' => ['parentCode' => 'SUV-TRENA-01', 'name' => 'Trena']]),
        ]])->assertOk()->assertJson(['itens' => 2]);

        $this->assertSame(2, PartnerSku::count());
    }

    /**
     * Reenvio byte a byte idêntico é o que um sync de catálogo faz: é a forma
     * normal de dizer "continua tudo assim". Antes disso ele batia na
     * constraint de unicidade do evento e o parceiro levava 500.
     */
    public function test_reenvio_identico_nao_estoura(): void
    {
        $this->enviar($this->sku())->assertOk();
        $this->enviar($this->sku())->assertOk()->assertJson(['itens' => 1]);

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, PartnerSku::count());
    }

    /** Reenvio do mesmo SKU atualiza; não duplica. */
    public function test_reenvio_atualiza_o_mesmo_sku(): void
    {
        $this->enviar($this->sku())->assertOk();
        $this->enviar($this->sku(['product' => ['name' => 'Kit Pincéis (novo nome)']]))->assertOk();

        $this->assertSame(1, PartnerSku::count());
        $this->assertSame('Kit Pincéis (novo nome)', PartnerSku::firstOrFail()->name);
    }

    /** Variação: o parceiro chama de MODELO, e os atributos vêm soltos. */
    public function test_variacao_guarda_cor_e_tamanho_e_o_produto_pai(): void
    {
        $this->enviar($this->sku([
            'sku' => 'MOLETOM-PRETO-G',
            'skuName' => 'Moletom preto, tamanho G',
            'color' => 'Preto',
            'size' => 'G',
            'product' => ['parentCode' => 'MOLETOM', 'name' => 'Moletom com Capuz'],
        ]))->assertOk();

        $item = PartnerSku::where('sku', 'MOLETOM-PRETO-G')->firstOrFail();
        $this->assertFalse($item->isAvulso());
        $this->assertSame('MOLETOM', $item->parent_code);
        $this->assertSame('Moletom preto, tamanho G', $item->variacao());
    }

    /** Sem `skuName`, o rótulo é montado a partir dos atributos que vieram. */
    public function test_a_variacao_se_descreve_pelos_atributos_quando_falta_o_nome(): void
    {
        $this->enviar($this->sku([
            'sku' => 'VENT-BRANCO-110',
            'color' => 'Branco',
            'voltage' => '110V',
            'product' => ['parentCode' => 'VENTILADOR', 'name' => 'Ventilador'],
        ]))->assertOk();

        $this->assertSame('Branco · 110V', PartnerSku::where('sku', 'VENT-BRANCO-110')->firstOrFail()->variacao());
    }

    /** Corpo que não reconhecemos fica guardado, como no webhook de resgates. */
    public function test_corpo_nao_reconhecido_e_guardado_e_nao_recusado(): void
    {
        $this->enviar(['catalogo' => 'algo diferente'])
            ->assertOk()
            ->assertJsonPath('itens', 0);

        $this->assertSame(WebhookEvent::STATUS_IGNORED, WebhookEvent::firstOrFail()->status);
        $this->assertDatabaseCount('partner_skus', 0);
    }

    // ------------------------------------------------------------------
    // O vínculo
    // ------------------------------------------------------------------

    /** Código igual dos dois lados é o caso comum: amarra sozinho. */
    public function test_amarra_sozinho_quando_o_codigo_bate_com_a_omie(): void
    {
        $produto = $this->produto;

        $this->enviar($this->sku())->assertOk()->assertJson(['vinculados' => 1, 'sem_vinculo' => []]);

        $this->assertSame($produto->id, PartnerSku::firstOrFail()->product_id);
    }

    /**
     * Com variação, o código da Omie costuma ser o do produto-pai: cada cor
     * vira um SKU derivado. Sem esta segunda tentativa, nenhum item com
     * variação casaria sozinho.
     */
    public function test_amarra_pelo_produto_pai_quando_o_sku_e_variacao(): void
    {
        $produto = $this->produto;

        $this->enviar($this->sku([
            'sku' => 'SUV-PINCEL-01-AZUL',
            'color' => 'Azul',
            'product' => ['parentCode' => 'SUV-PINCEL-01'],
        ]))->assertOk();

        $this->assertSame($produto->id, PartnerSku::where('sku', 'SUV-PINCEL-01-AZUL')->firstOrFail()->product_id);
    }

    /**
     * Código que não existe na Omie fica SEM vínculo, e a resposta nomeia o
     * SKU: quem está do outro lado é justamente quem consegue corrigir, e
     * devolver só "recebido" empurraria a descoberta para o primeiro resgate
     * travado.
     */
    public function test_o_que_nao_casa_fica_sem_vinculo_e_e_nomeado_na_resposta(): void
    {
        $this->enviar($this->sku([
            'sku' => 'NAO-EXISTE-NA-OMIE',
            'product' => ['parentCode' => 'NAO-EXISTE-NA-OMIE'],
        ]))->assertOk()->assertJson([
            'vinculados' => 0,
            'sem_vinculo' => ['NAO-EXISTE-NA-OMIE'],
        ]);

        $this->assertNull(PartnerSku::firstOrFail()->product_id);
    }

    /**
     * Reenvio não desfaz amarração feita à mão.
     *
     * Quem amarrou um SKU de código divergente fez isso porque o automático
     * não deu conta; recalcular a cada reenvio apagaria justamente o trabalho
     * que ninguém quer refazer.
     */
    public function test_reenvio_nao_desfaz_vinculo_manual(): void
    {
        $this->enviar($this->sku(['sku' => 'CODIGO-DELES', 'product' => ['parentCode' => 'CODIGO-DELES']]))->assertOk();

        $item = PartnerSku::firstOrFail();
        $produto = $this->produto;
        $item->update(['product_id' => $produto->id, 'linked_at' => now()]);

        $this->enviar($this->sku(['sku' => 'CODIGO-DELES', 'product' => ['parentCode' => 'CODIGO-DELES']]))->assertOk();

        $this->assertSame($produto->id, $item->fresh()->product_id);
    }

    // ------------------------------------------------------------------
    // O resgate, que é o motivo de tudo isso existir
    // ------------------------------------------------------------------

    private function importar(string $codigoDoProduto): AppnozImportRow
    {
        $caminho = sys_get_temp_dir().'/reconhece-'.uniqid().'.csv';
        file_put_contents($caminho, implode("\n", [
            'cpf,nome,email,telefone,codigo_produto,produto,quantidade,cep,endereco,numero,complemento,bairro,cidade,uf',
            "52998224725,José Pintor,jose@pintores.com.br,41988887777,{$codigoDoProduto},Kit,1,80010000,Rua A,45,,Centro,Curitiba,PR",
        ])."\n");

        return app(ImportAppnozFile::class)
            ->handle($caminho, AppnozImport::SOURCE_UPLOAD)
            ->rows()
            ->firstOrFail();
    }

    /** O resgate com o código DELES vira pedido, porque o vínculo traduz. */
    public function test_resgate_com_o_codigo_do_parceiro_vira_pedido(): void
    {
        $produto = $this->produto;

        PartnerSku::create([
            'source' => PartnerSku::SOURCE_RECONHECE,
            'sku' => 'RECONHECE-9911',
            'parent_code' => 'RECONHECE-9911',
            'name' => 'Kit Pincéis',
            'product_id' => $produto->id,
            'linked_at' => now(),
        ]);

        $linha = $this->importar('RECONHECE-9911');

        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $linha->status, (string) $linha->block_reason);
        $this->assertNotNull($linha->omie_order_id);
    }

    /**
     * SKU conhecido mas sem vínculo trava com a mensagem CERTA.
     *
     * "Não existe na Omie" e "existe no catálogo mas ninguém amarrou" pedem
     * ações opostas: cadastrar um produto, ou ligar dois registros que já
     * existem. Uma mensagem só mandaria metade das pessoas para o lugar errado.
     */
    public function test_sku_sem_vinculo_trava_dizendo_o_que_fazer(): void
    {
        PartnerSku::create([
            'source' => PartnerSku::SOURCE_RECONHECE,
            'sku' => 'RECONHECE-7722',
            'parent_code' => 'RECONHECE-7722',
            'name' => 'Trena',
        ]);

        $linha = $this->importar('RECONHECE-7722');

        $this->assertSame(AppnozImportRow::STATUS_BLOCKED_PRODUCT, $linha->status);
        $this->assertStringContainsString('não está vinculado', $linha->block_reason);
    }

    /** Parceiro que já usa o código da Omie continua funcionando sem vínculo. */
    public function test_codigo_da_omie_continua_entrando_sem_vinculo_nenhum(): void
    {
        $linha = $this->importar('SUV-PINCEL-01');

        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $linha->status, (string) $linha->block_reason);
    }
}
