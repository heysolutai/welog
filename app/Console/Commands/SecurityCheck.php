<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Confere, no servidor, o que o questionário de segurança dos clientes afirma.
 *
 *   php artisan welog:seguranca
 *
 * Cada resposta do questionário ("conexão com o banco cifrada", "backup
 * diário", "debug desligado") vira uma checagem com resultado OK/FALHA. É a
 * evidência para o assessment — e o jeito de descobrir ANTES dele que alguma
 * afirmação deixou de ser verdade (um upgrade do banco que quebrou o backup,
 * um .env recriado com `DB_SSLMODE=prefer`).
 *
 * Sai com código 1 se qualquer item falhar: dá para pendurar num monitor.
 */
class SecurityCheck extends Command
{
    protected $signature = 'welog:seguranca
                            {--backup-path=/backups : Diretório do volume de backups}';

    protected $description = 'Confere as garantias de segurança declaradas aos clientes (TLS do banco, backup, debug, cookies)';

    /** @var array<int, array{0: string, 1: bool, 2: string}> */
    private array $itens = [];

    public function handle(): int
    {
        $this->ambiente();
        $this->bancoCifrado();
        $this->sessao();
        $this->backup();
        $this->auditoria();
        $this->simulacoesDesligadas();

        $this->table(
            ['Garantia', 'Resultado', 'Detalhe'],
            array_map(fn (array $i) => [$i[0], $i[1] ? '<fg=green>OK</>' : '<fg=red>FALHA</>', $i[2]], $this->itens),
        );

        $falhas = count(array_filter($this->itens, fn (array $i) => ! $i[1]));

        if ($falhas > 0) {
            $this->error("{$falhas} garantia(s) não atendida(s).");

            return self::FAILURE;
        }

        $this->info('Todas as garantias atendidas.');

        return self::SUCCESS;
    }

    private function item(string $garantia, bool $ok, string $detalhe): void
    {
        $this->itens[] = [$garantia, $ok, $detalhe];
    }

    private function ambiente(): void
    {
        $env = (string) config('app.env');
        $this->item('Ambiente de produção', $env === 'production', "APP_ENV={$env}");

        // Debug ligado expõe stack trace, variáveis e trechos de código na tela de erro.
        $debug = (bool) config('app.debug');
        $this->item('Modo debug desligado', ! $debug, 'APP_DEBUG='.($debug ? 'true' : 'false'));

        $this->item('Chave da aplicação definida', filled(config('app.key')), filled(config('app.key')) ? 'APP_KEY presente' : 'APP_KEY vazia');
    }

    /**
     * Conexão com o banco cifrada — conferida na conexão REAL, não só no .env.
     *
     * `prefer` (o padrão do driver) aceita cair para texto puro em silêncio se
     * o TLS falhar; por isso a configuração também é exigida, e não só o
     * estado da conexão de agora.
     */
    private function bancoCifrado(): void
    {
        $conexao = config('database.default');

        if (config("database.connections.{$conexao}.driver") !== 'pgsql') {
            $this->item('Conexão com o banco cifrada (TLS)', false, "driver {$conexao} — checagem só existe para Postgres");

            return;
        }

        $modo = (string) config("database.connections.{$conexao}.sslmode");
        $exigido = in_array($modo, ['require', 'verify-ca', 'verify-full'], true);

        try {
            $ssl = DB::selectOne('select ssl, version from pg_stat_ssl where pid = pg_backend_pid()');
            $cifrada = (bool) ($ssl->ssl ?? false);
            $versao = $ssl->version ?? '—';
        } catch (Throwable $e) {
            $this->item('Conexão com o banco cifrada (TLS)', false, 'não foi possível consultar: '.$e->getMessage());

            return;
        }

        $this->item(
            'Conexão com o banco cifrada (TLS)',
            $exigido && $cifrada,
            "DB_SSLMODE={$modo}".($exigido ? '' : ' (use require ou verify-full)').' · conexão atual: '.($cifrada ? "cifrada ({$versao})" : 'SEM TLS'),
        );
    }

    private function sessao(): void
    {
        // Cookie de sessão só por HTTPS: sem isto ele trafega em texto se
        // alguém abrir o site por http:// antes do redirect.
        $secure = (bool) config('session.secure');
        $this->item('Cookie de sessão só em HTTPS', $secure, 'SESSION_SECURE_COOKIE='.($secure ? 'true' : 'false'));
    }

    private function backup(): void
    {
        $codigo = Artisan::call('welog:backup:status', ['--path' => $this->option('backup-path')]);
        $saida = trim(Artisan::output());
        $ultima = collect(explode("\n", $saida))->last(fn (string $l) => trim($l) !== '') ?? '';

        $this->item('Backup diário do banco em dia', $codigo === self::SUCCESS, trim($ultima));
    }

    /**
     * A trilha gravando, e a revisão trimestral de acessos em dia.
     *
     * Trilha vazia na última semana numa plataforma em uso quer dizer que a
     * gravação quebrou (a falha ao auditar não derruba a ação, de propósito —
     * então é aqui que ela aparece).
     */
    private function auditoria(): void
    {
        $semana = AuditLog::where('created_at', '>=', now()->subDays(7))->count();
        $this->item('Trilha de auditoria gravando', $semana > 0, "{$semana} registro(s) nos últimos 7 dias");

        $revisao = AuditLog::where('action', AuditLog::ACCESS_REVIEW)->latest('id')->first();
        $emDia = $revisao !== null && $revisao->created_at->gt(now()->subDays(100));

        $this->item(
            'Revisão de acessos trimestral',
            $emDia,
            $revisao
                ? 'última em '.$revisao->created_at->format('d/m/Y').($emDia ? '' : ' — rode welog:acessos:revisao')
                : 'nunca executada — rode welog:acessos:revisao',
        );
    }

    private function simulacoesDesligadas(): void
    {
        $nf = (bool) config('integrations.simulated_invoices');
        $this->item('NF simulada desligada', ! $nf, 'WELOG_SIMULATED_INVOICES='.($nf ? 'true' : 'false'));
    }
}
