<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use Tests\TestCase;

/**
 * Garante os dois extremos da integração: em teste/dev ela fica calada, e o
 * que é regra de negócio não vira alerta em produção.
 */
class SentryIntegrationTest extends TestCase
{
    public function test_sem_dsn_o_sdk_nao_e_ativado(): void
    {
        // phpunit.xml não define SENTRY_LARAVEL_DSN de propósito: rodar a suíte
        // não pode consumir cota nem mandar stack trace para fora.
        $this->assertEmpty(
            config('sentry.dsn'),
            'A suíte não pode rodar com DSN configurado.',
        );
    }

    public function test_pii_do_usuario_nunca_e_enviado_por_padrao(): void
    {
        $this->assertFalse(
            config('sentry.send_default_pii'),
            'send_default_pii anexaria CPF e endereço do cliente final ao evento.',
        );
    }

    public function test_bindings_de_query_ficam_fora_dos_breadcrumbs(): void
    {
        // O binding é o VALOR da query — CPF, e-mail, endereço.
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertFalse(config('sentry.tracing.sql_bindings'));
    }

    public function test_violacao_de_regra_de_negocio_e_ignorada(): void
    {
        // DomainException é mensagem para o usuário ("Projeto já foi liberado"),
        // não defeito. Sem isto o Sentry viraria um segundo access log.
        $this->assertContains(DomainException::class, config('sentry.ignore_exceptions'));
    }

    public function test_erros_esperados_de_http_sao_ignorados(): void
    {
        $ignoradas = config('sentry.ignore_exceptions');

        foreach ([
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
        ] as $classe) {
            $this->assertContains($classe, $ignoradas, "{$classe} deveria ser ignorada");
        }
    }

    public function test_o_scrubber_esta_ligado_nos_tres_ganchos(): void
    {
        // Uma config publicada sem os ganchos passaria a mandar PII sem aviso.
        foreach (['before_send', 'before_send_transaction', 'before_breadcrumb'] as $gancho) {
            $this->assertIsCallable(config("sentry.{$gancho}"), "{$gancho} não está ligado");
        }
    }
}
