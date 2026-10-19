<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Projects\ProjectPurger;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;

/**
 * Deixa na base SÓ as empresas indicadas — o resto (empresas, Projetos,
 * resgates, rastreio, Usuários do portal e Admin Clientes) some.
 *
 * Irmão de `welog:projects:purge`, com a mesma filosofia: a API é "quem FICA".
 * A diferença é a granularidade: aqui se mantém a empresa inteira, e as
 * outras vão embora mesmo tendo Admin Cliente vinculado — que é exatamente o
 * caso que a limpeza por Projeto poupa de propósito.
 *
 *   php artisan welog:companies:purge --keep=46.997.756/0001-33            (simula)
 *   php artisan welog:companies:purge --keep=46.997.756/0001-33 --execute --force
 */
class PurgeCompanies extends Command
{
    use ConfirmableTrait;

    protected $signature = 'welog:companies:purge
                            {--keep= : CNPJs (com ou sem máscara) ou ids das empresas a MANTER, separados por vírgula}
                            {--execute : Executa de verdade; sem isto é apenas simulação}
                            {--force : Pula a confirmação de produção (obrigatório com docker exec -T, que não tem TTY)}';

    protected $description = 'Apaga todas as empresas menos as indicadas, com Projetos, resgates e usuários delas';

    public function handle(ProjectPurger $purger): int
    {
        $manter = $this->empresasParaManter();

        if ($manter->isEmpty()) {
            $this->error('Nada a manter — recuse-se a esvaziar a base inteira.');
            $this->line('  --keep=46.997.756/0001-33          (CNPJ, com ou sem máscara)');
            $this->line('  --keep=12,15                        (ids do WeLog)');

            return self::FAILURE;
        }

        $ids = $manter->pluck('id')->all();
        $inventario = $purger->inventariarPorEmpresa($ids);

        $this->newLine();
        $this->line('Mantendo <options=bold>'.count($ids).'</> empresa(s):');
        foreach ($manter as $empresa) {
            $this->line("  · #{$empresa->id}  {$empresa->cnpj}  {$empresa->name}"
                ."  ({$empresa->projects()->count()} projeto(s), {$empresa->users()->count()} usuário(s))");
        }

        $this->newLine();
        $this->table(['Será apagado', 'Qtde'], [
            ['Empresas', $inventario['empresas']],
            ['Projetos', $inventario['projetos']],
            ['  └ autorizações de resgate', $inventario['entitlements']],
            ['  └ resgates', $inventario['resgates']],
            ['     └ eventos de rastreio', $inventario['rastreios']],
            ['Usuários (Admin Cliente + portal) das outras empresas', $inventario['usuarios']],
        ]);

        if (! $this->option('execute')) {
            $this->newLine();
            $this->warn('SIMULAÇÃO — nada foi apagado.');
            $this->line('  Confira o backup antes: <options=bold>php artisan welog:backup:status</>');
            $this->line('  Para executar de verdade, repita com <options=bold>--execute</>.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('Isto apaga dados de forma IRREVERSÍVEL')) {
            return self::FAILURE;
        }

        $apagados = $purger->apagarPorEmpresa($ids);

        $this->newLine();
        $this->info('Concluído.');
        $this->table(['Tabela', 'Apagados'], array_map(
            fn ($chave, $valor) => [$chave, $valor],
            array_keys($apagados),
            $apagados,
        ));

        return self::SUCCESS;
    }

    /** @return Collection<int, Company> */
    private function empresasParaManter()
    {
        $termos = array_filter(array_map('trim', explode(',', (string) $this->option('keep'))));
        $encontradas = collect();

        foreach ($termos as $termo) {
            $digitos = preg_replace('/\D/', '', $termo);

            // CNPJ (14) ou CPF (11) → busca por documento; senão, id do WeLog.
            $empresa = in_array(strlen($digitos), [11, 14], true)
                ? Company::where('cnpj', $digitos)->first()
                : (ctype_digit($termo) ? Company::find((int) $termo) : null);

            if ($empresa === null) {
                // Termo que não bate com nada aborta tudo: um CNPJ digitado
                // errado não pode virar "então apaga todas".
                $this->error("Empresa não encontrada: {$termo}");

                return collect();
            }

            $encontradas->push($empresa);
        }

        return $encontradas->unique('id')->values();
    }
}
