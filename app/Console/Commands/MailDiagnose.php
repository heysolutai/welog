<?php

namespace App\Console\Commands;

use App\Mail\AccountInvitationMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Por que o e-mail para FULANO não chegou.
 *
 *   php artisan welog:mail:diagnostico fulano@wegift.com.br
 *   php artisan welog:mail:diagnostico fulano@wegift.com.br --reenviar
 *
 * O envio normal é assíncrono: a falha mora na fila, longe de quem clicou.
 * Este comando junta as quatro peças que respondem a pergunta (conta, config
 * de e-mail, fila e falhas) e, com `--reenviar`, manda o convite AGORA, de
 * forma síncrona, para o erro do provedor aparecer na tela em vez de sumir
 * num job.
 */
class MailDiagnose extends Command
{
    protected $signature = 'welog:mail:diagnostico
                            {email : Destinatário que não recebeu}
                            {--reenviar : Reenvia o convite agora, sem fila, mostrando o erro real}';

    protected $description = 'Diagnostica por que um e-mail não chegou a um destinatário e permite reenviar na hora';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        // 1. Configuração em vigor NESTE processo.
        $this->line('<options=bold>1. Configuração de e-mail</>');
        $this->line('   mailer: <options=bold>'.config('mail.default').'</>'
            .'   remetente: <options=bold>'.config('mail.from.address').'</>'
            .'   fila: '.config('mail.queue'));

        if (config('mail.default') === 'log') {
            $this->warn('   MAIL_MAILER=log: nada sai de verdade, só vai para storage/logs.');
        }

        // 2. A conta existe? Foi convidada?
        $this->newLine();
        $this->line('<options=bold>2. Conta no WeLog</>');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error('   Nenhum usuário com este e-mail. O cadastro não chegou a ser feito');
            $this->line('   (ou foi feito com o e-mail escrito de outro jeito).');
            $semelhantes = User::where('email', 'like', '%'.explode('@', $email)[0].'%')->pluck('email');
            if ($semelhantes->isNotEmpty()) {
                $this->line('   Parecidos: '.$semelhantes->implode(', '));
            }

            return self::FAILURE;
        }

        $this->line("   #{$user->id}  {$user->name}  ·  papel: {$user->role}");
        $this->line('   senha: '.($user->password_set_at ? 'já definida' : '<fg=yellow>ainda não definida</>'));
        $this->line('   convite enviado em: '.($user->invited_at
            ? $user->invited_at->format('d/m/Y H:i:s')
            : '<fg=red>NUNCA</> (o convite não chegou a ser disparado)'));

        // 3. A fila está andando?
        $this->newLine();
        $this->line('<options=bold>3. Fila</>');
        $pendentes = DB::table('jobs')->count();
        $falhas = DB::table('failed_jobs')->count();
        $this->line("   jobs aguardando: {$pendentes}   ·   com falha: {$falhas}");

        if ($pendentes > 0) {
            $this->warn('   Há job parado: se o worker estiver fora do ar, nada é enviado.');
            $this->line('   Confira: docker compose ps worker');
        }

        // 4. Alguma falha menciona este e-mail?
        $doDestinatario = DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$email.'%')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        if ($doDestinatario->isNotEmpty()) {
            $this->newLine();
            $this->error('   Falhas relacionadas a este e-mail:');
            foreach ($doDestinatario as $falha) {
                $this->line('   · '.substr((string) $falha->exception, 0, 300));
            }
        }

        if (! $this->option('reenviar')) {
            $this->newLine();
            $this->line('Para reenviar AGORA e ver o erro do provedor na hora:');
            $this->line("  <options=bold>php artisan welog:mail:diagnostico {$email} --reenviar</>");

            return self::SUCCESS;
        }

        // 5. Reenvio síncrono: o erro do provedor aparece aqui.
        $this->newLine();
        $this->line('<options=bold>4. Reenviando o convite agora (sem fila)</>');

        if ($user->hasSetPassword()) {
            $this->warn('   Este usuário já definiu a senha: o convite não se aplica mais.');
            $this->line('   Ele deve entrar normalmente, ou usar "Esqueci minha senha".');

            return self::SUCCESS;
        }

        try {
            $token = Password::broker('invites')->createToken($user);
            // `send`, não `queue`: o erro do provedor precisa estourar aqui.
            Mail::to($user->email)->send(new AccountInvitationMail($user, $token));
            $user->forceFill(['invited_at' => now()])->save();

            $this->info('   ✓ Enviado sem erro pelo mailer '.config('mail.default').'.');
            $this->line('   Se ainda assim não chegar, o problema é do provedor: confira o painel dele');
            $this->line('   (domínio verificado, remetente autorizado, caixa de spam do destinatário).');
        } catch (Throwable $e) {
            $this->error('   ✗ Falhou: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
