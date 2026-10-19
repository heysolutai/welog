<?php

namespace Tests\Feature\Webhooks;

use App\Models\Company;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrelloWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
    }

    private function project(): Project
    {
        return Project::create([
            'company_id' => Company::create(['name' => 'ACME', 'cnpj' => '12345678000190'])->id,
            'name' => 'Projeto Teste',
            'trello_card_id' => 'card-abc',
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);
    }

    private function movePayload(string $cardId, string $listId): array
    {
        return [
            'action' => [
                'id' => 'action-'.uniqid(),
                'type' => 'updateCard',
                'data' => [
                    'card' => ['id' => $cardId],
                    'listAfter' => ['id' => $listId, 'name' => 'Produção'],
                    'listBefore' => ['id' => 'list-design'],
                ],
            ],
        ];
    }

    public function test_registro_do_webhook_responde_head_e_get(): void
    {
        $this->get('/webhooks/trello')->assertOk();
        $this->head('/webhooks/trello')->assertOk();
    }

    public function test_mover_card_atualiza_pipeline_do_projeto(): void
    {
        $project = $this->project();

        $this->postJson('/webhooks/trello', $this->movePayload('card-abc', 'list-production'))
            ->assertOk();

        $this->assertSame(Project::STATUS_PRODUCTION, $project->fresh()->pipeline_status);
    }

    public function test_lista_nao_mapeada_e_ignorada_sem_erro(): void
    {
        $project = $this->project();

        $this->postJson('/webhooks/trello', $this->movePayload('card-abc', 'list-desconhecida'))
            ->assertOk();

        $this->assertSame(Project::STATUS_DESIGN, $project->fresh()->pipeline_status);
    }

    public function test_assinatura_invalida_e_rejeitada_quando_secret_configurado(): void
    {
        config(['integrations.trello.secret' => 'super-secret']);

        $this->postJson('/webhooks/trello', $this->movePayload('card-abc', 'list-production'))
            ->assertForbidden();
    }

    public function test_reentrega_do_mesmo_action_id_nao_reprocessa(): void
    {
        $project = $this->project();
        $payload = $this->movePayload('card-abc', 'list-production');

        $this->postJson('/webhooks/trello', $payload)->assertOk();

        // Move manualmente para "Montagem" e reenvia o MESMO action id
        $project->fresh()->update(['pipeline_status' => Project::STATUS_ASSEMBLY]);
        $this->postJson('/webhooks/trello', $payload)->assertOk();

        $this->assertSame(Project::STATUS_ASSEMBLY, $project->fresh()->pipeline_status);
    }
}
