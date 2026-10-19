<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_login_e_redirecionamento_por_perfil(): void
    {
        $this->post('/login', ['email' => 'admin@welog.local', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        auth()->logout();

        $endCustomer = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'password' => 'secret-123',
        ]);

        $this->post('/login', ['email' => $endCustomer->email, 'password' => 'secret-123'])
            ->assertRedirect(route('portal.catalog'));
    }

    public function test_usuario_inativo_nao_loga(): void
    {
        User::factory()->create([
            'email' => 'inativo@welog.local',
            'password' => 'secret-123',
            'active' => false,
        ]);

        $this->post('/login', ['email' => 'inativo@welog.local', 'password' => 'secret-123'])
            ->assertSessionHasErrors('email');
    }

    public function test_cliente_final_nao_acessa_area_operacional(): void
    {
        $endCustomer = User::factory()->create(['role' => User::ROLE_END_CUSTOMER]);

        $this->actingAs($endCustomer)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($endCustomer)->get(route('projects.index'))->assertForbidden();
        $this->actingAs($endCustomer)->get(route('imports.index'))->assertForbidden();
    }

    public function test_operador_nao_acessa_portal_do_cliente(): void
    {
        $operator = User::where('email', 'operador@welog.local')->first();

        $this->actingAs($operator)->get(route('portal.catalog'))->assertForbidden();
    }

    public function test_n2_consulta_mas_nao_reprocessa(): void
    {
        $n2 = User::where('email', 'consulta@welog.local')->first();

        $import = \App\Models\AppnozImport::create([
            'filename' => 'SUV-1', 'file_hash' => 'hash-n2', 'source' => 'webhook',
        ]);
        $row = $import->rows()->create([
            'row_number' => 1, 'cpf' => '52998224725', 'customer_name' => 'Ana',
            'product_code' => 'KIT-X', 'quantity' => 1, 'status' => 'blocked_product',
        ]);

        $this->actingAs($n2)->get(route('dashboard'))->assertOk();
        $this->actingAs($n2)->get(route('imports.index'))->assertOk();
        $this->actingAs($n2)->get(route('imports.rows.show', $row))->assertOk();
        $this->actingAs($n2)->post(route('imports.rows.reprocess', $row))->assertForbidden();
    }

    public function test_benefits_apenas_admin(): void
    {
        $n1 = User::where('email', 'operador@welog.local')->first();
        $admin = User::where('email', 'admin@welog.local')->first();

        $this->actingAs($n1)->get(route('benefits.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('benefits.index'))->assertOk();
    }

    public function test_visitante_e_redirecionado_ao_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect('/login');
    }
}
