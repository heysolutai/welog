<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

/**
 * Gera o link de primeiro acesso de um usuário para entrega manual.
 *
 * Existe porque o convite depende de SMTP: enquanto o servidor de e-mail não
 * estiver configurado (ou quando um convite cai em failed_jobs), o usuário fica
 * travado sem conseguir definir a senha. Com isto o Admin WeGift pega o link e
 * repassa pelo canal que tiver.
 *
 * ⚠️ O link permite DEFINIR A SENHA da conta. Trate como credencial: mande por
 * canal privado e não cole em grupo, ticket ou log.
 */
class InviteLink extends Command
{
    protected $signature = 'welog:invite:link {email : E-mail do usuário}';

    protected $description = 'Gera o link de primeiro acesso/redefinição de senha para envio manual';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("Nenhum usuário com o e-mail {$email}.");

            // Ajuda a achar erro de digitação sem expor a base inteira
            $parecidos = User::where('email', 'like', '%'.substr($email, 0, 4).'%')
                ->limit(5)->pluck('email');

            if ($parecidos->isNotEmpty()) {
                $this->line('Parecidos: '.$parecidos->implode(', '));
            }

            return self::FAILURE;
        }

        $this->table(['Campo', 'Valor'], [
            ['nome', $user->name],
            ['e-mail', $user->email],
            ['perfil', $user->role],
            ['empresa', $user->company?->name ?? '—'],
            ['ativo', $user->active ? 'sim' : 'NÃO — o login será recusado'],
            ['senha definida', $user->password_set_at?->format('d/m/Y H:i') ?? 'ainda não'],
            ['convite enviado', $user->invited_at?->format('d/m/Y H:i') ?? 'nunca'],
        ]);

        if ($user->password_set_at !== null) {
            $this->warn('Este usuário já definiu a senha.');
            $this->line('O link abaixo serve como redefinição — a senha atual deixará de valer.');

            if (! $this->confirm('Gerar mesmo assim?', false)) {
                return self::SUCCESS;
            }
        }

        // Mesmo mecanismo do convite por e-mail (password_reset_tokens), então
        // o link expira conforme config/auth.php (60 min por padrão).
        $token = Password::broker('invites')->createToken($user);
        $user->forceFill(['invited_at' => now()])->save();

        $url = route('password.set.form', ['token' => $token, 'email' => $user->email]);

        $this->newLine();
        $this->info('Link de primeiro acesso:');
        $this->line($url);
        $this->newLine();
        $this->warn('Expira em '.config('auth.passwords.users.expire', 60).' minutos. Vale uma vez.');
        $this->line('Trate como senha: canal privado, sem colar em grupo ou ticket.');

        if (! $user->active) {
            $this->newLine();
            $this->error('Atenção: a conta está INATIVA. Mesmo definindo a senha, o login será recusado.');
            $this->line('Ative em /admin/users antes de repassar o link.');
        }

        return self::SUCCESS;
    }
}
