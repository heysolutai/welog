<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Autocomplete de empresa no cadastro manual de Projeto (nome ou CNPJ). */
class CompanyLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Company::create(['name' => 'ACME Brindes', 'cnpj' => '12345678000190']);
        Company::create(['name' => 'Beta Corporativo', 'cnpj' => '98765432000110']);
        Company::create(['name' => 'Gama Ltda', 'cnpj' => '11222333000144']);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->first();
    }

    public function test_busca_por_trecho_do_nome(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('companies.lookup', ['q' => 'aCm']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'ACME Brindes')
            ->assertJsonPath('0.cnpj', '12345678000190');
    }

    public function test_busca_por_trecho_do_cnpj_com_ou_sem_mascara(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('companies.lookup', ['q' => '98.765']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Beta Corporativo');

        $this->actingAs($this->admin())
            ->getJson(route('companies.lookup', ['q' => '11222']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Gama Ltda');
    }

    public function test_termo_curto_devolve_vazio(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('companies.lookup', ['q' => 'a']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_visitante_e_n2_nao_acessam(): void
    {
        $this->get(route('companies.lookup', ['q' => 'acme']))->assertRedirect(route('login'));

        $n2 = User::factory()->create(['role' => User::ROLE_WEGIFT_N2]);
        $this->actingAs($n2)->getJson(route('companies.lookup', ['q' => 'acme']))->assertForbidden();
    }
}
