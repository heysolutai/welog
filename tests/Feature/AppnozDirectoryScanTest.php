<?php

namespace Tests\Feature;

use App\Models\AppnozImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cron de leitura (S5): varredura diária dos diretórios ATIVOS parametrizados.
 *
 * O Anexo I fala em "diretório específico previamente definido" — e previamente
 * definido é acordo entre pessoas, não constante de código. Uma linha de
 * produto nova ou um caminho renomeado não podem exigir deploy.
 */
class AppnozDirectoryScanTest extends TestCase
{
    use RefreshDatabase;

    private const CABECALHO = 'cpf,nome,email,telefone,codigo_produto,produto,quantidade,cep,endereco,numero,complemento,bairro,cidade,uf';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed();
    }

    /**
     * O nome entra no conteúdo de propósito: o importador deduplica por hash
     * SHA-256 do arquivo, então dois relatórios idênticos em pastas diferentes
     * contariam como um só — e o teste passaria a medir o dedupe, não a
     * varredura.
     */
    private function relatorioEm(string $diretorio, string $nome): void
    {
        Storage::disk('local')->put("{$diretorio}/{$nome}", self::CABECALHO."\n"
            ."52998224725,Maria {$nome},maria@x.com,41999990000,SUV-PINCEL-01,Pincel,1,80010000,Rua XV,10,,Centro,Curitiba,PR\n");
    }

    public function test_varre_todos_os_diretorios_parametrizados(): void
    {
        config(['integrations.appnoz.inbox_directories' => ['inbox-a', 'inbox-b']]);

        $this->relatorioEm('inbox-a', 'lote-a.csv');
        $this->relatorioEm('inbox-b', 'lote-b.csv');

        $this->artisan('welog:consume-appnoz-directory')->assertSuccessful();

        $this->assertSame(2, AppnozImport::count(), 'um lote por arquivo, em pastas diferentes');
    }

    /** Arquivo lido sai do caminho, senão a varredura de amanhã o relê. */
    public function test_arquivo_processado_vai_para_o_historico(): void
    {
        config(['integrations.appnoz.inbox_directories' => ['inbox-a']]);

        $this->relatorioEm('inbox-a', 'lote.csv');
        $this->artisan('welog:consume-appnoz-directory')->assertSuccessful();

        $this->assertEmpty(Storage::disk('local')->files('inbox-a'));
        $this->assertNotEmpty(Storage::disk('local')->files('appnoz-processed'));
    }

    /** Sem parametrização não há o que varrer — e isso é aviso, não falha. */
    public function test_sem_diretorio_ativo_avisa_e_nao_quebra(): void
    {
        config(['integrations.appnoz.inbox_directories' => []]);

        $this->artisan('welog:consume-appnoz-directory')
            ->expectsOutputToContain('Nenhum diretório ativo')
            ->assertSuccessful();
    }

    /** `--dir` serve para reprocessar uma pasta sem mexer no ambiente. */
    public function test_opcao_dir_sobrepoe_a_parametrizacao(): void
    {
        config(['integrations.appnoz.inbox_directories' => ['inbox-a']]);

        $this->relatorioEm('inbox-a', 'nao-deve-entrar.csv');
        $this->relatorioEm('avulso', 'deve-entrar.csv');

        $this->artisan('welog:consume-appnoz-directory --dir=avulso')->assertSuccessful();

        $this->assertSame(1, AppnozImport::count());
        $this->assertSame('deve-entrar.csv', AppnozImport::first()->filename);
        $this->assertNotEmpty(Storage::disk('local')->files('inbox-a'), 'a pasta parametrizada ficou intacta');
    }

    /** Uma pasta ilegível não pode impedir a leitura das outras. */
    public function test_arquivo_recusado_nao_interrompe_a_varredura(): void
    {
        config(['integrations.appnoz.inbox_directories' => ['inbox-a', 'inbox-b']]);

        Storage::disk('local')->put('inbox-a/vazio.csv', '');
        $this->relatorioEm('inbox-b', 'bom.csv');

        $this->artisan('welog:consume-appnoz-directory')->assertSuccessful();

        $this->assertSame(1, AppnozImport::where('filename', 'bom.csv')->count());
        $this->assertEmpty(Storage::disk('local')->files('inbox-a'), 'o recusado também sai do caminho');
    }
}
