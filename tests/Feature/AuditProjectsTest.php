<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A varredura é o que permite AFIRMAR que arrumou.
 *
 * Ela roda contra dez consultas diferentes; basta uma coluna renomeada para
 * um dos números virar zero em silêncio — e um relatório que diz "nada
 * pendente" por engano é pior que não ter relatório.
 */
class AuditProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_varredura_roda_e_acha_o_que_esta_torto(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '99888777000166']);

        // Projeto sem kit, sem card e sem prazo — os três de uma vez
        Project::create(['company_id' => $company->id, 'name' => 'Torto', 'quantity' => 0]);

        // E um Projeto cujo produto nunca teve a estrutura lida da Omie: é o
        // achado que motivou a varredura, e o único que tem conserto por comando.
        $semEstrutura = Project::create(['company_id' => $company->id, 'name' => 'Sem BOM', 'quantity' => 1]);
        $produto = Product::create(['omie_code' => 'KIT-SEM-BOM', 'name' => 'Kit sem estrutura']);
        $semEstrutura->products()->attach($produto->id, ['quantity' => 1]);

        $this->artisan('welog:projects:audit')
            ->expectsOutputToContain('Projeto sem kit vinculado')
            ->expectsOutputToContain('welog:omie:sync-kit-structure')
            ->assertSuccessful();
    }

    /** Cada achado precisa saber listar os próprios registros. */
    public function test_a_opcao_ids_lista_os_registros(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '99888777000166']);
        $project = Project::create(['company_id' => $company->id, 'name' => 'Torto', 'quantity' => 0]);

        $this->artisan('welog:projects:audit --ids')
            ->expectsOutputToContain((string) $project->id)
            ->assertSuccessful();
    }

    /** Banco saudável não pode acusar problema — senão o relatório vira ruído. */
    public function test_projeto_completo_nao_vira_achado(): void
    {
        $company = Company::create([
            'name' => 'ACME', 'cnpj' => '99888777000166', 'contact_email' => 'a@acme.com',
        ]);

        $kit = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit']);
        $componente = Product::create(['omie_code' => 'ITEM-1', 'name' => 'Item']);
        $kit->components()->attach($componente->id, ['quantity' => 1]);

        $project = Project::create([
            'company_id' => $company->id,
            'name' => 'Certo',
            'quantity' => 10,
            'delivery_deadline' => now()->addDays(10),
            'trello_card_id' => 'card-1',
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $project->products()->attach($kit->id, ['quantity' => 10]);

        $this->artisan('welog:projects:audit')->assertSuccessful();

        $this->assertSame(0, Project::doesntHave('products')->count());
        $this->assertSame(0, Product::whereHas('projects')->doesntHave('components')->count());
    }
}
