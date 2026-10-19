<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ajuste manual do estágio do Projeto (quando o Trello não está disponível). */
class ProjectStageUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $this->project = Project::create([
            'company_id' => $company->id, 'name' => 'Natal', 'quantity' => 10,
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    public function test_admin_move_o_projeto_para_em_estoque_e_ele_fica_liberavel(): void
    {
        $this->assertFalse($this->project->canBeReleased());

        $this->actingAs($this->admin())
            ->patch(route('projects.stage', $this->project), ['pipeline_status' => Project::STATUS_IN_STOCK])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Estoque Disponível'));

        $this->project->refresh();
        $this->assertSame(Project::STATUS_IN_STOCK, $this->project->pipeline_status);
        $this->assertTrue($this->project->canBeReleased());
    }

    public function test_n1_pode_e_n2_nao_pode_alterar_estagio(): void
    {
        $n1 = User::factory()->create(['role' => User::ROLE_WEGIFT_N1]);
        $this->actingAs($n1)
            ->patch(route('projects.stage', $this->project), ['pipeline_status' => Project::STATUS_PRODUCTION])
            ->assertRedirect();
        $this->assertSame(Project::STATUS_PRODUCTION, $this->project->fresh()->pipeline_status);

        $n2 = User::factory()->create(['role' => User::ROLE_WEGIFT_N2]);
        $this->actingAs($n2)
            ->patch(route('projects.stage', $this->project), ['pipeline_status' => Project::STATUS_DONE])
            ->assertForbidden();
        $this->assertSame(Project::STATUS_PRODUCTION, $this->project->fresh()->pipeline_status);
    }

    public function test_estagio_invalido_ou_igual_e_recusado(): void
    {
        $this->actingAs($this->admin())
            ->patch(route('projects.stage', $this->project), ['pipeline_status' => 'inexistente'])
            ->assertSessionHasErrors('pipeline_status');

        $this->actingAs($this->admin())
            ->patch(route('projects.stage', $this->project), ['pipeline_status' => Project::STATUS_DESIGN])
            ->assertSessionHasErrors('pipeline_status');
    }
}
