<?php

namespace Tests\Feature;

use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A tela Resgates lista SÓ os resgates de Projetos (`redemptions`).
 *
 * Ela já uniu os dois processos na mesma lista, mas o cliente pediu a
 * separação: as linhas importadas de parceiros (`appnoz_import_rows`, o
 * "Fluxo B" interno) têm expedição própria e são acompanhadas na visão
 * do módulo Importações Parceiros (imports.index).
 */
class RedemptionsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function resgate(string $status = Redemption::STATUS_SHIPPED): Redemption
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '99888777000166']);
        $product = Product::create(['omie_code' => 'KIT-A', 'name' => 'Kit A']);
        $project = Project::create(['company_id' => $company->id, 'name' => 'PV-1', 'quantity' => 1]);

        $pessoa = User::create([
            'name' => 'Ana Projeto', 'email' => 'ana@acme.com.br', 'password' => 'x',
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id, 'active' => true,
        ]);

        return Redemption::create([
            'project_id' => $project->id,
            'user_id' => $pessoa->id,
            'product_id' => $product->id,
            'status' => $status,
            'recipient_name' => 'Ana', 'zip' => '80010000', 'street' => 'Rua XV',
            'number' => '10', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
            'carrier' => Redemption::CARRIER_CORREIOS, 'tracking_code' => 'AA123456789BR',
        ]);
    }

    private function linhaImportada(): AppnozImportRow
    {
        $import = AppnozImport::create([
            'filename' => 'suvinil.xlsx',
            'file_hash' => 'hash-'.uniqid(),
            'source' => AppnozImport::SOURCE_DIRECTORY,
            'status' => AppnozImport::STATUS_COMPLETED,
        ]);

        return AppnozImportRow::create([
            'appnoz_import_id' => $import->id,
            'row_number' => 2,
            'cpf' => '52998224725',
            'customer_name' => 'Bruno Parceiro',
            'email' => 'bruno@suvinil.com.br',
            'product_code' => 'SUV-01',
            'product_name' => 'Kit Suvinil',
            'quantity' => 1,
            'zip' => '01310100', 'street' => 'Av Paulista', 'number' => '1000',
            'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP',
            'status' => AppnozImportRow::STATUS_DISPATCHED,
            'tracking_code' => 'FLASH-999',
        ]);
    }

    public function test_a_lista_traz_somente_resgates_de_projetos(): void
    {
        $this->resgate();
        $this->linhaImportada();

        $this->actingAs($this->operador())
            ->get(route('redemptions.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Redemptions/Index')
                ->has('redemptions.data', 1)
                ->where('redemptions.total', 1)
                ->where('redemptions.data.0.pessoa', 'Ana Projeto'),
            );
    }

    public function test_filtro_de_situacao_traduz_o_status_do_resgate(): void
    {
        $this->resgate(Redemption::STATUS_SHIPPED);

        $this->actingAs($this->operador())
            ->get(route('redemptions.index', ['situacao' => 'em_transporte']))
            ->assertInertia(fn (Assert $p) => $p->has('redemptions.data', 1));

        $this->actingAs($this->operador())
            ->get(route('redemptions.index', ['situacao' => 'entregue']))
            ->assertInertia(fn (Assert $p) => $p->has('redemptions.data', 0));
    }

    /** A busca vai ao servidor: filtrar só a página visível mentiria na página 2. */
    public function test_busca_por_pessoa_e_por_codigo_de_rastreio(): void
    {
        $this->resgate();

        $this->actingAs($this->operador())
            ->get(route('redemptions.index', ['busca' => 'AA123456789BR']))
            ->assertInertia(fn (Assert $p) => $p
                ->has('redemptions.data', 1)
                ->where('redemptions.data.0.pessoa', 'Ana Projeto'),
            );

        $this->actingAs($this->operador())
            ->get(route('redemptions.index', ['busca' => 'ninguém com esse nome']))
            ->assertInertia(fn (Assert $p) => $p->has('redemptions.data', 0));
    }

    public function test_contagens_alimentam_as_abas_e_ignoram_linhas_importadas(): void
    {
        $this->resgate(Redemption::STATUS_DELIVERED);
        $this->linhaImportada();

        $this->actingAs($this->operador())
            ->get(route('redemptions.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('contagens.entregue', 1)
                ->missing('contagens.em_transporte'),
            );
    }
}
