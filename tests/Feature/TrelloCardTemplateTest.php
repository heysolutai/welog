<?php

namespace Tests\Feature;

use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Trello\TrelloFakeGateway;
use App\Models\Company;
use App\Models\Project;
use App\Services\FluxoA\CreateTrelloCardForProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Card novo nasce como CÓPIA do card modelo do quadro.
 *
 * Pintar uma etiqueta da cor certa a plataforma já fazia; o que ela não tinha
 * como reproduzir era o resto do modelo — os checklists da produção, os campos
 * personalizados, os anexos. Copiando o card, tudo isso vem junto, e a cor
 * passa a ser a etiqueta REAL do quadro em vez de uma imitação.
 */
class TrelloCardTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();

        config([
            'integrations.trello.board_id' => 'board1',
            'integrations.omie.project_templates' => '7828126432=com_estoque',
            // Códigos curtos reais dos modelos do quadro da operação.
            'integrations.trello.card_templates' => 'evento=33wyYj44,com_estoque=Zmz776Yy,'
                .'amostra=mMVIaqtz,sem_estoque=efsWcnTL,bosch_sem_estoque=rP0SQ5cP',
        ]);
    }

    private function trello(): TrelloFakeGateway
    {
        $gateway = app(TrelloGateway::class);
        $this->assertInstanceOf(TrelloFakeGateway::class, $gateway);

        return $gateway;
    }

    /** Põe o card modelo no quadro simulado, com cor e checklist. */
    private function modeloNoQuadro(string $id): void
    {
        $this->trello()->seedCard($id, [
            'id' => $id,
            'idList' => 'list-templates',
            'name' => '[COM Estoque] Nome da empresa - Nome do projeto',
            // A etiqueta do modelo marca o MODELO no quadro, não o tipo da
            // campanha — ver a asserção em test_card_nasce_com_o_checklist…
            'idLabels' => ['label-do-modelo'],
            'desc' => "Descrição padrão\n\nContato do cliente: Nome / Cargo\nEndereço para entrega: CEP",
            'checklists' => [['name' => 'Produção', 'items' => ['Arte aprovada', 'Kit montado']]],
            'customFields' => [['name' => 'Responsável', 'value' => null]],
        ]);
    }

    private function projeto(string $template): Project
    {
        $company = Company::firstOrCreate(['cnpj' => '12345678000190'], ['name' => 'ACME']);

        return Project::create([
            'company_id' => $company->id,
            'name' => 'Projeto de teste',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_DESIGN,
            'template' => $template,
            'omie_order_number' => '69848',
            'client_order_number' => 'Onboarding Jan25',
        ]);
    }

    public function test_card_nasce_com_o_checklist_e_a_descricao_padrao_do_modelo(): void
    {
        $this->modeloNoQuadro('Zmz776Yy');

        $project = $this->projeto('com_estoque');
        app(CreateTrelloCardForProject::class)->handle($project);

        $card = $this->trello()->getCard($project->fresh()->trello_card_id);

        $this->assertSame('Produção', $card['checklists'][0]['name']);
        $this->assertSame('Responsável', $card['customFields'][0]['name']);
        $this->assertSame('Zmz776Yy', $card['copiadoDe']);

        // Título e lista são os da plataforma, não os do modelo
        $this->assertSame('[COM Estoque] ACME - Onboarding Jan25', $card['name']);
        $this->assertSame('list-design', $card['idList']);

        // Descrição: dados do pedido em cima, formulário do modelo embaixo.
        // Mandar só a nossa apagaria o formulário que a operação preenche.
        $this->assertStringContainsString('Pedido Omie: 69848', $card['desc']);
        $this->assertStringContainsString('Endereço para entrega', $card['desc']);

        // A etiqueta do tipo é pintada pela plataforma, não herdada do modelo:
        // as do modelo marcam o MODELO e fariam todo card parecer um template.
        $this->assertNotContains('label-do-modelo', $card['idLabels']);
        $this->assertCount(1, $card['idLabels']);
    }

    /** Cada tipo copia o SEU modelo. */
    public function test_cada_tipo_copia_o_proprio_modelo(): void
    {
        $esperado = [
            'evento' => '33wyYj44',
            'com_estoque' => 'Zmz776Yy',
            'amostra' => 'mMVIaqtz',
            'sem_estoque' => 'efsWcnTL',
            'bosch_sem_estoque' => 'rP0SQ5cP',
        ];

        foreach ($esperado as $template => $modelo) {
            $this->modeloNoQuadro($modelo);
            $project = $this->projeto($template);

            app(CreateTrelloCardForProject::class)->handle($project);

            $card = $this->trello()->getCard($project->fresh()->trello_card_id);
            $this->assertSame($modelo, $card['copiadoDe'], "Template {$template} copiou o modelo errado");
        }
    }

    /**
     * O `idCardSource` exige o id LONGO do card (24 hexadecimais). O código
     * curto da URL é recusado — e é o curto que se copia do navegador para
     * configurar, então a conversão precisa acontecer sozinha.
     *
     * Sem ela o Trello recusava a cópia, o card nascia do zero e o único
     * sinal era uma linha no log: no quadro, um card sem checklist nenhum.
     */
    public function test_modelo_configurado_pelo_codigo_curto_e_copiado_pelo_id_longo(): void
    {
        $this->trello()->seedCard('Zmz776Yy', [
            'id' => '6aae0e5e1d84016681bd59c0',
            'idList' => 'list-templates',
            'desc' => 'Descrição padrão',
            'checklists' => [['name' => 'Produção', 'items' => ['Arte aprovada']]],
        ]);

        $project = $this->projeto('com_estoque');
        app(CreateTrelloCardForProject::class)->handle($project);

        $card = $this->trello()->getCard($project->fresh()->trello_card_id);

        $this->assertSame('6aae0e5e1d84016681bd59c0', $card['copiadoDe'], 'A cópia tem de usar o id longo');
        $this->assertSame('Produção', $card['checklists'][0]['name']);
    }

    /**
     * Sem modelo configurado nada quebra: o card é criado do zero e a
     * plataforma pinta a etiqueta do tipo, como antes.
     */
    public function test_sem_modelo_configurado_o_card_e_criado_do_zero(): void
    {
        config(['integrations.trello.card_templates' => '']);

        $project = $this->projeto('com_estoque');
        app(CreateTrelloCardForProject::class)->handle($project);

        $card = $this->trello()->getCard($project->fresh()->trello_card_id);

        $this->assertNull($card['copiadoDe']);
        $this->assertCount(1, $card['idLabels'], 'A etiqueta do tipo continua sendo pintada');
        $this->assertSame('[COM Estoque] ACME - Onboarding Jan25', $card['name']);
    }

    /**
     * Modelo recusado não custa o card.
     *
     * O link do modelo é configuração: o card pode ser arquivado, o código
     * pode mudar, o token pode não enxergar o quadro onde ele está. Se a
     * recusa derrubasse a criação, uma edição no quadro deixaria a fábrica
     * sem card nenhum — e o Projeto invisível para ela.
     */
    public function test_modelo_recusado_nao_impede_a_criacao_do_card(): void
    {
        $recusa = new class extends TrelloFakeGateway
        {
            public function createCard(string $listId, string $name, string $description = '', ?string $sourceCardId = null): array
            {
                if ($sourceCardId !== null) {
                    throw new \RuntimeException('invalid value for idCardSource');
                }

                return parent::createCard($listId, $name, $description);
            }
        };

        $this->app->instance(TrelloGateway::class, $recusa);

        $project = $this->projeto('com_estoque');
        app(CreateTrelloCardForProject::class)->handle($project);

        $cardId = $project->fresh()->trello_card_id;
        $this->assertNotNull($cardId, 'O card deveria ter nascido mesmo sem o modelo');

        $card = $recusa->getCard($cardId);
        $this->assertNull($card['copiadoDe']);
        $this->assertCount(1, $card['idLabels'], 'Sem o modelo, a etiqueta do tipo volta a ser pintada');
    }
}
