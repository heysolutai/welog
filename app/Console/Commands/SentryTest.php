<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

use function Sentry\captureException;
use function Sentry\captureMessage;

/**
 * Smoke test do Sentry — para rodar no servidor logo após o deploy.
 *
 * Envia de propósito uma exceção COM dado sensível dentro, para você conferir
 * na interface do Sentry que ele chegou filtrado. Se aparecer CPF ou e-mail
 * legível lá, o scrubber não está no caminho.
 */
class SentryTest extends Command
{
    protected $signature = 'welog:sentry:test {--pii : Inclui CPF e e-mail falsos, para validar a filtragem}';

    protected $description = 'Envia um evento de teste ao Sentry e confirma a configuração';

    public function handle(): int
    {
        $dsn = config('sentry.dsn');

        $this->table(['Configuração', 'Valor'], [
            ['DSN', $dsn ? substr($dsn, 0, 28).'…' : '(vazio — SDK inativo)'],
            ['environment', config('sentry.environment')],
            ['release', config('sentry.release') ?: '(não definido)'],
            ['send_default_pii', config('sentry.send_default_pii') ? 'true ⚠️' : 'false'],
            ['sql_bindings', config('sentry.breadcrumbs.sql_bindings') ? 'true ⚠️' : 'false'],
            ['traces_sample_rate', config('sentry.traces_sample_rate')],
            ['exceções ignoradas', count(config('sentry.ignore_exceptions', []))],
        ]);

        if (empty($dsn)) {
            $this->error('SENTRY_LARAVEL_DSN não configurado — nada será enviado.');
            $this->line('Em dev e nos testes isso é o esperado. Em produção, preencha o .env.');

            return self::FAILURE;
        }

        if (config('sentry.send_default_pii')) {
            $this->warn('send_default_pii está LIGADO: CPF e endereço do Usuário irão junto nos eventos.');
        }

        $mensagem = $this->option('pii')
            ? 'Teste WeLog — CPF 529.982.247-25 de joao.teste@exemplo.com.br deve aparecer FILTRADO no Sentry'
            : 'Teste WeLog — evento de verificação';

        captureMessage($mensagem);

        try {
            throw new RuntimeException('Exceção de teste da WeLog — pode ignorar e resolver.');
        } catch (Throwable $e) {
            $id = captureException($e);
        }

        $this->newLine();
        $this->info('Evento enviado: '.($id ?? 'sem id'));
        $this->line('Confira em Issues no painel do Sentry (pode levar alguns segundos).');

        if ($this->option('pii')) {
            $this->newLine();
            $this->warn('Confira no evento se o CPF e o e-mail aparecem como [filtrado].');
            $this->line('Se aparecerem legíveis, o before_send não está sendo aplicado.');
        }

        return self::SUCCESS;
    }
}
