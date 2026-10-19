<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Integrations\Omie\OmieApiGateway;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O carimbo de "estrutura conferida".
 *
 * Sem ele, a pergunta que sobra é "tem componente?" — e a maioria do catálogo
 * é produto simples, que responde "não" para sempre. A varredura nunca fechava
 * em zero e o backfill reconsultava os mesmos ~140 códigos a cada execução,
 * que é exatamente a rajada punida pela Omie com bloqueio da conta.
 */
class KitStructureSyncTest extends TestCase
{
    use RefreshDatabase;

    private function gateway(): OmieApiGateway
    {
        return new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's');
    }

    /**
     * O comando resolve o gateway pelo container, e nos testes o driver é
     * `fake` — sem esta troca ele nem chega ao `Http::fake`, e uma asserção
     * sobre chamadas HTTP passa comparando zero com zero.
     */
    private function usarGatewayReal(): void
    {
        $this->app->instance(OmieGateway::class, $this->gateway());
    }

    private function produtoEmProjeto(string $code): Product
    {
        $product = Product::create(['omie_code' => $code, 'name' => "Produto {$code}"]);
        $company = Company::firstOrCreate(['cnpj' => '99888777000166'], ['name' => 'ACME']);

        Project::create(['company_id' => $company->id, 'name' => 'P', 'quantity' => 1])
            ->products()->attach($product->id, ['quantity' => 1]);

        return $product;
    }

    /** Produto simples conferido fica conferido — não volta para a fila do backfill. */
    public function test_produto_simples_e_marcado_como_conferido(): void
    {
        Http::fake(['*' => Http::response([
            'codigo' => 'SIMPLES-1',
            'descricao' => 'Caneta',
            'tipoItem' => 'PA',
            'componentes_kit' => null,
        ])]);

        $this->produtoEmProjeto('SIMPLES-1');

        (new MirrorOmieProduct(app(OmieAccounts::class), $this->gateway()))->handle('SIMPLES-1');

        $product = Product::where('omie_code', 'SIMPLES-1')->firstOrFail();

        $this->assertNotNull($product->structure_synced_at, 'sem componentes, mas conferido');
        $this->assertSame(0, $product->components()->count());
    }

    public function test_backfill_nao_reconsulta_o_que_ja_foi_conferido(): void
    {
        Http::fake(['*' => Http::response([
            'codigo' => 'SIMPLES-1', 'descricao' => 'Caneta', 'componentes_kit' => null,
        ])]);

        $this->usarGatewayReal();
        $this->produtoEmProjeto('SIMPLES-1');

        $this->artisan('welog:omie:sync-kit-structure --sleep=0')->assertSuccessful();
        $primeira = Http::recorded()->count();

        $this->artisan('welog:omie:sync-kit-structure --sleep=0')->assertSuccessful();

        $this->assertSame($primeira, Http::recorded()->count(), 'a segunda execução não pode gastar chamada');
    }

    /**
     * A Omie tem dois freios: `REDUNDANT` (aviso, ~60s, endpoint) e
     * `MISUSE_API_PROCESS` (bloqueio da conta, ~30min, tudo). Insistir no
     * primeiro leva ao segundo — foi assim que uma varredura derrubou a
     * integração inteira por meia hora, webhooks de produção junto.
     *
     * O comando para na primeira recusa. Uma tentativa a mais é o que separa
     * "espere um minuto" de "espere trinta".
     */
    public function test_freio_da_omie_interrompe_sem_insistir(): void
    {
        Http::fake(['*' => Http::response(
            '{"faultstring":"ERROR: Consumo redundante detectado. Aguarde 59 segundos para tentar novamente (REDUNDANT).","faultcode":"SOAP-ENV:Client-6"}',
            500,
        )]);

        $this->usarGatewayReal();
        $this->produtoEmProjeto('KIT-1');

        $this->artisan('welog:omie:sync-kit-structure --sleep=0')
            ->expectsOutputToContain('Espere 59s')
            ->assertFailed();

        // Uma chamada, não duas: o comando não pode bater na porta durante o castigo.
        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_bloqueio_de_conta_e_reportado_como_tal(): void
    {
        Http::fake(['*' => Http::response(
            '{"faultstring":"ERROR: API bloqueada por consumo indevido. Tente novamente em 1756 segundos.","faultcode":"MISUSE_API_PROCESS"}',
            425,
        )]);

        $this->usarGatewayReal();
        $this->produtoEmProjeto('KIT-1');

        $this->artisan('welog:omie:sync-kit-structure --sleep=0')
            ->expectsOutputToContain('BLOQUEOU A CONTA')
            ->assertFailed();
    }

    /**
     * A importação em lote (`ListarProdutos`) não devolve estrutura. Carimbar
     * ali registraria uma conferência que não aconteceu — e o kit ficaria sem
     * conteúdo para sempre, sem ninguém saber.
     */
    public function test_importacao_em_lote_nao_carimba_conferencia(): void
    {
        Http::fake(['*' => Http::response([
            'produto_servico_cadastro' => [['codigo' => 'LOTE-1', 'descricao' => 'Vindo da lista']],
            'total_de_paginas' => 1,
        ])]);

        (new MirrorOmieProduct(app(OmieAccounts::class), $this->gateway()))->syncAll();

        $this->assertNull(Product::where('omie_code', 'LOTE-1')->value('structure_synced_at'));
    }
}
