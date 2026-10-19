<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CarrierController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookEventController;
use App\Http\Controllers\AppnozImportController;
use App\Http\Controllers\Auth\FirstAccessController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CepLookupController;
use App\Http\Controllers\CompanyAdminController;
use App\Http\Controllers\CompanyEmailTemplateController;
use App\Http\Controllers\CompanyLookupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FiscalBenefitController;
use App\Http\Controllers\MessageStudioController;
use App\Http\Controllers\PartnerSkuController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPhotoController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectRequestController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

// Autenticação
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:auth');

    // Primeiro acesso (convite)
    Route::get('/conta/definir-senha/{token}', [PasswordController::class, 'setForm'])->name('password.set.form');
    Route::post('/conta/definir-senha', [PasswordController::class, 'set'])->middleware('throttle:auth')->name('password.set');

    /*
     * Primeiro acesso pela intranet do Cliente.
     *
     * O colaborador chega por um link que a própria empresa dele publicou, e
     * o token na URL diz de quem é a tela — sem ele, um endereço só seria
     * porta aberta para a base inteira.
     */
    Route::get('/primeiro-acesso/{token}', [FirstAccessController::class, 'form'])->name('primeiro-acesso.form');
    Route::post('/primeiro-acesso/{token}', [FirstAccessController::class, 'send'])
        ->middleware('throttle:primeiro-acesso')
        ->name('primeiro-acesso.send');

    // Esqueci minha senha
    Route::get('/senha/esqueci', [PasswordController::class, 'requestForm'])->name('password.request');
    Route::post('/senha/esqueci', [PasswordController::class, 'sendResetLink'])->middleware('throttle:auth')->name('password.email');

    // Redefinir (link de reset) — nome 'password.reset' esperado pela notificação padrão
    Route::get('/senha/redefinir/{token}', [PasswordController::class, 'resetForm'])->name('password.reset');
    Route::post('/senha/redefinir', [PasswordController::class, 'reset'])->middleware('throttle:auth')->name('password.update');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// Preenchimento de endereço por CEP — usado no cadastro de empresa e, no
// portal, no endereço de entrega do resgate. Throttle porque é uma chamada a
// serviço externo disparada por digitação.
Route::get('/cep/{cep}', CepLookupController::class)
    ->whereNumber('cep')
    ->middleware(['auth', 'throttle:60,1'])
    ->name('cep.lookup');

/*
|--------------------------------------------------------------------------
| Área operacional WeGift
|--------------------------------------------------------------------------
| N2 tem acesso de consulta; ações críticas exigem admin/N1 (role:critical).
*/
Route::middleware(['auth', 'role:operator'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/redemptions', [RedemptionController::class, 'index'])->name('redemptions.index');

    // Catálogo de produtos e kits (Módulo 5) — consulta; N2 também visualiza
    Route::get('/produtos', [ProductController::class, 'index'])->name('products.index');

    // Catálogo do parceiro (Reconhece) e a amarração dele ao produto da Omie.
    // SKU sem vínculo trava o resgate; a tela existe para ver isso ANTES.
    Route::get('/produtos/catalogo-parceiro', [PartnerSkuController::class, 'index'])->name('partner-skus.index');
    // Registrado antes de qualquer /produtos/{product}: sem isto o literal
    // "catalogo-parceiro" seria tratado como id de produto.
    Route::get('/produtos/{product}/editar', [ProductController::class, 'edit'])->name('products.edit');

    // Importações Parceiros: os resgates que chegam pelo webhook, um a um.
    Route::get('/imports', [AppnozImportController::class, 'index'])->name('imports.index');
    // A antiga visão Envios virou a própria lista; o endereço guardado em
    // favorito continua chegando lá.
    Route::redirect('/imports/envios', '/imports');
    Route::get('/imports/resgates/{row}', [AppnozImportController::class, 'show'])
        ->whereNumber('row')->name('imports.rows.show');
});

