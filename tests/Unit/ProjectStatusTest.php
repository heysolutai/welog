<?php

namespace Tests\Unit;

use App\Models\Project;
use Tests\TestCase;

/**
 * Regra de liberação do Fluxo A (Módulo 2): o Projeto só pode ser liberado
 * quando a Omie sinalizou estoque e ele ainda não foi liberado. Lógica pura
 * sobre os timestamps, sem banco.
 */
class ProjectStatusTest extends TestCase
{
    public function test_estoque_disponivel_reflete_o_timestamp(): void
    {
        $project = new Project;
        $this->assertFalse($project->hasStockAvailable());

        $project->stock_available_at = now();
        $this->assertTrue($project->hasStockAvailable());
    }

    public function test_liberado_reflete_o_timestamp(): void
    {
        $project = new Project;
        $this->assertFalse($project->isReleased());

        $project->released_at = now();
        $this->assertTrue($project->isReleased());
    }

    public function test_so_pode_liberar_em_estoque_e_ainda_nao_liberado(): void
    {
        // Fora de "Em Estoque" → não pode liberar, mesmo com saldo na Omie:
        // saldo de componente não é kit pronto na prateleira.
        $emMontagem = new Project;
        $emMontagem->pipeline_status = Project::STATUS_ASSEMBLY;
        $emMontagem->stock_available_at = now();
        $this->assertFalse($emMontagem->canBeReleased());

        // Em estoque e ainda não liberado → pode
        $emEstoque = new Project;
        $emEstoque->pipeline_status = Project::STATUS_IN_STOCK;
        $this->assertTrue($emEstoque->canBeReleased());

        // Já liberado → não pode de novo (protege contra dupla liberação)
        $jaLiberado = new Project;
        $jaLiberado->pipeline_status = Project::STATUS_IN_STOCK;
        $jaLiberado->released_at = now();
        $this->assertFalse($jaLiberado->canBeReleased());
    }
}
