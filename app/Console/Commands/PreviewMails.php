<?php

namespace App\Console\Commands;

use App\Mail\AccountInvitationMail;
use App\Mail\ProjectReleasedMail;
use App\Mail\RedemptionConfirmedMail;
use App\Mail\ShipmentUpdateMail;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envia os e-mails REAIS do sistema para um endereço de teste.
 *
 * Diferente do `welog:mail:test`, que manda texto cru e só prova que o SMTP
 * responde: aqui saem os quatro mailables de produção, com a moldura da marca
 * e — nos três parametrizáveis — o assunto e o corpo lidos do banco. É a única
 * forma de ver o que o Usuário vai receber de fato.
 *
 * Envio SÍNCRONO de propósito: erro de credencial, domínio não verificado ou
 * TLS aparece no terminal, em vez de virar job silencioso em `failed_jobs`.
 */
class PreviewMails extends Command
{
    protected $signature = 'welog:mail:preview
        {email : Para onde enviar}
        {--only= : Só um deles (convite|liberacao|resgate|status)}';

    protected $description = 'Envia os e-mails reais (com os modelos do banco) para um endereço de teste';

    public function handle(): int
    {
        $destino = (string) $this->argument('email');

        if (filter_var($destino, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("'{$destino}' não é um e-mail válido.");

            return self::FAILURE;
        }

        $this->line('mailer: <options=bold>'.config('mail.default').'</> · remetente: '
            .config('mail.from.address'));
        $this->newLine();

        $enviados = 0;

        foreach ($this->mensagens() as $chave => $montar) {
            if ($this->option('only') && $this->option('only') !== $chave) {
                continue;
            }

            try {
                $mailable = $montar();
            } catch (Throwable $e) {
                $this->warn("{$chave}: pulado — {$e->getMessage()}");

                continue;
            }

            try {
                // `send`, não `queue`: com a fila, um erro de SMTP viraria job
                // em failed_jobs e o terminal diria "enviado".
                Mail::to($destino)->send($mailable);
                $this->info("{$chave}: enviado.");
                $enviados++;
            } catch (Throwable $e) {
                $this->error("{$chave}: FALHOU — {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line("{$enviados} e-mail(s) enviados para {$destino}.");

        if (config('mail.default') === 'smtp' && str_contains((string) config('mail.mailers.smtp.host'), 'mailpit')) {
            $this->comment('Mailpit: veja em http://localhost:8025');
        }

        return self::SUCCESS;
    }

    /**
     * Usa registros REAIS do banco: um mailable montado com model inventado
     * não passa pelas relações que o template percorre, e o teste passaria sem
     * provar nada.
     *
     * @return array<string, callable(): \Illuminate\Mail\Mailable>
     */
    private function mensagens(): array
    {
        return [
            'convite' => fn () => new AccountInvitationMail(
                $this->primeiro(User::whereNotNull('company_id'), 'nenhum Usuário vinculado a Empresa'),
                'token-de-exemplo-para-previa',
            ),

            'liberacao' => fn () => new ProjectReleasedMail(
                $this->primeiro(
                    ProjectEntitlement::with('user', 'product', 'project.company'),
                    'nenhum Usuário cadastrado em Projeto',
                ),
            ),

            'resgate' => fn () => new RedemptionConfirmedMail(
                $this->primeiro(Redemption::with('user', 'product'), 'nenhum resgate no banco'),
            ),

            'status' => fn () => new ShipmentUpdateMail(
                $this->primeiro(Redemption::with('user', 'product'), 'nenhum resgate no banco'),
                'Pedido a caminho!',
                'Seu presente saiu para entrega e chega nos próximos dias.',
            ),
        ];
    }

    /** @param \Illuminate\Database\Eloquent\Builder<*> $query */
    private function primeiro($query, string $motivo)
    {
        $registro = $query->latest('id')->first();

        if ($registro === null) {
            throw new \RuntimeException($motivo);
        }

        return $registro;
    }
}
