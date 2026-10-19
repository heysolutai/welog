<?php

namespace Tests\Feature;

use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Trello\TrelloFakeGateway;
use App\Models\Company;
use App\Models\Project;
use App\Services\FluxoA\CreateTrelloCardForProject;
use App\Services\FluxoA\OmieProjectMarker;
use App\Support\Projects\ProjectTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Templates de card por tipo de campanha.
 *
 * A operação já lia o quadro pelo prefixo e pela cor do card ([Evento],
 * [COM Estoque], [SEM Estoque]…). O tipo vem do PROJETO em que o pedido foi
 * classificado na Omie, e o card passa a nascer pronto nesse formato em vez de
 * alguém renomear card a card.
 */
class ProjectTemplatesTest extends TestCase
{
    use RefreshDatabase;

    /** Códigos reais dos projetos "Welog - *" na conta da operação. */
    private const CODIGOS = [
        '7828126392' => 'evento',
        '7828126432' => 'com_estoque',
        '7828127180' => 'amostra',
        '7828129354' => 'sem_estoque',
        '7828128552' => 'bosch_sem_estoque',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();

        $mapa = collect(self::CODIGOS)->map(fn ($t, $c) => "{$c}={$t}")->implode(',');
        config([
            'integrations.omie.project_templates' => $mapa,
            'integrations.trello.board_id' => 'board1',
        ]);
    }

    private function trello(): TrelloFakeGateway
    {
        $gateway = app(TrelloGateway::class);
        $this->assertInstanceOf(TrelloFakeGateway::class, $gateway);

        return $gateway;
    }

    private function projeto(string $template, array $extra = []): Project
    {
        $company = Company::firstOrCreate(
            ['cnpj' => '12345678000190'],
            ['name' => 'ACME Tecnologia LTDA'],
        );

        return Project::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Projeto de teste',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_DESIGN,
            'template' => $template,
            'omie_order_number' => '66906',
            'client_order_number' => 'Onboarding 2026',
            'ordered_at' => '2026-09-10',
        ], $extra));
    }

    /** Card recém-criado, com o título que foi para o quadro. */
    private function cardDe(Project $project): array
    {
        app(CreateTrelloCardForProject::class)->handle($project);

        return $this->trello()->getCard($project->fresh()->trello_card_id);
    }

    public function test_cada_tipo_gera_o_titulo_do_quadro(): void
    {
        $this->assertSame(
            '[Evento] ACME Tecnologia LTDA - Onboarding 2026',
            $this->cardDe($this->projeto('evento'))['name'],
        );

        $this->assertSame(
            '[COM Estoque] ACME Tecnologia LTDA - Onboarding 2026',
            $this->cardDe($this->projeto('com_estoque'))['name'],
        );

        $this->assertSame(
            '[Amostra] ACME Tecnologia LTDA - Onboarding 2026',
            $this->cardDe($this->projeto('amostra'))['name'],
        );

        // Sem estoque leva o número do pedido Omie, com zeros à esquerda
        $this->assertSame(
            '[SEM Estoque][#66906] ACME Tecnologia LTDA - Onboarding 2026',
            $this->cardDe($this->projeto('sem_estoque'))['name'],
        );

        // Bosch tem formato próprio: o mês no lugar do cliente
        $this->assertSame(
            '[SEM Estoque] Pedidos Bosch - Setembro',
            $this->cardDe($this->projeto('bosch_sem_estoque'))['name'],
        );
    }

    /** Pedido curto mantém os quatro dígitos do quadro ([#0001]). */
    public function test_numero_curto_ganha_zeros_a_esquerda(): void
    {
        $project = $this->projeto('sem_estoque', ['omie_order_number' => '1']);

        $this->assertSame(
            '[SEM Estoque][#0001] ACME Tecnologia LTDA - Onboarding 2026',
            $this->cardDe($project)['name'],
        );
    }

    /** Sem o campo da Omie preenchido, o número do pedido segura o lugar. */
    public function test_sem_numero_do_pedido_do_cliente_usa_o_pedido_omie(): void
    {
        $project = $this->projeto('evento', ['client_order_number' => null]);

        $this->assertSame(
            '[Evento] ACME Tecnologia LTDA - 66906',
            $this->cardDe($project)['name'],
        );
    }

    /** O nome de exibição da empresa (Company Name) vence a razão social. */
    public function test_usa_o_nome_de_exibicao_da_empresa(): void
    {
        $project = $this->projeto('evento');
        $project->company->update(['display_name' => 'ACME']);

        $this->assertSame(
            '[Evento] ACME - Onboarding 2026',
            $this->cardDe($project->fresh())['name'],
        );
    }

    public function test_card_nasce_com_a_etiqueta_colorida_do_tipo(): void
    {
        $evento = $this->projeto('evento');
        $card = $this->cardDe($evento);

        $labels = $this->trello()->labels();
        $etiqueta = $labels[$card['idLabels'][0]];

        $this->assertSame('Evento', $etiqueta['name']);
        $this->assertSame('orange', $etiqueta['color']);
    }

    /**
     * "SEM Estoque" existe em duas cores no quadro (azul e, no Bosch, verde).
     * Reaproveitar pelo nome daria a mesma cor para os dois.
     */
    public function test_sem_estoque_e_bosch_usam_etiquetas_de_cores_diferentes(): void
    {
        $comum = $this->cardDe($this->projeto('sem_estoque'));
        $bosch = $this->cardDe($this->projeto('bosch_sem_estoque'));

        $labels = $this->trello()->labels();

        $this->assertNotSame($comum['idLabels'][0], $bosch['idLabels'][0]);
        $this->assertSame('blue', $labels[$comum['idLabels'][0]]['color']);
        $this->assertSame('green', $labels[$bosch['idLabels'][0]]['color']);
    }

    /** Projeto mapeado é aceito sem precisar repetir o código em PROJECT_CODES. */
    public function test_codigo_com_template_e_aceito_automaticamente(): void
    {
        config(['integrations.omie.project_codes' => '']);

        $marker = app(OmieProjectMarker::class);

        foreach (array_keys(self::CODIGOS) as $codigo) {
            $pedido = ['informacoes_adicionais' => ['codProj' => (int) $codigo]];

            $this->assertTrue($marker->matches($pedido), "Projeto {$codigo} deveria ser aceito");
            $this->assertInstanceOf(ProjectTemplate::class, $marker->templateFor($pedido));
        }

        // Suvinil continua fora: ele é do módulo Parceiros Externos
        $this->assertFalse($marker->matches(['informacoes_adicionais' => ['codProj' => 7386090746]]));
    }

    /** Sem template mapeado, o card mantém o formato antigo. */
    public function test_projeto_sem_template_mantem_o_titulo_antigo(): void
    {
        $project = $this->projeto('');

        $this->assertSame(
            '66906 - Onboarding 2026',
            $this->cardDe($project)['name'],
        );
    }
}
