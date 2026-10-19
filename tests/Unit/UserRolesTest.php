<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

/**
 * Papéis e permissões (Módulo 10) — lógica pura sobre o campo `role`, sem
 * banco. Garante que a fronteira crítico / consulta / cliente não afrouxe.
 */
class UserRolesTest extends TestCase
{
    private function userWithRole(string $role): User
    {
        $user = new User;
        $user->role = $role;

        return $user;
    }

    public function test_operadores_wegift_sao_operadores(): void
    {
        foreach ([User::ROLE_ADMIN_WEGIFT, User::ROLE_WEGIFT_N1, User::ROLE_WEGIFT_N2] as $role) {
            $this->assertTrue($this->userWithRole($role)->isOperator(), "{$role} deveria ser operador");
        }

        $this->assertFalse($this->userWithRole(User::ROLE_ADMIN_CLIENT)->isOperator());
        $this->assertFalse($this->userWithRole(User::ROLE_END_CUSTOMER)->isOperator());
    }

    public function test_apenas_admin_e_n1_executam_acoes_criticas(): void
    {
        $this->assertTrue($this->userWithRole(User::ROLE_ADMIN_WEGIFT)->canPerformCriticalActions());
        $this->assertTrue($this->userWithRole(User::ROLE_WEGIFT_N1)->canPerformCriticalActions());

        // N2 é consulta: não libera projeto nem importa
        $this->assertFalse($this->userWithRole(User::ROLE_WEGIFT_N2)->canPerformCriticalActions());
        $this->assertFalse($this->userWithRole(User::ROLE_ADMIN_CLIENT)->canPerformCriticalActions());
        $this->assertFalse($this->userWithRole(User::ROLE_END_CUSTOMER)->canPerformCriticalActions());
    }

    public function test_reconhece_admin_cliente_e_cliente_final(): void
    {
        $this->assertTrue($this->userWithRole(User::ROLE_ADMIN_CLIENT)->isClientAdmin());
        $this->assertFalse($this->userWithRole(User::ROLE_ADMIN_WEGIFT)->isClientAdmin());

        $this->assertTrue($this->userWithRole(User::ROLE_END_CUSTOMER)->isEndCustomer());
        $this->assertFalse($this->userWithRole(User::ROLE_ADMIN_CLIENT)->isEndCustomer());
    }

    public function test_destino_pos_login_por_perfil(): void
    {
        $this->assertSame('dashboard', $this->userWithRole(User::ROLE_ADMIN_WEGIFT)->homeRouteName());
        $this->assertSame('dashboard', $this->userWithRole(User::ROLE_WEGIFT_N2)->homeRouteName());
        $this->assertSame('projects.index', $this->userWithRole(User::ROLE_ADMIN_CLIENT)->homeRouteName());
        $this->assertSame('portal.catalog', $this->userWithRole(User::ROLE_END_CUSTOMER)->homeRouteName());
    }

    public function test_login_por_senha_bloqueado_ate_definir_senha(): void
    {
        $user = new User;
        $this->assertFalse($user->hasSetPassword());

        $user->password_set_at = now();
        $this->assertTrue($user->hasSetPassword());
    }
}
