<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Revisão periódica de acessos — quem tem acesso privilegiado, e quem não
 * deveria mais ter.
 *
 *   php artisan welog:acessos:revisao
 *   php artisan welog:acessos:revisao --csv=storage/app/revisao-2026T4.csv --responsavel="Fulano"
 *
 * Lista toda conta que enxerga mais que o próprio resgate (equipe WeGift,
 * Admin Cliente, Admin de Projeto) e marca para REVISAR a que está ativa sem
 * uso há mais de WELOG_ACCESS_REVIEW_IDLE_DAYS — conta parada é a que vaza sem
 * ninguém notar, porque o dono saiu da empresa e ninguém avisou.
 *
 * O comando não desativa nada: quem decide é quem revisa, na tela de usuários.
 * A execução fica na trilha de auditoria, que é a evidência de que a revisão
 * aconteceu (procedimento trimestral em docs/SEGURANCA.md).
 */
class AccessReview extends Command
{
    protected $signature = 'welog:acessos:revisao
                            {--csv= : Salva a lista também em CSV (evidência da revisão)}
                            {--responsavel= : Quem conduziu a revisão (vai para a trilha de auditoria)}';

    protected $description = 'Lista contas com acesso privilegiado e aponta as paradas há muito tempo';

    private const PAPEIS = [
        User::ROLE_ADMIN_WEGIFT,
        User::ROLE_WEGIFT_N1,
        User::ROLE_WEGIFT_N2,
        User::ROLE_ADMIN_CLIENT,
        User::ROLE_PROJECT_ADMIN,
    ];

    public function handle(): int
    {
        $limite = (int) config('welog.audit.access_review_idle_days', 90);
        $corte = now()->subDays($limite);

        $contas = User::query()
            ->with('company:id,name')
            ->whereIn('role', self::PAPEIS)
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $linhas = $contas->map(function (User $u) use ($corte) {
            // Referência de "uso": o último login; sem nenhum, a data do convite.
            $referencia = $u->last_login_at ?? $u->invited_at ?? $u->created_at;
            $revisar = $u->active && $referencia !== null && $referencia->lt($corte);

            return [
                'nome' => $u->name,
                'email' => $u->email,
                'perfil' => $u->role,
                'empresa' => $u->company?->name ?? 'WeGift',
                'ativa' => $u->active ? 'sim' : 'não',
                'ultimo_login' => $u->last_login_at?->format('d/m/Y') ?? 'nunca',
                'situacao' => match (true) {
                    ! $u->active => 'desativada',
                    $revisar => 'REVISAR',
                    default => 'ok',
                },
            ];
        });

        $this->table(
            ['Nome', 'E-mail', 'Perfil', 'Empresa', 'Ativa', 'Último login', 'Situação'],
            $linhas->map(fn (array $l) => [
                ...array_values(array_diff_key($l, ['situacao' => 1])),
                $l['situacao'] === 'REVISAR' ? '<fg=yellow>REVISAR</>' : $l['situacao'],
            ]),
        );

        $aRevisar = $linhas->where('situacao', 'REVISAR')->count();

        $this->newLine();
        $this->line("{$contas->count()} conta(s) com acesso privilegiado · {$aRevisar} sem uso há mais de {$limite} dias.");

        if ($aRevisar > 0) {
            $this->warn('Desative na tela de usuários as contas de quem não precisa mais de acesso.');
        }

        if ($caminho = $this->option('csv')) {
            $this->salvarCsv($caminho, $linhas->all());
            $this->info("Lista salva em {$caminho}.");
        }

        Auditoria::registrar(AuditLog::ACCESS_REVIEW, 'Revisão periódica de acessos executada'
            .($this->option('responsavel') ? ' por '.$this->option('responsavel') : '').'.', dados: [
                'contas' => $contas->count(),
                'a_revisar' => $aRevisar,
                'limite_dias' => $limite,
                'em' => Carbon::now()->toIso8601String(),
            ]);

        return self::SUCCESS;
    }

    /** @param  array<int, array<string, string>>  $linhas */
    private function salvarCsv(string $caminho, array $linhas): void
    {
        $saida = fopen($caminho, 'w');
        fwrite($saida, "\xEF\xBB\xBF");
        fputcsv($saida, ['Nome', 'E-mail', 'Perfil', 'Empresa', 'Ativa', 'Último login', 'Situação'], ';');

        foreach ($linhas as $linha) {
            fputcsv($saida, array_values($linha), ';');
        }

        fclose($saida);
    }
}
