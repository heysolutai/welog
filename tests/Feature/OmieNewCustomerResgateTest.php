<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieApiGateway;
use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Services\FluxoB\ProcessAppnozRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Resgate de um CPF que ainda não tem cadastro na Omie, contra a API real
 * simulada.
 *
 * A Omie diz "não tem" com HTTP 500 ("Não existem registros para a página
 * [1]!"). O cliente HTTP tratava isso como falha passageira e reenviava a
 * chamada idêntica meio segundo depois — e a Omie recusava a repetição como
 * "consumo redundante". Todo CPF novo travava no primeiro clique.
 */
class OmieNewCustomerResgateTest extends TestCase
{
    use RefreshDatabase;

    private const NAO_EXISTE = ['faultstring' => 'ERROR: Não existem registros para a página [1]!', 'faultcode' => 'SOAP-ENV:Client-5113'];

    private const REDUNDANTE = ['faultstring' => 'ERROR: Consumo redundante detectado. Aguarde 59 segundos para tentar novamente (REDUNDANT).', 'faultcode' => 'SOAP-ENV:Client-6'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['integrations.omie.min_interval_ms' => 0]);
    }

    private function gateway(): OmieApiGateway
    {
        return new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's');
    }

    /** Responde por método; a segunda ListarClientes idêntica seria recusada. */
    private function omie(): void
    {
        $listou = 0;

        Http::fake(function (Request $r) use (&$listou) {
            return match ($r['call']) {
                'ConsultarProduto' => Http::response(['codigo' => '1034', 'descricao' => 'Pincel', 'codigo_produto' => 555, 'valor_unitario' => 22.25]),
                'ListarClientes' => ++$listou === 1
                    ? Http::response(self::NAO_EXISTE, 500)
                    : Http::response(self::REDUNDANTE, 500),
                // Como a Omie: sem uma das duas chaves, recusa.
                'UpsertCliente' => blank($r['param'][0]['codigo_cliente_integracao'] ?? null) && blank($r['param'][0]['codigo_cliente_omie'] ?? null)
                    ? Http::response(['faultstring' => 'ERROR: O preenchimento das tags [codigo_cliente_omie] ou [codigo_cliente_integracao] é obrigatório!'], 500)
                    : Http::response(['codigo_cliente_omie' => 9911, 'codigo_status' => '0']),
                // Como a Omie: campo fora do `cabecalho` é recusado.
                'IncluirPedido' => isset($r['param'][0]['codigo_cliente'])
                    ? Http::response(['faultstring' => 'ERROR: Tag [CODIGO_CLIENTE] não faz parte da estrutura do tipo complexo [pedido_venda_produto]!'], 500)
                    : Http::response(['codigo_pedido' => 424242, 'numero_pedido' => '000000000009107']),
                default => Http::response([], 200),
            };
        });
    }

    public function test_cpf_sem_cadastro_e_uma_resposta_e_nao_e_repetido(): void
    {
        $this->omie();

        $this->assertNull($this->gateway()->findCustomerByDocument('04166533169'));

        Http::assertSentCount(1);
    }

    public function test_resgate_de_cpf_novo_cadastra_o_cliente_e_cria_o_pedido(): void
    {
        $this->omie();
        $this->app->instance(OmieGateway::class, $this->gateway());

        $import = AppnozImport::create([
            'filename' => 'reconhece-TESTE-006', 'file_hash' => 'hash-006', 'source' => AppnozImport::SOURCE_WEBHOOK,
        ]);
        $row = $import->rows()->create([
            'row_number' => 1, 'external_id' => 'TESTE-0006',
            'cpf' => '04166533169', 'customer_name' => 'Yago Machado',
            'product_code' => '1034', 'product_name' => 'Pincel', 'quantity' => 1,
            'zip' => '74920190', 'street' => 'Rua Fagundes Varella', 'number' => 'Qd 58 Lt 2',
            'district' => 'Cidade Satélite São Luiz', 'city' => 'Aparecida de Goiânia', 'state' => 'GO',
            'status' => AppnozImportRow::STATUS_PENDING,
        ]);

        $row = app(ProcessAppnozRow::class)->handle($row);

        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);
        $this->assertTrue($row->customer_auto_created);
        $this->assertSame('9911', $row->omie_customer_code);
        // Gravado como a Omie mostra na tela: sem os zeros da resposta.
        $this->assertSame('9107', $row->omie_order_number);

        // Uma consulta de cliente só: sem a repetição que a Omie recusaria.
        Http::assertSentCount(4);

        // O pedido no formato do pedido 65452, feito à mão na Omie.
        Http::assertSent(function (Request $r) {
            if ($r['call'] !== 'IncluirPedido') {
                return false;
            }

            $pedido = $r['param'][0];

            return $pedido['cabecalho']['codigo_cliente'] === 9911
                && $pedido['cabecalho']['codigo_parcela'] === '000'
                && $pedido['cabecalho']['codigo_cenario_impostos'] === '7310891385'
                && $pedido['cabecalho']['quantidade_itens'] === 1
                && $pedido['det'][0]['produto']['codigo_produto'] === 555
                && $pedido['det'][0]['produto']['valor_unitario'] === 22.25
                && $pedido['informacoes_adicionais']['codigo_categoria'] === '1.01.02'
                && $pedido['informacoes_adicionais']['codigo_conta_corrente'] === 7310887094
                && $pedido['informacoes_adicionais']['codProj'] === 7386090746;
        });

        // Cliente novo vai com o CPF como chave de integração.
        Http::assertSent(fn (Request $r) => $r['call'] === 'UpsertCliente'
            && $r['param'][0]['codigo_cliente_integracao'] === '04166533169');
    }
}
