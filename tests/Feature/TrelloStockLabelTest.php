<?php

namespace Tests\Feature;

use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Trello\TrelloFakeGateway;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Etiqueta amarela "Em estoque" no card do Trello — a convenção que a operação
 * usava à mão no quadro, agora mantida pela plataforma: etapa com kit em
 * estoque (Estoque/Envio) = etiqueta no card; antes disso ou Concluído = sem
 * etiqueta.
 */
class TrelloStockLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
        config(['integrations.trello.board_id' => 'board1']);
    }

    private function trello(): TrelloFakeGateway
    {
        $gateway = app(TrelloGateway::class);
        $this->assertInstanceOf(TrelloFakeGateway::class, $gateway);

        return $gateway;
    }

    private function projetoComCard(string $status): Project
    {
        $company = Company::firstOrCreate(['cnpj' => '12345678000190'], ['name' => 'ACME']);
        $product = Product::firstOrCreate(['omie_code' => 'KIT-LBL-01'], ['name' => 'Kit Etiqueta']);

        $card = $this->trello()->createCard('list-design', '66906 - ACME', 'Projeto de teste');

        $project = Project::create([
            'company_id' => $company->id,
            'name' => '66906 - ACME',
            'quantity' => 1,
            'pipeline_status' => $status,
            'trello_card_id' => $card['id'],
            'trello_card_url' => $card['url'],
        ]);
        $project->products()->attach($product->id, ['quantity' => 1]);

        return $project;
    }

    private function labelsDoCard(Project $project): array
    {
        return $this->trello()->getCard($project->trello_card_id)['idLabels'] ?? [];
    }

    public function test_chegar_ao_estoque_poe_a_etiqueta_e_regressao_tira(): void
    {
        $project = $this->projetoComCard(Project::STATUS_ASSEMBLY);
        $this->assertSame([], $this->labelsDoCard($project));

        // Kit chegou ao estoque (mesmo caminho do sinal da Omie/ajuste manual)
        $project->update(['pipeline_status' => Project::STATUS_IN_STOCK]);
        $this->assertCount(1, $this->labelsDoCard($project));

        // Envio: os kits saindo continuam existindo — etiqueta fica
        $project->update(['pipeline_status' => Project::STATUS_SHIPPING]);
        $this->assertCount(1, $this->labelsDoCard($project));

        // Item danificado: card volta para Montagem — etiqueta sai
        $project->update(['pipeline_status' => Project::STATUS_ASSEMBLY]);
        $this->assertSame([], $this->labelsDoCard($project));
    }

    public function test_concluido_tira_a_etiqueta(): void
    {
        $project = $this->projetoComCard(Project::STATUS_IN_STOCK);
        $project->update(['pipeline_status' => Project::STATUS_SHIPPING]);
        $this->assertCount(1, $this->labelsDoCard($project));

        $project->update(['pipeline_status' => Project::STATUS_DONE]);
        $this->assertSame([], $this->labelsDoCard($project));
    }

    public function test_a_etiqueta_e_reaproveitada_pelo_nome_no_quadro(): void
    {
        $a = $this->projetoComCard(Project::STATUS_ASSEMBLY);
        $b = $this->projetoComCard(Project::STATUS_ASSEMBLY);

        $a->update(['pipeline_status' => Project::STATUS_IN_STOCK]);
        $b->update(['pipeline_status' => Project::STATUS_IN_STOCK]);

        // Uma etiqueta só no quadro, compartilhada pelos dois cards
        $this->assertSame($this->labelsDoCard($a), $this->labelsDoCard($b));
    }

    public function test_projeto_sem_card_nao_estoura(): void
    {
        $company = Company::create(['name' => 'Beta', 'cnpj' => '99888777000166']);
        $project = Project::create([
            'company_id' => $company->id,
            'name' => 'Sem card',
            'quantity' => 1,
            'pipeline_status' => Project::STATUS_ASSEMBLY,
        ]);

        $project->update(['pipeline_status' => Project::STATUS_IN_STOCK]);

        $this->assertSame(Project::STATUS_IN_STOCK, $project->fresh()->pipeline_status);
    }
}
