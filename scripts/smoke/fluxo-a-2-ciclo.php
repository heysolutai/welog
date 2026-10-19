<?php

/**
 * Smoke test Fluxo A — passo 2 (após os webhooks de pedido e estoque):
 * cadastra cliente final, libera o Projeto, resgata e expede.
 * Uso: php artisan tinker scripts/smoke/fluxo-a-2-ciclo.php
 */

use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\FluxoA\RedeemKit;
use App\Services\FluxoA\RegisterEndCustomer;
use App\Services\FluxoA\ReleaseProject;
use App\Services\FluxoA\ShipRedemption;

$project = Project::latest('id')->firstOrFail();
echo "Projeto #{$project->id} — {$project->name}\n";
echo 'Estoque disponível: '.($project->hasStockAvailable() ? 'SIM' : 'NÃO')."\n";

$admin = User::where('email', 'admin@welog.local')->firstOrFail();

$entitlement = app(RegisterEndCustomer::class)->handle($project, [
    'name' => 'Colaborador Demo',
    'email' => 'colaborador@demo.local',
    'product_id' => $project->products->first()->id,
], $admin);
echo "Cliente final cadastrado (entitlement #{$entitlement->id})\n";

app(ReleaseProject::class)->handle($project->fresh(), $admin);
echo "Projeto liberado — e-mail de notificação na fila\n";

$redemption = app(RedeemKit::class)->handle($entitlement->fresh(), [
    'recipient_name' => 'Colaborador Demo',
    'zip' => '80010000',
    'street' => 'Rua XV de Novembro',
    'number' => '100',
    'district' => 'Centro',
    'city' => 'Curitiba',
    'state' => 'PR',
], $entitlement->user);
echo "Resgate #{$redemption->id} confirmado — e-mail de confirmação na fila\n";

config(['integrations.correios.prepostagem_enabled' => true]);
app(ShipRedemption::class)->handle($redemption, 'correios', $admin);
echo "Expedido via Correios — rastreio: {$redemption->fresh()->tracking_code}\n";
