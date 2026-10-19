<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Product;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedido que chega pelo webhook com os tamanhos de uma grade (COLETE_1,
 * COLETE_2): pelo prefixo do código, o pai (COLETE) entra no catálogo junto.
 *
 * Antes só os tamanhos do pedido eram espelhados, e o pai ficava fora da
 * WeLog — os tamanhos apareciam como itens avulsos.
 */
class GradeParentFromWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-grade-webhook';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['integrations.omie.webhook_token' => self::TOKEN]);
        $this->seed();

        $omie = app(OmieGateway::class);
        $omie->addProduct(['omie_code' => 'COLETE', 'name' => 'Colete Sotreq 85 anos']);
        $omie->addProduct(['omie_code' => 'COLETE_1', 'name' => 'Colete Sotreq 85 anos G']);
        $omie->addProduct(['omie_code' => 'COLETE_2', 'name' => 'Colete Sotreq 85 anos M']);
    }

    /** @param  array<string, int>  $itens  código => quantidade */
    private function pedido(string $integracao, array $itens): string
    {
        $pedido = app(OmieGateway::class)->createOrder([
            'codigo_pedido_integracao' => $integracao,
            'cabecalho' => [
                'numero_pedido' => 'PV-'.crc32($integracao),
                'codigo_cliente' => 'CLI-00085',
                'data_previsao' => now()->addDays(30)->toDateString(),
            ],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'Sotreq S.A.'],
            'det' => collect($itens)
                ->map(fn (int $quantidade, string $codigo) => ['produto' => ['codigo' => $codigo, 'quantidade' => $quantidade]])
                ->values()
                ->all(),
        ])['omie_order_id'];

        $this->postJson('/webhooks/omie/'.self::TOKEN, [
            'messageId' => 'msg-grade-'.$pedido,
            'topic' => 'VendaProduto.Incluida',
            'event' => ['idPedido' => $pedido],
        ])->assertOk();

        return $pedido;
    }

    public function test_o_pai_da_grade_entra_no_catalogo_e_o_pedido_segue_por_tamanho(): void
    {
        $pedido = $this->pedido('grade-1', ['COLETE_1' => 5, 'COLETE_2' => 10]);

        $projeto = Project::where('omie_order_id', $pedido)->firstOrFail();

        // O pai, com o nome da Omie, no catálogo...
        $this->assertSame('Colete Sotreq 85 anos', Product::where('omie_code', 'COLETE')->value('name'));

        // ...e o pedido continua por tamanho, com a quantidade de cada um.
        $this->assertEqualsCanonicalizing(
            ['COLETE_1' => 5, 'COLETE_2' => 10],
            $projeto->products->mapWithKeys(fn (Product $p) => [$p->omie_code => (int) $p->pivot->quantity])->all(),
        );
    }

    public function test_pedido_com_o_pai_traz_os_tamanhos_da_omie_para_a_escolha(): void
    {
        // Nenhum tamanho no catálogo da WeLog: ninguém rodou "Importar da Omie".
        $this->assertFalse(Product::where('omie_code', 'like', 'COLETE%')->exists());

        $pedido = $this->pedido('grade-pai', ['COLETE' => 20]);

        $projeto = Project::where('omie_order_id', $pedido)->firstOrFail();

        // O pedido continua sendo o pai × 20...
        $this->assertSame(['COLETE'], $projeto->products->pluck('omie_code')->all());

        // ...e os tamanhos vieram da Omie para a escolha no portal.
        $this->assertSame(
            ['M', 'G'],
            Product::where('omie_code', 'COLETE')->firstOrFail()->opcoesDeVariacao()->pluck('size')->all(),
        );
    }

    /**
     * O tamanho vem da característica da Omie ("Tamanho Corta Vento: PP"),
     * não do nome — que aqui nem diz o tamanho.
     */
    public function test_tamanho_sai_da_caracteristica_da_omie(): void
    {
        $omie = app(OmieGateway::class);
        $omie->addProduct(['omie_code' => 'JAQ', 'name' => 'Jaqueta corta vento unissex + Necessaire']);
        $omie->addProduct([
            'omie_code' => 'JAQ_1',
            'name' => 'Jaqueta corta vento Unissex + Necessaire',
            'caracteristicas' => [['nCodCaract' => 1, 'cNomeCaract' => 'Tamanho Corta Vento', 'cConteudo' => 'PP', 'cExibirItemNF' => 'S']],
        ]);
        $omie->addProduct([
            'omie_code' => 'JAQ_2',
            'name' => 'Jaqueta corta vento Unissex + Necessaire',
            'caracteristicas' => [['nCodCaract' => 1, 'cNomeCaract' => 'Tamanho Corta Vento', 'cConteudo' => 'g']],
        ]);

        $this->pedido('grade-caracteristica', ['JAQ' => 20]);

        // Gravada no formato do catálogo, sem os campos de exibição da Omie.
        $this->assertSame(
            [['nome' => 'Tamanho Corta Vento', 'conteudo' => 'PP']],
            Product::where('omie_code', 'JAQ_1')->firstOrFail()->caracteristicas,
        );

        $this->assertSame(
            ['PP', 'G'],
            Product::where('omie_code', 'JAQ')->firstOrFail()->opcoesDeVariacao()->pluck('size')->all(),
        );
    }

    public function test_produto_sem_grade_nao_reconsulta_a_omie_a_cada_volta(): void
    {
        app(OmieGateway::class)->addProduct(['omie_code' => 'BOLSA', 'name' => 'Bolsa esportiva']);

        $pedido = $this->pedido('sem-grade', ['BOLSA' => 10]);
        $conta = Project::where('omie_order_id', $pedido)->value('omie_account');

        $this->assertSame(['BOLSA'], Product::where('omie_code', 'like', 'BOLSA%')->pluck('omie_code')->all());
        $this->assertTrue(Cache::has('omie:sem-grade:'.($conta ?? '').'|bolsa'));
        $this->assertSame([], Product::where('omie_code', 'BOLSA')->firstOrFail()->opcoesDeVariacao()->all());
    }
}
