<?php

use App\Support\Observability\SentryScrubber;

/*
|--------------------------------------------------------------------------
| Sentry — monitoramento de erros
|--------------------------------------------------------------------------
|
| Ativo apenas em produção: sem DSN o SDK não envia nada, então basta deixar
| SENTRY_LARAVEL_DSN vazio em dev e nos testes.
|
| ⚠️ LGPD: esta aplicação trafega CPF, endereço e e-mail de clientes finais.
| O `before_send` remove esses dados ANTES do evento sair do servidor — ver
| App\Support\Observability\SentryScrubber e docs/OBSERVABILIDADE.md.
|
*/

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // SHA do commit publicado — o CI preenche no deploy. Sem isto, o Sentry
    // não sabe em qual versão o erro apareceu nem quando foi resolvido.
    'release' => env('SENTRY_RELEASE'),

    /*
    | NUNCA ligar. `send_default_pii` anexa corpo da requisição, cookies e IP
    | ao evento — no nosso caso, CPF e endereço de entrega do cliente final.
    */
    'send_default_pii' => false,

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    // Corpo da requisição fora do evento: é a principal fonte de PII aqui
    // (formulário de resgate, cadastro de cliente final, upload em lote).
    'max_request_body_size' => 'none',

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        // Bindings carregam os VALORES da query — CPF, e-mail, endereço.
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'redis_commands' => false,
        'http_client_requests' => true,
        'views' => true,
        'missing_routes' => false,
        'continue_after_response' => true,
        'default_integrations' => true,
    ],

    /*
    | Exceções esperadas. Sem isto o Sentry vira um segundo access log: cada
    | CPF inválido numa planilha, cada 404 de robô e cada tentativa de resgate
    | duplicado geraria um "erro". Ruído afogando sinal é a forma mais comum de
    | um monitoramento parar de ser lido.
    */
    'ignore_exceptions' => [
        // Violação de regra de negócio — a mensagem é feita para o usuário ler
        App\Exceptions\DomainException::class,
        Illuminate\Validation\ValidationException::class,
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Auth\Access\AuthorizationException::class,
        Illuminate\Database\Eloquent\ModelNotFoundException::class,
        Illuminate\Session\TokenMismatchException::class,
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
        Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        // Rate limiting funcionando é comportamento correto, não incidente
        Illuminate\Http\Exceptions\ThrottleRequestsException::class,
    ],

    // Última barreira antes do envio: limpa PII e descarta o que é ruído.
    'before_send' => [SentryScrubber::class, 'beforeSend'],

    'before_send_transaction' => [SentryScrubber::class, 'beforeSendTransaction'],

    'before_breadcrumb' => [SentryScrubber::class, 'beforeBreadcrumb'],

];
