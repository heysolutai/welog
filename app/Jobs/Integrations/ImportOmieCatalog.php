<?php

namespace App\Jobs\Integrations;

use App\Jobs\Concerns\IntegrationJob;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Importa o catálogo completo de produtos/kits da Omie em background, disparado
 * pela tela de Produtos (Módulo 5). Enfileirado para não bloquear a requisição
 * nem estourar timeout: o sync respeita o teto de chamadas/min da Omie e
 * percorre todas as páginas, então pode demorar.
 */
class ImportOmieCatalog implements ShouldBeUnique, ShouldQueue
{
    use IntegrationJob, Queueable;

    /** Evita que duas importações simultâneas dobrem o consumo da API Omie. */
    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(MirrorOmieProduct $mirror): void
    {
        $mirror->syncAll();
    }
}
