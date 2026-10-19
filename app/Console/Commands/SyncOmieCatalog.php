<?php

namespace App\Console\Commands;

use App\Integrations\Support\ApiThrottleException;
use App\Models\Product;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa o catálogo completo de produtos/kits da Omie.
 *
 * Sem isto, um produto só entrava no catálogo local quando aparecia em algum
 * pedido importado — então o operador não conseguia montar um Projeto com um
 * kit que ainda não tinha sido vendido.
 */
class SyncOmieCatalog extends Command
{
    protected $signature = 'welog:omie:sync-catalog';

    protected $description = 'Importa da Omie todos os produtos e kits (com estrutura BOM) para o catálogo local';

    public function handle(MirrorOmieProduct $mirror): int
    {
        if (config('integrations.omie.driver') !== 'api') {
            $this->warn('OMIE_DRIVER está em `'.config('integrations.omie.driver').'` — os dados virão do gateway simulado, não da Omie real.');

            if (! $this->option('no-interaction') && ! $this->confirm('Continuar mesmo assim?', false)) {
                return self::FAILURE;
            }
        }

        $antes = Product::count();
        $this->info("Catálogo local antes: {$antes} produtos.");
        $this->line('Importando… (respeitando o teto de '.config('integrations.omie.requests_per_minute').' chamadas/min)');

        try {
            $total = $mirror->syncAll();
        } catch (ApiThrottleException $e) {
            $this->error('Teto de consumo da Omie atingido: '.$e->getMessage());
            $this->line('Reduza OMIE_REQUESTS_PER_MINUTE ou rode de novo em alguns minutos.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Falha na importação: '.$e->getMessage());

            return self::FAILURE;
        }

        $depois = Product::count();

        $this->newLine();
        $this->table(['', 'Quantidade'], [
            ['registros lidos da Omie', $total],
            ['produtos no catálogo', $depois],
            ['novos', $depois - $antes],
            ['kits (com BOM)', Product::has('components')->count()],
        ]);

        $this->info('Catálogo sincronizado.');

        return self::SUCCESS;
    }
}
