<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A planilha é conferida antes de qualquer cadastro.
 *
 * O que estes testes guardam é que a validação diga o MESMO que o cadastro:
 * aprovar o que o cadastro recusa ensinaria a confiar no "tudo certo". E que
 * validar não grave nada — é só uma pergunta.
 */
class ValidateEntitlementSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private Company $empresa;

    private Project $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();
        $this->empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);

        $kit = Product::create([
            'omie_code' => 'KIT-VAL', 'name' => 'Kit', 'active' => true,
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);

        $this->projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => 'Campanha',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $this->projeto->products()->attach($kit->id, ['quantity' => 10]);
    }

    private function validar(string $csv)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, $csv);

        return $this->actingAs($this->operador)->postJson(
            route('projects.entitlements.batch.validate', $this->projeto),
            ['file' => new UploadedFile($tmp, 'lista.csv', 'text/csv', null, true)],
        );
    }

    public function test_planilha_certa_fica_pronta(): void
    {
        $this->validar("nome,email,funcao,cpf,telefone\nAna,ana@sotreq.com.br,Colaborador,529.982.247-25,(11) 91234-5678\n")
            ->assertOk()
            ->assertJson(['total' => 1, 'prontas' => 1, 'com_erro' => 0, 'com_aviso' => 0, 'pode_importar' => true]);
    }

    /** Validar é só uma pergunta: ninguém é cadastrado. */
    public function test_validar_nao_cadastra_ninguem(): void
    {
        $this->validar("nome,email\nAna,ana@sotreq.com.br\n")->assertOk();

        $this->assertNull(User::where('email', 'ana@sotreq.com.br')->first());
        $this->assertSame(0, ProjectEntitlement::count());
    }

    /**
     * Coluna obrigatória ausente é erro de CABEÇALHO, uma mensagem só. Listar
     * a mesma frase em cada linha esconderia a única coisa a corrigir.
     */
    public function test_falta_de_coluna_obrigatoria_e_erro_de_cabecalho(): void
    {
        $resposta = $this->validar("nome,mail\nAna,ana@sotreq.com.br\nBia,bia@sotreq.com.br\n")->assertOk();

        $resposta->assertJson(['pode_importar' => false, 'linhas' => []]);
        $this->assertStringContainsString('"email"', $resposta->json('cabecalho.0.mensagem'));
    }

    /** "E-mail" com hífen é o jeito mais comum de escrever: entra como email. */
    public function test_email_com_hifen_no_cabecalho_e_aceito(): void
    {
        $this->validar("Nome,E-mail\nAna,ana@sotreq.com.br\n")
            ->assertOk()
            ->assertJson(['prontas' => 1, 'pode_importar' => true]);
    }

    public function test_erros_de_linha_apontam_a_linha_da_planilha(): void
    {
        $resposta = $this->validar(implode("\n", [
            'nome,email,funcao',
            'Ana,ana@sotreq.com.br,Colaborador',
            ',sem-arroba,Colaborador',
            'Caio,caio@sotreq.com.br,Gerente',
            '',
        ]))->assertOk();

        $resposta->assertJson(['total' => 3, 'prontas' => 1, 'com_erro' => 2, 'pode_importar' => true]);

        $linhas = collect($resposta->json('linhas'));
        $this->assertTrue($linhas->contains(fn ($m) => $m['linha'] === 3 && $m['tipo'] === 'erro'));
        $this->assertTrue($linhas->contains(fn ($m) => $m['linha'] === 4 && str_contains($m['mensagem'], 'Gerente')));
    }

    public function test_email_repetido_na_planilha_e_erro(): void
    {
        $resposta = $this->validar("nome,email\nAna,ana@sotreq.com.br\nAna 2,ANA@sotreq.com.br\n")->assertOk();

        $resposta->assertJson(['prontas' => 1, 'com_erro' => 1]);
        $this->assertStringContainsString('linha 2', $resposta->json('linhas.0.mensagem'));
    }

    /** O cadastro recusa Colaborador que está num Projeto de outra Empresa; a validação avisa antes. */
    public function test_email_de_colaborador_de_outra_empresa_e_erro(): void
    {
        $outra = Company::create(['name' => 'Outra', 'cnpj' => '98765432000110']);
        $pessoa = User::factory()->create([
            'email' => 'dupla@empresa.com.br',
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $outra->id,
        ]);
        $projetoDaOutra = \App\Models\Project::create(['company_id' => $outra->id, 'name' => 'Da outra']);
        $produto = \App\Models\Product::create(['omie_code' => 'OUTRO-1', 'name' => 'Outro']);
        \App\Models\ProjectEntitlement::create(['project_id' => $projetoDaOutra->id, 'user_id' => $pessoa->id, 'product_id' => $produto->id]);

        $this->validar("nome,email\nDupla,dupla@empresa.com.br\n")
            ->assertOk()
            ->assertJson(['prontas' => 0, 'com_erro' => 1, 'pode_importar' => false]);
    }

    /** Já fora de todos os Projetos da outra Empresa, o e-mail não fica preso: a linha entra. */
    public function test_colaborador_de_outra_empresa_sem_projeto_entra(): void
    {
        $outra = Company::create(['name' => 'Outra', 'cnpj' => '98765432000110']);
        User::factory()->create([
            'email' => 'livre@empresa.com.br',
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $outra->id,
        ]);

        $this->validar("nome,email\nLivre,livre@empresa.com.br\n")
            ->assertOk()
            ->assertJson(['prontas' => 1, 'com_erro' => 0, 'pode_importar' => true]);
    }

    /** CPF com dígito errado não barra: é aviso, e a linha entra. */
    public function test_cpf_invalido_e_aviso_e_nao_erro(): void
    {
        $resposta = $this->validar("nome,email,cpf\nAna,ana@sotreq.com.br,111.222.333-44\n")->assertOk();

        $resposta->assertJson(['prontas' => 1, 'com_aviso' => 1, 'com_erro' => 0, 'pode_importar' => true]);
        $this->assertSame('aviso', $resposta->json('linhas.0.tipo'));
    }

    public function test_coluna_desmarcada_vira_aviso_de_cabecalho(): void
    {
        $this->empresa->update(['customizations' => ['import_fields' => true, 'import_columns' => ['nome', 'email']]]);

        $resposta = $this->validar("nome,email,cpf\nAna,ana@sotreq.com.br,111.222.333-44\n")->assertOk();

        // O CPF nem é conferido: a coluna será ignorada.
        $resposta->assertJson(['prontas' => 1, 'com_aviso' => 0]);
        $this->assertStringContainsString('desmarcada', $resposta->json('cabecalho.0.mensagem'));
    }

    /** Com vários itens no Projeto, a planilha continua sem coluna de kit. */
    public function test_varios_itens_nao_exigem_coluna_de_kit(): void
    {
        $segundo = Product::create([
            'omie_code' => 'KIT-VAL-2', 'name' => 'Kit 2', 'active' => true,
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);
        $this->projeto->products()->attach($segundo->id, ['quantity' => 5]);

        $this->validar("nome,email\nAna,ana@sotreq.com.br\nBia,bia@sotreq.com.br\n")
            ->assertOk()
            ->assertJson(['prontas' => 2, 'com_erro' => 0, 'cabecalho' => [], 'pode_importar' => true]);
    }

    /** Planilha antiga, ainda com `codigo_kit`: a coluna é ignorada, não recusada. */
    public function test_coluna_de_kit_antiga_vira_aviso(): void
    {
        $resposta = $this->validar("nome,email,codigo_kit\nAna,ana@sotreq.com.br,QUALQUER\n")->assertOk();

        $resposta->assertJson(['prontas' => 1, 'com_erro' => 0, 'pode_importar' => true]);
        $this->assertStringContainsString('codigo_kit', $resposta->json('cabecalho.0.mensagem'));
    }

    /** A prévia pinta a célula que causou o erro, e só ela. */
    public function test_previa_marca_a_celula_com_erro_e_o_estado_das_colunas(): void
    {
        $resposta = $this->validar("nome,email,xyz\nAna,ana@sotreq.com.br,1\nBia,sem-arroba,2\n")->assertOk();

        $resposta->assertJson([
            'colunas' => [
                ['coluna' => 'nome', 'estado' => 'ok'],
                ['coluna' => 'email', 'estado' => 'ok'],
                ['coluna' => 'xyz', 'estado' => 'ignorada'],
            ],
            'previa_colunas' => ['nome', 'email'],
            'previa' => [
                ['linha' => 2, 'status' => 'ok', 'valores' => ['nome' => 'Ana', 'email' => 'ana@sotreq.com.br']],
                ['linha' => 3, 'status' => 'erro', 'celulas' => ['email' => 'erro']],
            ],
            'previa_omitidas' => 0,
        ]);

        $this->assertArrayNotHasKey('nome', $resposta->json('previa.1.celulas'));
        $this->assertNotEmpty($resposta->json('previa.1.mensagens'));
    }

    public function test_coluna_obrigatoria_ausente_aparece_como_falta(): void
    {
        $resposta = $this->validar("nome\nAna\n")->assertOk();

        $this->assertContains(['coluna' => 'email', 'estado' => 'falta'], $resposta->json('colunas'));
        // Cabeçalho errado: a linha aparece, mas sem veredito.
        $resposta->assertJsonPath('previa.0.status', null);
    }

    public function test_projeto_sem_itens_nao_importa(): void
    {
        $this->projeto->products()->detach();

        $resposta = $this->validar("nome,email\nAna,ana@sotreq.com.br\n")->assertOk();

        $resposta->assertJson(['prontas' => 0, 'pode_importar' => false]);
        $this->assertStringContainsString('não tem itens', $resposta->json('cabecalho.0.mensagem'));
    }

    /** O que a validação aprova, o cadastro cadastra. */
    public function test_o_que_passou_na_validacao_e_cadastrado(): void
    {
        $csv = "nome,email\nAna,ana@sotreq.com.br\nBia,bia@sotreq.com.br\n";

        $this->validar($csv)->assertJson(['prontas' => 2]);

        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, $csv);

        $this->actingAs($this->operador)
            ->post(route('projects.entitlements.batch', $this->projeto), [
                'file' => new UploadedFile($tmp, 'lista.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ProjectEntitlement::count());
    }

    /** A prévia é para reconhecer o arquivo: só as primeiras linhas. */
    public function test_previa_mostra_as_cinco_primeiras_linhas(): void
    {
        $linhas = array_map(fn ($i) => "P{$i},p{$i}@sotreq.com.br", range(1, 8));

        $resposta = $this->validar("nome,email\n".implode("\n", $linhas)."\n")->assertOk();

        $resposta->assertJson(['total' => 8, 'prontas' => 8, 'previa_omitidas' => 3]);
        $this->assertSame([2, 3, 4, 5, 6], array_column($resposta->json('previa'), 'linha'));
    }

    /** O telefone aparece na prévia como vai ser gravado. */
    public function test_previa_mostra_o_telefone_normalizado(): void
    {
        $this->validar("nome,email,telefone\nAna,ana@sotreq.com.br,(41) 99999-0000\n")
            ->assertOk()
            ->assertJson(['com_aviso' => 0])
            ->assertJsonPath('previa.0.valores.telefone', '+5541999990000');
    }

    public function test_telefone_sem_ddd_e_aviso(): void
    {
        $resposta = $this->validar("nome,email,telefone\nAna,ana@sotreq.com.br,99999-0000\n")->assertOk();

        $resposta->assertJson(['prontas' => 1, 'com_aviso' => 1, 'pode_importar' => true]);
        $resposta->assertJsonPath('previa.0.celulas.telefone', 'aviso');
    }

    /** O cadastro grava o telefone sempre como +55DDDNÚMERO. */
    public function test_importacao_grava_o_telefone_com_mais_55(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, "nome,email,telefone\nAna,ana@sotreq.com.br,55 (41) 99999-0000\nBia,bia@sotreq.com.br,99999-0000\n");

        $this->actingAs($this->operador)
            ->post(route('projects.entitlements.batch', $this->projeto), [
                'file' => new UploadedFile($tmp, 'lista.csv', 'text/csv', null, true),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('+5541999990000', User::where('email', 'ana@sotreq.com.br')->value('phone'));
        // Sem DDD não dá para normalizar: a pessoa entra, sem telefone.
        $this->assertNull(User::where('email', 'bia@sotreq.com.br')->value('phone'));
    }

    public function test_arquivo_vazio_e_erro_de_cabecalho(): void
    {
        $this->validar("nome,email\n")
            ->assertOk()
            ->assertJson(['pode_importar' => false]);
    }
}
