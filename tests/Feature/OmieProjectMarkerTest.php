<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Models\Project;
use App\Services\FluxoA\CreateProjectFromOmieOrder;
use App\Services\FluxoA\OmieProjectMarker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Só vira Projeto WeLog o pedido MARCADO como tal.
 *
 * A conta da Omie atende a operação inteira da Santa Origem: 532 pedidos só
 * nos últimos 5 dias, e o sistema criava Projeto para todos — foi assim que o
 * banco chegou a 1597 Projetos, quase nenhum de verdade. O que separa um do
 * outro é o texto escrito no campo de observação do pedido.
 */
class OmieProjectMarkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Códigos reais do cadastro de Projetos da conta da Santa Origem. */
    private const PROJETO_VENDA = '7407717220';

    private const PROJETO_SUVINIL = '7386090746';

    private function pedido(string $observacao, ?string $codProj = null): array
    {
        return [
            'cabecalho' => ['numero_pedido' => '66730', 'codigo_cliente' => 'CLI-1'],
            'cliente' => ['cnpj' => '12345678000190', 'razao_social' => 'ACME'],
            'observacoes' => ['obs_venda' => $observacao],
            'informacoes_adicionais' => ['codProj' => $codProj === null ? 0 : (int) $codProj],
            'det' => [['produto' => ['codigo' => 'KIT-ONBOARD-01', 'quantidade' => 10]]],
        ];
    }

    private function criarComPedido(array $order): ?Project
    {
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getOrder')->andReturn($order);
        $omie->shouldReceive('findProductByCode')->andReturn([
            'omie_code' => 'KIT-ONBOARD-01', 'name' => 'Kit', 'components' => [],
        ]);
        $omie->shouldReceive('getCustomer')->andReturn(null);

        return app(CreateProjectFromOmieOrder::class)->handle('7812165921');
    }

    public function test_pedido_com_a_marcacao_vira_projeto(): void
    {
        config(['integrations.omie.project_marker' => 'projeto welog']);

        $this->assertNotNull($this->criarComPedido($this->pedido('Projeto WeLog — Natal 2026')));
        $this->assertSame(1, Project::where('omie_order_id', '7812165921')->count());
    }

    public function test_pedido_sem_a_marcacao_e_ignorado(): void
    {
        config(['integrations.omie.project_marker' => 'projeto welog']);

        $this->assertNull($this->criarComPedido($this->pedido('Venda balcão')));
        $this->assertSame(0, Project::where('omie_order_id', '7812165921')->count());
    }

    public function test_observacao_vazia_e_ignorada(): void
    {
        config(['integrations.omie.project_marker' => 'projeto welog']);

        $this->assertNull($this->criarComPedido($this->pedido('')));
    }

    /** Quem digita escreve como quer: caixa e acento não podem decidir isso. */
    public function test_comparacao_ignora_caixa_e_acento(): void
    {
        config(['integrations.omie.project_marker' => 'projeto welog']);

        $marker = app(OmieProjectMarker::class);

        $this->assertTrue($marker->matches($this->pedido('PROJETO WELOG')));
        $this->assertTrue($marker->matches($this->pedido('  projeto welog  ')));
        $this->assertTrue($marker->matches($this->pedido('Pedido do Projéto Welog')));
    }

    /** Vários termos, porque o texto real ainda está sendo combinado. */
    public function test_aceita_mais_de_um_termo(): void
    {
        config(['integrations.omie.project_marker' => 'projeto welog,kit onboarding wegift']);

        $marker = app(OmieProjectMarker::class);

        $this->assertTrue($marker->matches($this->pedido('Projeto WeLog')));
        $this->assertTrue($marker->matches($this->pedido('Kit onboarding Wegift')));
        $this->assertFalse($marker->matches($this->pedido('Compra de material')));
    }

    /**
     * Vazio = sem filtro, que é o comportamento antigo.
     *
     * É a escolha menos destrutiva das duas: um filtro que entrasse em vigor
     * sozinho, com valor em branco, pararia de criar Projeto de verdade sem
     * ninguém perceber. A pendência aparece na varredura.
     */
    public function test_sem_marcador_configurado_nada_e_filtrado(): void
    {
        config(['integrations.omie.project_marker' => '']);

        $marker = app(OmieProjectMarker::class);

        $this->assertFalse($marker->configured());
        $this->assertTrue($marker->matches($this->pedido('qualquer coisa')));
        $this->assertNotNull($this->criarComPedido($this->pedido('')));
    }

    public function test_varredura_denuncia_marcador_ausente(): void
    {
        config(['integrations.omie.project_marker' => '', 'integrations.omie.project_codes' => '']);

        $this->artisan('welog:projects:audit')
            ->expectsOutputToContain('OMIE_PROJECT_MARKER não configurado')
            ->assertSuccessful();
    }

    // ── Critério por código do Projeto da Omie ──────────────────────────────
    // Campo escolhido numa lista, não digitado: não erra por acento, por caixa
    // nem porque alguém esqueceu de escrever a observação.

    public function test_pedido_do_projeto_venda_vira_projeto(): void
    {
        config(['integrations.omie.project_codes' => self::PROJETO_VENDA]);

        $this->assertNotNull($this->criarComPedido($this->pedido('', self::PROJETO_VENDA)));
        $this->assertSame(1, Project::where('omie_order_id', '7812165921')->count());
    }

    public function test_pedido_do_projeto_suvinil_e_ignorado(): void
    {
        config(['integrations.omie.project_codes' => self::PROJETO_VENDA]);

        $this->assertNull($this->criarComPedido($this->pedido('', self::PROJETO_SUVINIL)));
        $this->assertSame(0, Project::where('omie_order_id', '7812165921')->count());
    }

    /**
     * Pedido sem classificação fica de FORA. É o caso dos 1413 Projetos com
     * nome de pessoa física: entravam por omissão, não por decisão.
     */
    public function test_pedido_sem_projeto_classificado_e_ignorado(): void
    {
        config(['integrations.omie.project_codes' => self::PROJETO_VENDA]);

        $marker = app(OmieProjectMarker::class);

        $this->assertFalse($marker->matches($this->pedido('')));
        $this->assertFalse($marker->matches(['cabecalho' => []]));
        $this->assertFalse($marker->matches(['informacoes_adicionais' => ['codProj' => 0]]));
    }

    /** A Omie devolve o código como inteiro; o `.env` guarda string. */
    public function test_codigo_casa_independente_do_tipo(): void
    {
        config(['integrations.omie.project_codes' => self::PROJETO_VENDA]);

        $marker = app(OmieProjectMarker::class);

        $this->assertSame(self::PROJETO_VENDA, $marker->projectCodeOf([
            'informacoes_adicionais' => ['codProj' => (int) self::PROJETO_VENDA],
        ]));
        $this->assertTrue($marker->matches($this->pedido('', self::PROJETO_VENDA)));
    }

    public function test_aceita_mais_de_um_projeto(): void
    {
        config(['integrations.omie.project_codes' => self::PROJETO_VENDA.','.self::PROJETO_SUVINIL]);

        $marker = app(OmieProjectMarker::class);

        $this->assertTrue($marker->matches($this->pedido('', self::PROJETO_VENDA)));
        $this->assertTrue($marker->matches($this->pedido('', self::PROJETO_SUVINIL)));
        $this->assertFalse($marker->matches($this->pedido('', '9999999999')));
    }

    /**
     * O código VENCE o texto, e não se soma a ele.
     *
     * Somar com "ou" faria um pedido do projeto Suvinil entrar na esteira só
     * por trazer a palavra certa na observação — exatamente o vazamento que o
     * critério por código veio fechar.
     */
    public function test_codigo_substitui_o_texto_em_vez_de_somar(): void
    {
        config([
            'integrations.omie.project_codes' => self::PROJETO_VENDA,
            'integrations.omie.project_marker' => 'projeto welog',
        ]);

        $marker = app(OmieProjectMarker::class);

        $this->assertSame('projeto', $marker->criterio());
        $this->assertFalse($marker->matches($this->pedido('Projeto WeLog', self::PROJETO_SUVINIL)));
        $this->assertTrue($marker->matches($this->pedido('Venda balcão', self::PROJETO_VENDA)));
    }

    public function test_sem_codigo_configurado_o_texto_volta_a_valer(): void
    {
        config([
            'integrations.omie.project_codes' => '',
            'integrations.omie.project_marker' => 'projeto welog',
        ]);

        $marker = app(OmieProjectMarker::class);

        $this->assertSame('observacao', $marker->criterio());
        $this->assertTrue($marker->matches($this->pedido('Projeto WeLog', self::PROJETO_SUVINIL)));
    }

    public function test_comando_lista_os_projetos_da_conta(): void
    {
        config(['integrations.omie.project_codes' => self::PROJETO_VENDA]);

        $this->artisan('welog:omie:projetos')
            ->expectsOutputToContain('Venda')
            ->expectsOutputToContain('Suvinil')
            ->expectsOutputToContain(self::PROJETO_VENDA)
            ->assertSuccessful();
    }
}
