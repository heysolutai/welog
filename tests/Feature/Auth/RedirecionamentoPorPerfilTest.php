<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quem já está autenticado e abre uma rota de visitante tem de ir para a
 * própria área.
 *
 * Sem `redirectUsersTo`, o padrão do Laravel é '/dashboard' — que exige perfil
 * de operador. O Admin Cliente reabria o link do convite, caía em 403, e o
 * único botão da página o devolvia ao mesmo 403. Aconteceu em produção.
 */
class RedirecionamentoPorPerfilTest extends TestCase
{
    use RefreshDatabase;

    private function clientAdmin(): User
    {
        $company = Company::create(['name' => 'ACME Ltda', 'cnpj' => '12345678000190']);

        return User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $company->id,
            'password_set_at' => now(),
        ]);
    }

    public function test_admin_cliente_logado_abrindo_login_vai_para_projetos(): void
    {
        $this->actingAs($this->clientAdmin())
            ->get(route('login'))
            ->assertRedirect(route('projects.index'));
    }

    public function test_admin_cliente_logado_na_raiz_nao_cai_em_403(): void
    {
        // '/' redireciona para '/login'; era por aqui que a pessoa se perdia
        // ao simplesmente digitar o domínio. Seguindo os redirects até o fim,
        // tem de chegar numa página que ele PODE ver — antes dava 403.
        $this->actingAs($this->clientAdmin())
            ->followingRedirects()
            ->get('/')
            ->assertOk();
    }

    public function test_cliente_final_logado_vai_para_o_portal(): void
    {
        $customer = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'password_set_at' => now(),
        ]);

        $this->actingAs($customer)
            ->get(route('login'))
            ->assertRedirect(route('portal.catalog'));
    }

    public function test_operador_logado_vai_para_o_painel(): void
    {
        $operador = User::factory()->create([
            'role' => User::ROLE_WEGIFT_N1,
            'password_set_at' => now(),
        ]);

        $this->actingAs($operador)
            ->get(route('login'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_visitante_continua_vendo_o_login(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
