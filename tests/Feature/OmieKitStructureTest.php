<?php

namespace Tests\Feature;

use App\Integrations\Omie\OmieAccounts;
use App\Integrations\Omie\OmieApiGateway;
use App\Models\Product;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Estrutura de kit vinda da Omie.
 *
 * O `ConsultarProduto` traz `tipoItem` ("KT") e `componentes_kit`, mas o
 * componente vem pelo ID INTERNO da Omie (`codigo_produto_componente`),
 * enquanto o catálogo daqui é indexado pelo código do produto. Sem traduzir,
 * o kit era espelhado sem nenhum subitem — e o gateway fake, que devolvia a
 * estrutura pronta, escondia isso dos testes.
 */
class OmieKitStructureTest extends TestCase
{
    use RefreshDatabase;

    /** Kit 169 "Kit pipoca", com o produto 170 dentro — o caso real da Suvinil. */
    private function fakeOmie(): void
    {
        Http::fake(function ($request) {
            $param = $request->data()['param'][0] ?? [];

            // Componente pedido pelo ID interno
            if (($param['codigo_produto'] ?? null) === 7605941668) {
                return Http::response(['codigo' => '170', 'descricao' => 'KIT PIPOCA']);
            }

            return Http::response([
                'codigo' => '169',
                'descricao' => 'Kit pipoca Pintou Parceria',
                'tipoItem' => 'KT',
                'componentes_kit' => [[
                    'codigo_produto_componente' => 7605941668,
                    'quantidade_componente' => 2,
                ]],
            ]);
        });
    }

    private function gateway(): OmieApiGateway
    {
        return new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's');
    }

    public function test_kit_e_espelhado_com_seus_subitens(): void
    {
        $this->fakeOmie();

        $kit = (new MirrorOmieProduct(app(OmieAccounts::class), $this->gateway()))->handle('169');

        $this->assertNotNull($kit);
        $this->assertTrue((bool) $kit->is_kit);

        $component = $kit->components()->firstOrFail();
        $this->assertSame('170', $component->omie_code);
        $this->assertSame(2.0, (float) $component->pivot->quantity);
    }

    /** O subitem entra no catálogo com NOME, não com o código no lugar do nome. */
    public function test_subitem_novo_recebe_a_descricao_da_omie(): void
    {
        $this->fakeOmie();

        (new MirrorOmieProduct(app(OmieAccounts::class), $this->gateway()))->handle('169');

        $this->assertSame('KIT PIPOCA', Product::where('omie_code', '170')->value('name'));
    }

    /** Produto sem `componentes_kit` não vira kit por engano. */
    public function test_produto_simples_nao_vira_kit(): void
    {
        Http::fake(['*' => Http::response([
            'codigo' => '170',
            'descricao' => 'KIT PIPOCA',
            'tipoItem' => 'PA',
            'componentes_kit' => null,
        ])]);

        $produto = (new MirrorOmieProduct(app(OmieAccounts::class), $this->gateway()))->handle('170');

        $this->assertFalse((bool) $produto->is_kit);
        $this->assertSame(0, $produto->components()->count());
    }

    /**
     * O mesmo kit aparece em dezenas de pedidos por reconciliação. Resolver o
     * componente uma vez por pedido devolveria a integração à janela de castigo
     * da Omie que já derrubou a fila 243 vezes.
     */
    public function test_componente_e_resolvido_uma_vez_so(): void
    {
        $this->fakeOmie();

        $mirror = new MirrorOmieProduct(app(OmieAccounts::class), $this->gateway());
        $mirror->handle('169');

        $chamadasDoComponente = Http::recorded()
            ->filter(fn ($par) => (($par[0]->data()['param'][0]['codigo_produto'] ?? null) === 7605941668))
            ->count();

        $this->assertSame(1, $chamadasDoComponente);
    }
}
