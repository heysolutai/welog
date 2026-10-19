<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Estado dos backups do Postgres.
 *
 * O modo de falha perigoso de um backup automático é falhar em silêncio: só se
 * descobre na hora de restaurar. Este comando responde "existe uma cópia
 * recente e com tamanho plausível?" com código de saída utilizável por
 * monitoramento externo.
 *
 * O volume `welog-backups` é montado somente-leitura em /backups (compose de
 * produção); o serviço que gera os dumps é o `backup`.
 */
class BackupStatus extends Command
{
    protected $signature = 'welog:backup:status
                            {--path=/backups : Diretório onde o serviço de backup grava}
                            {--max-age=36 : Idade máxima tolerada do backup mais recente, em horas}';

    protected $description = 'Verifica se existe um backup do banco recente e íntegro';

    public function handle(): int
    {
        $path = rtrim((string) $this->option('path'), '/');
        $maxAgeHours = (int) $this->option('max-age');

        if (! is_dir($path)) {
            $this->error("Diretório de backups não encontrado: {$path}");
            $this->line('Em produção ele vem do volume `welog-backups`; confira o serviço `backup`.');

            return self::FAILURE;
        }

        $this->reportLastRun($path);

        $daily = $this->dumps("{$path}/daily");
        $weekly = $this->dumps("{$path}/weekly");

        if ($daily === []) {
            $this->error('Nenhum backup diário encontrado.');

            return self::FAILURE;
        }

        $newest = $daily[0];
        $ageHours = (time() - filemtime($newest)) / 3600;

        $this->table(['Item', 'Valor'], [
            ['backups diários', count($daily)],
            ['backups semanais', count($weekly)],
            ['mais recente', basename($newest)],
            ['tamanho', $this->humanSize(filesize($newest))],
            ['idade', sprintf('%.1f h', $ageHours)],
            ['espaço total', $this->humanSize(array_sum(array_map('filesize', [...$daily, ...$weekly])))],
        ]);

        if ($ageHours > $maxAgeHours) {
            $this->error(sprintf(
                'Backup mais recente tem %.1f h (limite: %d h) — o serviço `backup` pode estar parado.',
                $ageHours,
                $maxAgeHours,
            ));

            return self::FAILURE;
        }

        $this->info('Backups em dia.');

        return self::SUCCESS;
    }

    /** Última linha escrita pelo script de backup (OK ou FALHA). */
    private function reportLastRun(string $path): void
    {
        $statusFile = "{$path}/last-status";

        if (! is_file($statusFile)) {
            return;
        }

        $status = trim((string) file_get_contents($statusFile));

        str_contains($status, 'FALHA')
            ? $this->error("Última execução: {$status}")
            : $this->line("Última execução: {$status}");
    }

    /** @return array<int, string> dumps do mais recente para o mais antigo */
    private function dumps(string $directory): array
    {
        $files = glob("{$directory}/welog-*.dump") ?: [];

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files;
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return sprintf('%.1f %s', $bytes, $unit);
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
