<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Customizações do Cliente — as chaves liga/desliga do modal.
 *
 * O que estes testes protegem: o JSON só aceita chave CONHECIDA (senão o banco
 * junta lixo que nenhuma tela lê), a tela sempre recebe o conjunto completo de
 * features (senão uma criada hoje some do modal em Cliente antigo) e alternar
 * uma não mexe nas outras.
 */
class CompanyCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    private function url(): string
    {
        return route('admin.companies.customizations.update', $this->company);
    }

    public function test_admin_liga_e_desliga_uma_customizacao(): void
    {
        $this->actingAs($this->admin())
            ->patch($this->url(), ['key' => 'project_logo', 'enabled' => true])
            ->assertRedirect();

        $this->assertTrue($this->company->refresh()->customizacao('project_logo'));

        $this->actingAs($this->admin())
            ->patch($this->url(), ['key' => 'project_logo', 'enabled' => false]);

        $this->assertFalse($this->company->refresh()->customizacao('project_logo'));
    }

    /** Sem isto, qualquer nome viraria uma chave órfã no JSON. */
    public function test_recusa_chave_desconhecida(): void
    {
        $this->actingAs($this->admin())
            ->patch($this->url(), ['key' => 'feature_inventada', 'enabled' => true])
            ->assertSessionHasErrors('key');

        $this->assertNull($this->company->refresh()->customizations);
    }

    /**
     * Uma chave por chamada, e as demais intactas.
     *
     * É o que permite dois operadores mexerem em features diferentes sem um
     * sobrescrever o ajuste do outro.
     */
    public function test_alternar_uma_chave_preserva_as_outras(): void
    {
        $this->company->update(['customizations' => ['outra_coisa' => true]]);

        $this->actingAs($this->admin())
            ->patch($this->url(), ['key' => 'project_logo', 'enabled' => true]);

        $gravado = $this->company->refresh()->customizations;

        $this->assertTrue($gravado['project_logo']);
        $this->assertTrue($gravado['outra_coisa']);
    }

    /** Feature nasce desligada: criar uma não pode ligá-la em todo Cliente que já existe. */
    public function test_customizacao_nunca_tocada_vem_desligada(): void
    {
        $this->assertFalse($this->company->customizacao('project_logo'));
    }

    /**
     * A tela recebe TODAS as chaves conhecidas, não só as gravadas — senão a
     * linha de uma feature nova não apareceria no modal de um Cliente antigo.
     */
    public function test_tela_do_cliente_traz_o_catalogo_e_o_estado_de_cada_feature(): void
    {
        $this->actingAs($this->admin())->get(route('admin.companies.show', $this->company))
            ->assertInertia(fn (Assert $p) => $p
                ->where('company.customizations.project_logo', false)
                ->where('customization_catalog.0.key', 'project_logo')
                ->has('customization_catalog.0.label')
                ->has('customization_catalog.0.description')
                ->etc());
    }

    /** Parametrizar é da WeGift: o Admin Cliente não mexe na própria tela. */
    public function test_admin_cliente_nao_altera_customizacao(): void
    {
        $adminCliente = User::create([
            'name' => 'RH ACME',
            'email' => 'rh@acme.test',
            'password' => 'password',
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($adminCliente)
            ->patch($this->url(), ['key' => 'project_logo', 'enabled' => true])
            ->assertForbidden();

        $this->assertFalse($this->company->refresh()->customizacao('project_logo'));
    }
}
