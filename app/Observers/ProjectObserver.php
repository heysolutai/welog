<?php

namespace App\Observers;

use App\Jobs\Integrations\SyncTrelloStockLabel;
use App\Mail\ProjectStageChangedMail;
use App\Models\Project;
use Illuminate\Support\Facades\Mail;

/**
 * Aviso de mudança de etapa (feedback do UAT): quando o pipeline_status muda,
 * os Admins de Projeto (project_admin) daquele Projeto recebem o e-mail.
 *
 * Vive num observer, e não em cada serviço, porque a etapa muda por três
 * caminhos: webhook do Trello (ProcessTrelloWebhook), ajuste manual
 * (ProjectController@updateStage) e sinal de estoque da Omie
 * (HandleOmieStockSignal). Todos usam `$project->update()`, então o evento
 * `updated` do model cobre os três num ponto só.
 *
 * Quem recebe: SÓ o Admin Projeto. O Admin Cliente pediu para
 * receber apenas o convite de login, e o Usuário do portal já tem os avisos
 * de envio/entrega (shipment_update).
 */
class ProjectObserver
{
    public function updated(Project $project): void
    {
        // `wasChanged` garante que o e-mail só sai quando a etapa REALMENTE
        // mudou: o sinal de estoque, por exemplo, regrava o mesmo valor quando
        // o Projeto já passou de "Em Estoque" e isso não é mudança.
        if (! $project->wasChanged('pipeline_status')) {
            return;
        }

        // Etiqueta "Em estoque" no card do Trello acompanha a etapa (a
        // convenção amarela que a operação usava à mão). O job decide o estado
        // na execução e ignora Projeto sem card; falha vira retentativa na
        // fila, nunca trava a mudança de etapa.
        SyncTrelloStockLabel::dispatch($project->id);

        $admins = $project->projectAdmins()->where('active', true)->get();

        if ($admins->isEmpty()) {
            return;
        }

        // O rótulo vai congelado no Mailable: se a etapa mudar de novo antes de
        // o worker rodar, cada e-mail ainda conta a mudança que o disparou.
        $etapa = $project->stageLabel();

        foreach ($admins as $admin) {
            Mail::to($admin->email)->queue(new ProjectStageChangedMail($project, $etapa));
        }
    }
}
