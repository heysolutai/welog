<?php

use App\Http\Controllers\Webhooks\OmieWebhookController;
use App\Http\Controllers\Webhooks\ReconheceWebhookController;
use App\Http\Controllers\Webhooks\SuvinilWebhookController;
use App\Http\Controllers\Webhooks\TrelloWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks — rotas stateless, sem CSRF (registradas com prefixo /webhooks
| no bootstrap/app.php)
|--------------------------------------------------------------------------
| O {token} na URL é a credencial de verificação: a URL completa registrada
| no provedor (Omie, Suvinil) embute o token configurado em config/integrations.
*/

Route::middleware('throttle:webhooks')->group(function () {
    Route::post('/omie/{token}', OmieWebhookController::class)->name('webhooks.omie');

    // Trello valida a URL com HEAD/GET ao registrar o webhook; POSTs são assinados
    Route::match(['get', 'head'], '/trello', [TrelloWebhookController::class, 'verify']);
    Route::post('/trello', TrelloWebhookController::class)->name('webhooks.trello');

    // Suvinil empurra os resgates dela (Importações Parceiros). GET/HEAD é a
    // verificação da URL: todo parceiro testa o endereço antes de ligar o
    // disparo, e sem isso o teste volta 405 e parece que a URL está errada.
    Route::match(['get', 'head'], '/suvinil/{token}', [SuvinilWebhookController::class, 'verify']);
    Route::post('/suvinil/{token}', SuvinilWebhookController::class)->name('webhooks.suvinil');

    // Reconhece: cadastro de produto da vitrine onde o colaborador escolhe o
    // brinde. GET/HEAD é a verificação da URL, como nos demais. Sem {token},
    // a chave vem no header (X-API-Key / Authorization), como eles pediram.
    Route::match(['get', 'head'], '/reconhece', [ReconheceWebhookController::class, 'verify']);
    Route::post('/reconhece', ReconheceWebhookController::class)->name('webhooks.reconhece.header');
    Route::match(['get', 'head'], '/reconhece/{token}', [ReconheceWebhookController::class, 'verify']);
    Route::post('/reconhece/{token}', ReconheceWebhookController::class)->name('webhooks.reconhece');
});
