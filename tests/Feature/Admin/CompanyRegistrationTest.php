<?php

namespace Tests\Feature\Admin;

use App\Integrations\Omie\OmieApiGateway;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\FluxoA\CreateProjectFromOmieOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Cadastro de empresa cliente com contato e endereço (módulo 10).
 *
 * A empresa nasce de duas formas: à mão pelo Admin WeGift, ou automaticamente
 * pelo webhook de pedido da Omie. A segunda nasce incompleta, então o que
 * importa é (a) dar para completar depois e (b) a Omie preencher o que puder
 * sem apagar correção feita à mão.
 */
class CompanyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Suvinil Tintas S/A',
            'trade_name' => 'Suvinil',
            'cnpj' => '12.345.678/0001-90',
            'contact_name' => 'Marina Souza',
            'contact_email' => 'MARINA@suvinil.com.br',
            'contact_phone' => '(11) 3000-1000',
            'zip' => '01310-100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'complement' => 'Andar 12',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'sp',
            'notes' => 'Cliente do programa Pintou Parceria.',
        ], $override);
    }

    public function test_cadastra_empresa_com_contato_e_endereco(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.companies.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $company = Company::where('name', 'Suvinil Tintas S/A')->firstOrFail();

        // CNPJ, CEP, UF e e-mail normalizados na entrada
        $this->assertSame('12345678000190', $company->cnpj);
        $this->assertSame('01310100', $company->zip);
        $this->assertSame('SP', $company->state);
        $this->assertSame('marina@suvinil.com.br', $company->contact_email);

        $this->assertSame('Suvinil', $company->trade_name);
        $this->assertSame('Avenida Paulista', $company->street);
        $this->assertSame('São Paulo', $company->city);
    }

    public function test_endereco_completo_e_montado_para_exibicao(): void
    {
        $this->actingAs($this->admin())->post(route('admin.companies.store'), $this->payload());

        $endereco = Company::firstOrFail()->full_address;

        $this->assertStringContainsString('Avenida Paulista, 1000', $endereco);
        $this->assertStringContainsString('São Paulo/SP', $endereco);
        $this->assertStringContainsString('CEP 01310-100', $endereco);
    }

    public function test_empresa_pode_ser_editada(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '99888777000166']);

        $this->actingAs($this->admin())
            ->put(route('admin.companies.update', $company), $this->payload([
                'cnpj' => '99888777000166',
                'name' => 'ACME Tecnologia LTDA',
            ]))
            ->assertSessionHasNoErrors();

        $company->refresh();
        $this->assertSame('ACME Tecnologia LTDA', $company->name);
        $this->assertSame('Avenida Paulista', $company->street);
    }

    /**
     * Company Name (UAT): o nome mostrado nos e-mails ao Usuário é editável
     * pela WeGift, separado da razão social que a Omie sobrescreve.
     */
    public function test_nome_de_exibicao_e_editavel_e_exposto_para_a_tela(): void
    {
        $company = Company::create(['name' => 'ACME Comercio LTDA', 'cnpj' => '99888777000166']);

        $this->actingAs($this->admin())
            ->put(route('admin.companies.update', $company), $this->payload([
                'cnpj' => '99888777000166',
                'display_name' => '  Loja do Sorriso  ',
            ]))
            ->assertSessionHasNoErrors();

        $company->refresh();
        $this->assertSame('Loja do Sorriso', $company->display_name);
        $this->assertSame('Loja do Sorriso', $company->label);

        $this->actingAs($this->admin())
            ->get(route('admin.companies.show', $company))
            ->assertInertia(fn (Assert $p) => $p
                ->where('company.display_name', 'Loja do Sorriso')
                ->where('company.label', 'Loja do Sorriso'));
    }

    public function test_nome_de_exibicao_vazio_cai_na_razao_social(): void
    {
        $company = Company::create([
            'name' => 'ACME Comercio LTDA', 'cnpj' => '99888777000166',
            'display_name' => 'Loja do Sorriso',
        ]);

        // Enviar o campo em branco LIMPA o nome de exibição…
        $this->actingAs($this->admin())
            ->put(route('admin.companies.update', $company), $this->payload([
                'name' => 'ACME Comercio LTDA', 'cnpj' => '99888777000166', 'display_name' => '',
            ]))
            ->assertSessionHasNoErrors();

        $company->refresh();
        $this->assertNull($company->display_name);
        // …e o label degrada para a razão social.
        $this->assertSame('ACME Comercio LTDA', $company->label);
    }

    public function test_edicao_sem_o_campo_nao_apaga_o_nome_de_exibicao(): void
    {
        // Formulário que ainda não tem o input (onda 3 em andamento) não pode
        // apagar o que já foi definido: só o campo PRESENTE no envio conta.
        $company = Company::create([
            'name' => 'ACME Comercio LTDA', 'cnpj' => '99888777000166',
            'display_name' => 'Loja do Sorriso',
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.companies.update', $company), $this->payload(['cnpj' => '99888777000166']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Loja do Sorriso', $company->fresh()->display_name);
    }

    public function test_edicao_nao_aceita_cnpj_de_outra_empresa(): void
    {
        Company::create(['name' => 'Primeira', 'cnpj' => '11111111000111']);
        $segunda = Company::create(['name' => 'Segunda', 'cnpj' => '22222222000122']);

        $this->actingAs($this->admin())
            ->put(route('admin.companies.update', $segunda), $this->payload(['cnpj' => '11111111000111']))
            ->assertSessionHasErrors('cnpj');

        $this->assertSame('22222222000122', $segunda->fresh()->cnpj);
    }

    public function test_campos_faltantes_sao_reportados(): void
    {
        // Como nasce uma empresa criada pelo webhook da Omie
        $company = Company::create(['name' => 'Cliente novo', 'cnpj' => '33333333000133']);

        $faltando = $company->missingFields();

        $this->assertContains('CEP', $faltando);
        $this->assertContains('cidade', $faltando);
        $this->assertContains('e-mail de contato', $faltando);
        $this->assertNull($company->full_address);
    }

    public function test_cadastro_completo_nao_reporta_faltas(): void
    {
        $this->actingAs($this->admin())->post(route('admin.companies.store'), $this->payload());

        $this->assertSame([], Company::firstOrFail()->missingFields());
    }

    public function test_somente_admin_wegift_gerencia_empresas(): void
    {
        $n1 = User::where('email', 'operador@welog.local')->firstOrFail();
        $company = Company::create(['name' => 'ACME', 'cnpj' => '99888777000166']);

        $this->actingAs($n1)->post(route('admin.companies.store'), $this->payload())->assertForbidden();
        $this->actingAs($n1)->put(route('admin.companies.update', $company), $this->payload())->assertForbidden();
        $this->actingAs($n1)->get(route('admin.companies.show', $company))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Página da Empresa
    // ------------------------------------------------------------------

    public function test_pagina_da_empresa_reune_admin_usuarios_projetos_e_resgates(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '99888777000166']);

        $admin = User::create([
            'name' => 'Ana Admin', 'email' => 'ana@acme.com.br', 'password' => 'x',
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $company->id, 'active' => true,
            'password_set_at' => null,
        ]);

        User::create([
            'name' => 'Bruno Usuário', 'email' => 'bruno@acme.com.br', 'password' => 'x',
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id, 'active' => true,
        ]);

        Project::create([
            'company_id' => $company->id,
            'name' => 'PV-1 ACME',
            'quantity' => 10,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.companies.show', $company))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Admin/Companies/Show')
                ->where('company.name', 'ACME')
                ->has('company.admins', 1)
                ->where('company.admins.0.name', $admin->name)
                ->where('company.admins.0.access_pending', true)
                ->has('end_customers', 1)
                ->where('end_customers.0.name', 'Bruno Usuário')
                ->has('projects', 1)
                ->where('projects.0.end_customers_count', 0)
                ->has('redemptions', 0),
            );
    }

    /**
     * Sem Admin Cliente a Empresa está parada — a tela precisa do dado para
     * gritar isso, e não apenas mostrar uma lista vazia.
     */
    public function test_empresa_sem_admin_cliente_chega_com_a_lista_vazia(): void
    {
        $company = Company::create(['name' => 'Sozinha', 'cnpj' => '55555555000155']);

        $this->actingAs($this->admin())
            ->get(route('admin.companies.show', $company))
            ->assertInertia(fn (Assert $p) => $p->has('company.admins', 0));
    }

    /** `modelo` é rota de download, não id de empresa — a curinga não pode engolir. */
    public function test_rota_do_modelo_nao_e_confundida_com_uma_empresa(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.companies.template'))
            ->assertDownload('modelo-empresas.xlsx');
    }

    // ------------------------------------------------------------------
    // Preenchimento automático a partir da Omie
    // ------------------------------------------------------------------

    public function test_omie_preenche_contato_e_endereco_da_empresa(): void
    {
        Http::fake([
            '*produtos/pedido*' => Http::response([
                'pedido_venda_produto' => [
                    'cabecalho' => ['numero_pedido' => 'PV-1', 'codigo_cliente' => 7788],
                    'det' => [],
                ],
            ]),
            '*geral/clientes*' => Http::response([
                'codigo_cliente_omie' => 7788,
                'cnpj_cpf' => '12.345.678/0001-90',
                'razao_social' => 'Suvinil Tintas S/A',
                'nome_fantasia' => 'Suvinil',
                'email' => 'contato@suvinil.com.br',
                'telefone1_ddd' => '11',
                'telefone1_numero' => '30001000',
                'cep' => '01310-100',
                'endereco' => 'Avenida Paulista',
                'endereco_numero' => '1000',
                'bairro' => 'Bela Vista',
                'cidade' => 'São Paulo',
                'estado' => 'SP',
            ]),
        ]);

        $project = app(CreateProjectFromOmieOrder::class, [
            'omie' => new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's'),
        ])->handle('900001');

        $company = $project->company;

        $this->assertSame('Suvinil', $company->trade_name);
        $this->assertSame('contato@suvinil.com.br', $company->contact_email);
        $this->assertSame('11 30001000', $company->contact_phone);
        $this->assertSame('01310100', $company->zip);
        $this->assertSame('São Paulo', $company->city);
        $this->assertSame('SP', $company->state);
        $this->assertSame([], $company->missingFields());
    }

    public function test_reimportacao_nao_apaga_correcao_feita_a_mao(): void
    {
        $company = Company::create([
            'name' => 'Suvinil Tintas S/A',
            'cnpj' => '12345678000190',
            'city' => 'Curitiba',            // corrigido pelo operador
            'contact_email' => 'novo@suvinil.com.br',
        ]);

        Http::fake([
            '*produtos/pedido*' => Http::response([
                'pedido_venda_produto' => [
                    'cabecalho' => ['numero_pedido' => 'PV-2', 'codigo_cliente' => 7788],
                    'det' => [],
                ],
            ]),
            '*geral/clientes*' => Http::response([
                'codigo_cliente_omie' => 7788,
                'cnpj_cpf' => '12345678000190',
                'razao_social' => 'Suvinil Tintas S/A',
                'cidade' => 'São Paulo',                    // valor antigo na Omie
                'email' => 'antigo@suvinil.com.br',
                'endereco' => 'Avenida Paulista',           // este ainda está vazio aqui
            ]),
        ]);

        app(CreateProjectFromOmieOrder::class, [
            'omie' => new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's'),
        ])->handle('900002');

        $company->refresh();

        // O que o operador corrigiu permanece…
        $this->assertSame('Curitiba', $company->city);
        $this->assertSame('novo@suvinil.com.br', $company->contact_email);
        // …e o que estava vazio é preenchido
        $this->assertSame('Avenida Paulista', $company->street);
    }

    public function test_sincronizacao_da_omie_nao_apaga_o_nome_de_exibicao(): void
    {
        // `name` é espelho da razão social e a Omie PODE sobrescrevê-lo;
        // `display_name` é da WeGift e a sincronização nunca o toca (UAT).
        $company = Company::create([
            'name' => 'Nome antigo',
            'cnpj' => '12345678000190',
            'display_name' => 'Suvinil',
        ]);

        Http::fake([
            '*produtos/pedido*' => Http::response([
                'pedido_venda_produto' => [
                    'cabecalho' => ['numero_pedido' => 'PV-3', 'codigo_cliente' => 7788],
                    'det' => [],
                ],
            ]),
            '*geral/clientes*' => Http::response([
                'codigo_cliente_omie' => 7788,
                'cnpj_cpf' => '12345678000190',
                'razao_social' => 'Suvinil Tintas Industriais S/A',
            ]),
        ]);

        app(CreateProjectFromOmieOrder::class, [
            'omie' => new OmieApiGateway('https://app.omie.com.br/api/v1', 'k', 's'),
        ])->handle('900003');

        $company->refresh();

        // A razão social acompanha a Omie…
        $this->assertSame('Suvinil Tintas Industriais S/A', $company->name);
        // …o nome de exibição fica, e continua sendo o que o Usuário lê.
        $this->assertSame('Suvinil', $company->display_name);
        $this->assertSame('Suvinil', $company->label);
    }
}
