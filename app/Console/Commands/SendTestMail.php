<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Smoke test do envio de e-mail. Serve para validar o SMTP de produção logo
 * após o deploy, antes de qualquer Usuário receber um convite.
 *
 * Envia de forma SÍNCRONA de propósito: um erro de credencial, TLS ou DNS
 * aparece no terminal em vez de virar um job silencioso em failed_jobs.
 */
class SendTestMail extends Command
{
    protected $signature = 'welog:mail:test {email : Destinatário do e-mail de teste}';

    protected $description = 'Envia um e-mail de teste pelo mailer configurado (valida o SMTP de produção)';

    public function handle(): int
    {
        $recipient = (string) $this->argument('email');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("'{$recipient}' não é um e-mail válido.");

            return self::FAILURE;
        }

        $mailer = config('mail.default');

        $this->table(['Configuração', 'Valor'], [
            ['mailer', $mailer],
            ['host', config("mail.mailers.{$mailer}.host") ?? '—'],
            ['port', config("mail.mailers.{$mailer}.port") ?? '—'],
            ['scheme', config("mail.mailers.{$mailer}.scheme") ?? '—'],
            ['from', config('mail.from.address').' ('.config('mail.from.name').')'],
            ['fila', config('mail.queue').' — ignorada neste teste (envio síncrono)'],
        ]);

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log: nada sai para a internet, a mensagem vai para storage/logs.');
        }

        try {
            Mail::raw($this->body(), fn (Message $message) => $message
                ->to($recipient)
                ->subject('[WeLog] Teste de envio — '.config('app.env')));
        } catch (Throwable $e) {
            $this->error('Falha no envio: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("E-mail de teste enviado para {$recipient}.");
        $this->line('Confira a caixa de entrada (e o spam) e, se houver, o painel de entregas do provedor.');

        return self::SUCCESS;
    }

    private function body(): string
    {
        return implode(PHP_EOL, [
            'Este é um envio de teste da plataforma WeLog.',
            '',
            'Ambiente: '.config('app.env'),
            'URL: '.config('app.url'),
            'Mailer: '.config('mail.default'),
            'Enviado em: '.now()->format('d/m/Y H:i:s T'),
            '',
            'Se você recebeu esta mensagem, o envio de e-mails está funcionando.',
        ]);
    }
}
