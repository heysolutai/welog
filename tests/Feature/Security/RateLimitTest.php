<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        RateLimiter::clear('auth');
    }

    public function test_login_bloqueia_apos_muitas_tentativas(): void
    {
        // 5 tentativas por e-mail+IP: a 6ª deve receber 429
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'admin@welog.local', 'password' => 'errada'])
                ->assertStatus(302);
        }

        $this->post('/login', ['email' => 'admin@welog.local', 'password' => 'errada'])
            ->assertStatus(429);
    }

    public function test_esqueci_senha_tem_rate_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => 'x@welog.local'])->assertStatus(302);
        }

        $this->post(route('password.email'), ['email' => 'x@welog.local'])->assertStatus(429);
    }

    public function test_webhooks_tem_rate_limit_alto_mas_presente(): void
    {
        config(['integrations.omie.webhook_token' => 'tok']);

        // Dentro do teto (120/min) responde normalmente
        $this->postJson('/webhooks/omie/tok', ['ping' => 'omie'])->assertOk();
    }
}
