<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 403 em navegação Inertia vira página do app, com botões que funcionam. */
class ForbiddenPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_403_inertia_renderiza_pagina_do_app(): void
    {
        $this->seed();
        $cliente = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'password_set_at' => now()]);

        // O middleware do Inertia exige a versão atual dos assets no GET;
        // sem ela o teste morre num 409 antes de chegar ao 403.
        $version = (new HandleInertiaRequests)->version(request());

        $this->actingAs($cliente)
            ->get(route('dashboard'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->assertStatus(403)
            ->assertJsonPath('component', 'Errors/Forbidden')
            ->assertJsonPath('props.home', fn ($home) => str_contains($home, '/portal'));
    }

    public function test_403_sem_inertia_continua_no_blade(): void
    {
        $this->seed();
        $cliente = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'password_set_at' => now()]);

        $this->actingAs($cliente)
            ->get(route('dashboard'))
            ->assertStatus(403)
            ->assertSee('Ir para a minha área');
    }
}
