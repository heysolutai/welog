<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Cadastro de empresas cliente em lote por planilha (módulo 10).
 *
 * O caso que importa não é criar do zero: é completar as empresas que o
 * webhook da Omie já criou com razão social e CNPJ e mais nada.
 */
class CompanyBatchImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'razao_social,nome_fantasia,cnpj,codigo_omie,nome_contato,email_contato,telefone,cep,logradouro,numero,complemento,bairro,cidade,uf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    /** O upload real chega em caminho temporário sem extensão — mesmo cenário do lote de clientes finais. */
    private function planilha(string $csv): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpupload-');
        file_put_contents($tmp, $csv);

        return new UploadedFile($tmp, 'empresas.csv', 'text/csv', null, true);
    }

    private function importar(string $csv)
    {
        return $this->actingAs($this->admin())
            ->post(route('admin.companies.batch'), ['file' => $this->planilha($csv)]);
    }

    public function test_cria_empresa_que_ainda_nao_existe(): void
    {
        $this->importar(self::HEADER."\n"
            .'Suvinil Tintas S/A,Suvinil,12.345.678/0001-90,,Marina Souza,MARINA@suvinil.com.br,(11) 3000-1000,01310-100,Avenida Paulista,1000,Andar 12,Bela Vista,São Paulo,sp'."\n")
            ->assertSessionHasNoErrors();

        $company = Company::where('cnpj', '12345678000190')->firstOrFail();

        $this->assertSame('Suvinil Tintas S/A', $company->name);
        $this->assertSame('Suvinil', $company->trade_name);
        // Normalizados na entrada, como no cadastro manual
        $this->assertSame('01310100', $company->zip);
        $this->assertSame('SP', $company->state);
        $this->assertSame('marina@suvinil.com.br', $company->contact_email);
        $this->assertSame([], $company->missingFields());
    }

    public function test_completa_empresa_que_nasceu_do_webhook_da_omie(): void
    {
        $company = Company::create(['name' => 'Cliente novo', 'cnpj' => '33333333000133']);

        $this->importar(self::HEADER."\n"
            .'Cliente Novo LTDA,,33333333000133,,Ana,ana@cliente.com.br,4133330000,80010000,Rua XV,10,,Centro,Curitiba,PR'."\n")
            ->assertSessionHasNoErrors();

        $company->refresh();

        $this->assertSame('Cliente Novo LTDA', $company->name);
        $this->assertSame('Curitiba', $company->city);
        $this->assertSame([], $company->missingFields());
        $this->assertSame(1, Company::where('cnpj', '33333333000133')->count());
    }

    /** Quem monta a planilha traz as colunas que tem em mãos — o resto vem vazio por falta de dado. */
    public function test_coluna_vazia_nao_apaga_o_que_ja_esta_gravado(): void
    {
        $company = Company::create([
            'name' => 'ACME',
            'cnpj' => '99888777000166',
            'city' => 'Curitiba',
            'contact_email' => 'contato@acme.com.br',
        ]);

        $this->importar(self::HEADER."\n"
            .'ACME Tecnologia LTDA,,99888777000166,,,,,,Rua das Flores,55,,,,'."\n")
            ->assertSessionHasNoErrors();

        $company->refresh();

        $this->assertSame('Curitiba', $company->city);
        $this->assertSame('contato@acme.com.br', $company->contact_email);
        $this->assertSame('Rua das Flores', $company->street);
        $this->assertSame('ACME Tecnologia LTDA', $company->name);
    }

    public function test_linha_invalida_e_reportada_sem_derrubar_as_demais(): void
    {
        $response = $this->importar(self::HEADER."\n"
            .'Boa Empresa LTDA,,11111111000111,,,,,,,,,,,'."\n"
            .'Sem CNPJ,,,,,,,,,,,,,'."\n"
            .'CNPJ Curto,,123,,,,,,,,,,,'."\n"
            .'UF Errada,,22222222000122,,,,,,,,,,,parana'."\n");

        $response->assertSessionHasErrors('batch');

        $erros = session('errors')->first('batch');
        $this->assertStringContainsString('Linha 3', $erros);
        $this->assertStringContainsString('Linha 4', $erros);
        $this->assertStringContainsString('14 dígitos', $erros);
        $this->assertStringContainsString('Linha 5', $erros);

        $this->assertDatabaseHas('companies', ['cnpj' => '11111111000111']);
        $this->assertDatabaseMissing('companies', ['cnpj' => '22222222000122']);
    }

    /** Dois lançamentos do mesmo CNPJ: o segundo sobrescreveria o primeiro em silêncio. */
    public function test_cnpj_repetido_na_mesma_planilha_e_recusado(): void
    {
        $response = $this->importar(self::HEADER."\n"
            .'Primeira Razao,,44444444000144,,,,,,,,,,São Paulo,SP'."\n"
            .'Segunda Razao,,44444444000144,,,,,,,,,,Curitiba,PR'."\n");

        $response->assertSessionHasErrors('batch');
        $this->assertStringContainsString('já aparece na linha 2', session('errors')->first('batch'));

        $company = Company::where('cnpj', '44444444000144')->firstOrFail();
        $this->assertSame('Primeira Razao', $company->name);
        $this->assertSame('São Paulo', $company->city);
    }

    public function test_planilha_vazia_vira_mensagem_e_nao_erro_500(): void
    {
        $response = $this->importar(self::HEADER."\n");

        $response->assertSessionHasErrors('batch');
        $this->assertStringContainsString('vazia', session('errors')->first('batch'));
    }

    public function test_baixa_o_modelo_xlsx(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.companies.template'))
            ->assertOk()
            ->assertDownload('modelo-empresas.xlsx');
    }

    public function test_somente_admin_wegift_importa_empresas(): void
    {
        $n1 = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->actingAs($n1)
            ->post(route('admin.companies.batch'), ['file' => $this->planilha(self::HEADER."\n")])
            ->assertForbidden();

        $this->actingAs($n1)->get(route('admin.companies.template'))->assertForbidden();
    }
}