/*
|--------------------------------------------------------------------------
| Relatórios (inclusão UAT)
|--------------------------------------------------------------------------
| Resumo em tela + exportação CSV de resgates e Projetos. WeGift vê tudo
| (N2 incluso: relatório é consulta); Admin Cliente sai escopado à empresa
| e Admin de Projeto ao Projeto dele — refinamento no ReportController.
*/
Route::middleware(['auth', 'role:operator,admin_client'])->group(function () {
    Route::get('/relatorios', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/relatorios/resgates', [ReportController::class, 'redemptions'])->name('reports.redemptions');
    Route::get('/relatorios/projetos', [ReportController::class, 'projects'])->name('reports.projects');
    Route::get('/relatorios/colaboradores', [ReportController::class, 'people'])->name('reports.people');
});

// Projetos: operadores WeGift + Admin Cliente (este último escopado à empresa)
Route::middleware(['auth', 'role:operator,admin_client'])->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])
        ->whereNumber('project')->name('projects.show');
});

Route::middleware(['auth', 'role:critical'])->group(function () {
    // Onboarding manual de Projeto (fallback quando a Omie falha)
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    // Sugestões de empresa (nome/CNPJ) para o autocomplete do cadastro manual.
    Route::get('/companies/lookup', CompanyLookupController::class)
        ->middleware('throttle:120,1')
        ->name('companies.lookup');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');

    // Ajuste manual do estágio (quando o Trello não está disponível/integrado).
    Route::patch('/projects/{project}/stage', [ProjectController::class, 'updateStage'])->name('projects.stage');
    // Edição de nome e prazo de entrega do Projeto (inclusão UAT). A partir da
    // edição, a sincronização com a Omie deixa de sobrescrever esses campos.
    Route::patch('/projects/{project}/details', [ProjectController::class, 'updateDetails'])->name('projects.details');
    // Liberação do resgate: só WeGift (dupla trava contratual)
    Route::post('/projects/{project}/release', [ProjectController::class, 'release'])->name('projects.release');
    // Solicitação de kits (N unidades de um item do pedido, sem limite por
    // pessoa) e freio de mão do resgate: só a WeGift, que opera em nome do
    // Cliente. Desbloquear é só do Admin WeGift e mora no grupo `role:admin`.
    Route::post('/projects/{project}/requests', [ProjectRequestController::class, 'store'])->name('projects.requests.store');
    Route::post('/projects/{project}/block-redemptions', [ProjectController::class, 'blockRedemptions'])->name('projects.redemptions.block');

    // Logo da campanha, enviada em Customizações (tela do Cliente). Fica neste
    // grupo, `role:critical`, porque personalizar a marca do Projeto é decisão
    // da WeGift — o Admin Cliente não parametriza a própria tela.
    // Textos da tela de resgate da campanha (Itens do pedido > Tela de resgate).
    Route::put('/projects/{project}/tela-de-resgate', [ProjectController::class, 'updatePortalTexts'])
        ->name('projects.portal-texts.update');

    Route::post('/projects/{project}/logo', [ProjectController::class, 'storeLogo'])
        ->name('projects.logo.store');
    Route::delete('/projects/{project}/logo', [ProjectController::class, 'destroyLogo'])
        ->name('projects.logo.destroy');
    Route::post('/redemptions/{redemption}/ship', [RedemptionController::class, 'ship'])->name('redemptions.ship');
    Route::post('/redemptions/{redemption}/delivered', [RedemptionController::class, 'markDelivered'])->name('redemptions.delivered');
    // Entrega em lote: a transportadora avisa uma campanha inteira de uma vez.
    Route::post('/redemptions/entregues', [RedemptionController::class, 'deliverBatch'])->name('redemptions.delivered.batch');
    // Alteração de status em massa: expedir e cancelar vários de uma vez.
    Route::post('/redemptions/expedir', [RedemptionController::class, 'shipBatch'])->name('redemptions.ship.batch');
    Route::post('/redemptions/cancelar', [RedemptionController::class, 'cancelBatch'])->name('redemptions.cancel.batch');
    // Todos os resgates do filtro atual, para "selecionar todos" além da página.
    Route::get('/redemptions/selecao', [RedemptionController::class, 'selection'])->name('redemptions.selection');
    // CPF que ficou para depois numa solicitação aberta pela WeGift.
    Route::patch('/redemptions/{redemption}/cpf', [RedemptionController::class, 'updateCpf'])->name('redemptions.cpf');
    // Endereço de entrega corrigido pela WeGift, inclusive depois de expedir
    // (quando ela já combinou a troca com a transportadora).
    Route::patch('/redemptions/{redemption}/endereco', [RedemptionController::class, 'updateAddress'])->name('redemptions.address');
    // Cancela resgate não expedido e devolve o saldo baixado (plataforma e Omie)
    Route::post('/redemptions/{redemption}/cancel', [RedemptionController::class, 'cancel'])->name('redemptions.cancel');

    Route::post('/imports/rows/{row}/reprocess', [AppnozImportController::class, 'reprocessRow'])->name('imports.rows.reprocess');
    Route::post('/imports/rows/{row}/nf', [AppnozImportController::class, 'fetchInvoice'])->name('imports.rows.invoice');
    Route::post('/imports/rows/{row}/flash', [AppnozImportController::class, 'sendToFlash'])->name('imports.rows.flash');
    Route::post('/imports/rows/{row}/divergencia', [AppnozImportController::class, 'resolveDivergence'])->name('imports.rows.divergence');

    // Importação do catálogo Omie (Módulo 5) — dispara o sync em background
    Route::post('/produtos/importar', [ProductController::class, 'import'])->name('products.import');
    Route::post('/produtos/{product}/reconhece', [ProductController::class, 'publishToReconhece'])
        ->name('products.reconhece.publish');
    Route::put('/produtos/{product}/reconhece/categorias', [ProductController::class, 'saveReconheceCategories'])
        ->name('products.reconhece.categories');
    Route::put('/produtos/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/produtos/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    // Galeria do item principal (até 10 fotos), da aba Itens do pedido ou da
    // tela do Produto. Tamanho de grade não tem galeria: usa a do pai.
    Route::post('/produtos/fotos', [ProductPhotoController::class, 'store'])->name('products.photos.store');
    Route::put('/produtos/{product}/fotos/ordem', [ProductPhotoController::class, 'reorder'])->name('products.photos.reorder');
    Route::delete('/produtos/fotos/{photo}', [ProductPhotoController::class, 'destroy'])->name('products.photos.destroy');
    Route::patch('/produtos/catalogo-parceiro/{partnerSku}/vinculo', [PartnerSkuController::class, 'link'])
        ->name('partner-skus.link');
});

// Cadastro de clientes finais: WeGift crítico OU admin da empresa cliente
// (o grupo admin_client inclui o Admin de Projeto, escopado nos controllers)
Route::middleware(['auth', 'role:critical,admin_client'])->group(function () {
    // Cadastro do Admin de um Projeto: WeGift em qualquer um, Admin Cliente
    // nos da empresa dele, Admin de Projeto apenas no dele. Ninguém cadastra
    // o próprio nível — Admin Cliente nasce na ficha da Empresa.
    Route::post('/company/admins', [CompanyAdminController::class, 'store'])->name('company.admins.store');

    /*
     * Mensagens que o Colaborador recebe, na voz da empresa.
     *
     * Grupo `critical,admin_client`: Admin Empresas e Admin Projeto. O texto
     * vale para a empresa inteira, então quem edita mexe também no que os
     * outros Projetos dela enviam — o histórico de quem salvou por último fica
     * no registro, e restringir ao Admin Empresas é uma linha, se a operação
     * preferir.
     */
    Route::get('/company/mensagens', [CompanyEmailTemplateController::class, 'index'])->name('company.templates.index');
    Route::put('/company/mensagens/{key}', [CompanyEmailTemplateController::class, 'update'])->name('company.templates.update');
    Route::delete('/company/mensagens/{key}', [CompanyEmailTemplateController::class, 'restore'])->name('company.templates.restore');

    // Estúdio de mensagens do resgate (JSON): o mesmo para o Admin WeGift, pelas
    // Customizações, e para o Admin Cliente, pelo menu Mensagens. Quem pode o
    // quê é conferido no controller — o Admin Cliente só na própria empresa.
    Route::get('/mensagens-do-resgate/{company}', [MessageStudioController::class, 'index'])->name('studio.index');
    Route::put('/mensagens-do-resgate/{company}/{key}', [MessageStudioController::class, 'update'])->name('studio.update');
    Route::delete('/mensagens-do-resgate/{company}/{key}', [MessageStudioController::class, 'restore'])->name('studio.restore');
    Route::put('/mensagens-do-resgate/{company}/{key}/envio', [MessageStudioController::class, 'toggle'])->name('studio.toggle');
    Route::post('/mensagens-do-resgate/{company}/{key}/teste', [MessageStudioController::class, 'test'])->name('studio.test');

    Route::post('/projects/{project}/entitlements', [ProjectController::class, 'storeEntitlement'])->name('projects.entitlements.store');
    // Admin do Projeto que também resgata: ligar e desligar depois do
    // cadastro. Só Admin Cliente e WeGift (conferido no controller).
    Route::post('/projects/{project}/admins/{user}/resgate', [CompanyAdminController::class, 'grantRedemption'])
        ->whereNumber(['project', 'user'])->name('projects.admins.redemption.grant');
    Route::delete('/projects/{project}/admins/{user}/resgate', [CompanyAdminController::class, 'revokeRedemption'])
        ->whereNumber(['project', 'user'])->name('projects.admins.redemption.revoke');
    Route::get('/projects/{project}/entitlements/modelo', [ProjectController::class, 'entitlementsTemplate'])->name('projects.entitlements.template');
    Route::post('/projects/{project}/entitlements/batch', [ProjectController::class, 'batchEntitlements'])->name('projects.entitlements.batch');
    // Confere a planilha sem cadastrar ninguém. Mesmo grupo de permissão da
    // importação: quem valida é quem vai importar.
    Route::post('/projects/{project}/entitlements/batch/validate', [ProjectController::class, 'validateBatchEntitlements'])->name('projects.entitlements.batch.validate');
    // As linhas recusadas de uma importação, numa planilha para corrigir e
    // subir de novo só elas.
    Route::get('/projects/{project}/importacoes/{import}/recusadas', [ProjectController::class, 'importFailures'])
        ->whereNumber(['project', 'import'])->name('projects.imports.failures');
    Route::post('/projects/{project}/importacoes/{import}/cancelar', [ProjectController::class, 'cancelImport'])
        ->whereNumber(['project', 'import'])->name('projects.imports.cancel');
    // O aviso flutuante de importação (JSON): as planilhas que a pessoa subiu
    // e que ainda correm ou acabaram de terminar.
    Route::get('/importacoes/acompanhar', [ProjectController::class, 'trackImports'])->name('imports.track');
    Route::post('/projects/{project}/entitlements/{entitlement}/resend-invite', [ProjectController::class, 'resendInvite'])->name('projects.entitlements.resend');
    // Remover Usuario do Projeto: o RH tira quem saiu da empresa.
    Route::delete('/projects/{project}/entitlements/{entitlement}', [ProjectController::class, 'destroyEntitlement'])->name('projects.entitlements.destroy');
    // Tirar a pessoa do Projeto inteira, numa chamada só (todas as autorizações).
    Route::delete('/projects/{project}/colaboradores/{user}', [ProjectController::class, 'destroyCollaborator'])
        ->whereNumber(['project', 'user'])->name('projects.collaborators.destroy');
});

// Parametrizações e administração (Admin WeGift)
Route::middleware(['auth', 'role:admin'])->group(function () {
    // Exclusão definitiva de Projeto (apaga resgates, rastreios e autorizações;
    // empresa e usuários ficam). Só Admin WeGift — N1 não exclui.
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
        ->whereNumber('project')->name('projects.destroy');

    // Tirar itens do Projeto (só sem resgate) e devolvê-los. A Omie não
    // traz de volta o item removido.
    Route::delete('/projects/{project}/itens', [ProjectController::class, 'destroyItems'])
        ->whereNumber('project')->name('projects.items.destroy');
    Route::post('/projects/{project}/itens-removidos/{codigo}/restaurar', [ProjectController::class, 'restoreItem'])
        ->whereNumber('project')->name('projects.items.restore');

    // Tirar um Admin do Projeto (apaga a conta, ou desativa quando ela tem
    // histórico). Mesmo nível de quem exclui o Projeto.
    Route::delete('/projects/{project}/admins/{user}', [CompanyAdminController::class, 'destroy'])
        ->whereNumber(['project', 'user'])->name('projects.admins.destroy');
    Route::post('/projects/{project}/admins/{user}/resend-invite', [CompanyAdminController::class, 'resendInvite'])
        ->whereNumber(['project', 'user'])->name('projects.admins.resend');

    // Soltar o freio do resgate: SÓ Admin WeGift, mesmo quando quem bloqueou
    // foi o cliente. Nem o N1 solta — a conferência é da WeGift.
    Route::post('/projects/{project}/unblock-redemptions', [ProjectController::class, 'unblockRedemptions'])
        ->whereNumber('project')->name('projects.redemptions.unblock');

    // Transportadoras: o cadastro que diz, por transportadora, se o rastreio
    // chega como código ou como link pronto.
    Route::get('/admin/transportadoras', [CarrierController::class, 'index'])->name('admin.carriers.index');
    Route::post('/admin/transportadoras', [CarrierController::class, 'store'])->name('admin.carriers.store');
    Route::put('/admin/transportadoras/{carrier}', [CarrierController::class, 'update'])->name('admin.carriers.update');
    Route::delete('/admin/transportadoras/{carrier}', [CarrierController::class, 'destroy'])->name('admin.carriers.destroy');

    Route::get('/benefits', [FiscalBenefitController::class, 'index'])->name('benefits.index');
    Route::post('/benefits', [FiscalBenefitController::class, 'store'])->name('benefits.store');
    Route::delete('/benefits/{benefit}', [FiscalBenefitController::class, 'destroy'])->name('benefits.destroy');

    // Usuários WeGift
    Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users.index');
    Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
    Route::put('/admin/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
    Route::post('/admin/users/{user}/toggle', [UserController::class, 'toggleActive'])->name('admin.users.toggle');
    Route::post('/admin/users/{user}/resend-invite', [UserController::class, 'resendInvite'])->name('admin.users.resend');
    // Exclusao definitiva de qualquer usuario (equipe ou cliente): so Admin WeGift.
    Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');

    // Empresas cliente + admins
    Route::get('/admin/companies', [CompanyController::class, 'index'])->name('admin.companies.index');
    Route::post('/admin/companies', [CompanyController::class, 'store'])->name('admin.companies.store');
    Route::get('/admin/companies/modelo', [CompanyController::class, 'template'])->name('admin.companies.template');
    // Níveis da consulta em árvore, buscados ao abrir cada um. Antes de
    // `/{company}` pelo mesmo motivo de `/modelo`: a rota curinga engoliria a
    // palavra como se fosse um id.
    Route::get('/admin/companies/buscar-pessoas', [CompanyController::class, 'findPeople'])
        ->name('admin.companies.people.search');
    Route::get('/admin/companies/{company}/projetos', [CompanyController::class, 'projects'])
        ->whereNumber('company')->name('admin.companies.projects');
    Route::get('/admin/projetos/{project}/pessoas', [CompanyController::class, 'people'])
        ->whereNumber('project')->name('admin.projects.people');
    Route::post('/admin/companies/lote', [CompanyController::class, 'batch'])->name('admin.companies.batch');
    // Depois de `/modelo`: a rota curinga engoliria a palavra como se fosse um id.
    Route::get('/admin/companies/{company}', [CompanyController::class, 'show'])->name('admin.companies.show');
    Route::put('/admin/companies/{company}', [CompanyController::class, 'update'])->name('admin.companies.update');
    // Customizações da Empresa (liga/desliga de feature). Uma chave por vez.
    Route::patch('/admin/companies/{company}/customizations', [CompanyController::class, 'updateCustomization'])
        ->name('admin.companies.customizations.update');

    Route::put('/admin/companies/{company}/import-columns', [CompanyController::class, 'updateImportColumns'])
        ->name('admin.companies.import-columns.update');

    // Logo do Cliente (a marca da empresa) e onde a logo aparece.
    Route::put('/admin/companies/{company}/logo-levels', [CompanyController::class, 'updateLogoLevels'])
        ->name('admin.companies.logo-levels.update');
    Route::post('/admin/companies/{company}/logo', [CompanyController::class, 'storeLogo'])
        ->name('admin.companies.logo.store');
    Route::delete('/admin/companies/{company}/logo', [CompanyController::class, 'destroyLogo'])
        ->name('admin.companies.logo.destroy');
    // E-mail de atendimento do Cliente no portal do Colaborador.
    Route::put('/admin/companies/{company}/support-email', [CompanyController::class, 'updateSupportEmail'])
        ->name('admin.companies.support-email.update');
    // Endereço (/primeiro-acesso/sotreq) e aviso da página de primeiro acesso.
    Route::put('/admin/companies/{company}/first-access', [CompanyController::class, 'updateFirstAccess'])
        ->name('admin.companies.first-access.update');

    Route::post('/admin/companies/{company}/admins', [CompanyController::class, 'storeAdmin'])->name('admin.companies.admins.store');
    // Tirar um Admin Cliente (apaga a conta, ou desativa quando ela tem histórico).
    Route::delete('/admin/companies/{company}/admins/{user}', [CompanyController::class, 'destroyAdmin'])
        ->whereNumber(['company', 'user'])->name('admin.companies.admins.destroy');
    Route::post('/admin/companies/{company}/admins/{user}/resend-invite', [CompanyController::class, 'resendAdminInvite'])->name('admin.companies.admins.resend');
    // Exclusão definitiva da empresa, com Projetos e contas dela (só Admin WeGift)
    Route::delete('/admin/companies/{company}', [CompanyController::class, 'destroy'])->name('admin.companies.destroy');

    // Log de webhooks — diagnóstico de "chegou e não virou nada"
    Route::get('/admin/webhooks', [WebhookEventController::class, 'index'])->name('admin.webhooks.index');
    Route::post('/admin/webhooks/{webhookEvent}/reprocess', [WebhookEventController::class, 'reprocess'])->name('admin.webhooks.reprocess');

    // Trilha de auditoria — quem fez o quê (ver docs/SEGURANCA.md)
    Route::get('/admin/auditoria', [AuditLogController::class, 'index'])->name('admin.audit.index');
    Route::get('/admin/auditoria/exportar', [AuditLogController::class, 'export'])->name('admin.audit.export');

    // Modelos de e-mail (Módulo 11)
    Route::get('/admin/email-templates', [EmailTemplateController::class, 'index'])->name('admin.email-templates.index');
    // A chave identifica a mensagem; o ALCANCE (geral, cliente, projeto) vem
    // na querystring, porque no nível do cliente a linha pode ainda não existir.
    Route::put('/admin/email-templates/{key}', [EmailTemplateController::class, 'update'])->name('admin.email-templates.update');
    Route::delete('/admin/email-templates/{key}', [EmailTemplateController::class, 'restore'])->name('admin.email-templates.restore');
});

/*
|--------------------------------------------------------------------------
| Portal do cliente final (Fluxo A)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:portal'])->prefix('portal')->group(function () {
    Route::get('/', [PortalController::class, 'catalog'])->name('portal.catalog');
    Route::get('/resgatar/{entitlement}', [PortalController::class, 'redeemForm'])->name('portal.redeem.form');
    Route::post('/resgatar/{entitlement}', [PortalController::class, 'redeem'])->name('portal.redeem');
    Route::get('/meus-resgates', [PortalController::class, 'redemptions'])->name('portal.redemptions');
});
