<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Exceptions\DomainException;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\FluxoA\ReleaseProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Trava de saldo na liberação (falha do UAT no projeto 68366) e o caminho do
 * sinal de estoque até a tela do Projeto.
 *
 * Regra: a partir de "Em Estoque" o saldo da plataforma e o da Omie andam
 * juntos, e liberar é abrir o consumo desse saldo — por isso a liberação exige
 * saldo real no espelho (products.stock_qty). Mover o estágio à mão não basta.
 */
class ReleaseStockGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    /**
     * Projeto já posicionado em "Em Estoque" (como o UAT fez à mão), com um
     * kit cujo saldo espelhado é controlado pelo teste. `null` = nunca
     * sincronizado (stock_qty fica no default 0 e stock_synced_at nulo).
     *
     * @return array{0: Project, 1: Product}
     */
    private function projetoEmEstoque(?float $saldoEspelhado, float $pedido = 50): array
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);

        $product = Product::create([
            'omie_code' => 'KIT-GUARD-01',
            'name' => 'Kit Onboarding',
        ] + ($saldoEspelhado === null
            ? []
            : ['stock_qty' => $saldoEspelhado, 'stock_synced_at' => now()]));

        $project = Project::create([
            'company_id' => $company->id,
            'name' => 'PV-68366 ACME',
            'quantity' => (int) $pedido,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
        ]);
        $project->products()->attach($product->id, ['quantity' => $pedido]);

        return [$project, $product];
    }

    // ------------------------------------------------------------------
    // Falha UAT 68366: estágio manual habilitava liberar sem saldo
    // ------------------------------------------------------------------

    public function test_liberacao_recusa_quando_o_saldo_espelhado_e_menor_que_o_pedido(): void
    {
        [$project] = $this->projetoEmEstoque(saldoEspelhado: 12, pedido: 50);

        $this->actingAs($this->admin())
            ->post(route('projects.release', $project))
            ->assertSessionHasErrors([
                'release' => 'Sem saldo em estoque para liberar: Kit Onboarding '
                    .'(precisa 50, há 12). Sincronize o estoque ou aguarde a produção.',
            ]);

        $this->assertFalse($project->fresh()->isReleased());
        Mail::assertNothingQueued();
    }

    public function test_liberacao_recusa_quando_o_saldo_nunca_foi_sincronizado(): void
    {
        [$project] = $this->projetoEmEstoque(saldoEspelhado: null, pedido: 50);

        $this->actingAs($this->admin())
            ->post(route('projects.release', $project))
            ->assertSessionHasErrors([
                'release' => 'Sem saldo em estoque para liberar: Kit Onboarding '
                    .'(precisa 50, estoque nunca sincronizado). '
                    .'Sincronize o estoque ou aguarde a produção.',
            ]);

        $this->assertFalse($project->fresh()->isReleased());
    }

    public function test_liberacao_passa_quando_o_saldo_cobre_exatamente_o_pedido(): void
    {
        [$project] = $this->projetoEmEstoque(saldoEspelhado: 50, pedido: 50);

        $this->actingAs($this->admin())
            ->post(route('projects.release', $project))
            ->assertSessionHasNoErrors();

        $this->assertTrue($project->fresh()->isReleased());
    }

    /** Todos os itens abaixo do pedido aparecem na mensagem, não só o primeiro. */
    public function test_mensagem_nomeia_todos_os_itens_sem_saldo(): void
    {
        [$project] = $this->projetoEmEstoque(saldoEspelhado: 12, pedido: 50);

        // Sem stock_synced_at: nunca sincronizada, mesmo com o default 0.
        $caneca = Product::create([
            'omie_code' => 'CANECA-GUARD',
            'name' => 'Caneca Personalizada',
        ]);
        $project->products()->attach($caneca->id, ['quantity' => 30]);

        try {
            app(ReleaseProject::class)->handle($project, $this->admin());
            $this->fail('A liberação deveria ter sido barrada por falta de saldo.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Kit Onboarding (precisa 50, há 12)', $e->getMessage());
            $this->assertStringContainsString(
                'Caneca Personalizada (precisa 30, estoque nunca sincronizado)',
                $e->getMessage(),
            );
        }

        $this->assertFalse($project->fresh()->isReleased());
    }

    // ------------------------------------------------------------------
    // Estágio manual para "Em Estoque" ressincroniza o espelho
    // ------------------------------------------------------------------

    public function test_mudanca_manual_para_em_estoque_reavalia_o_saldo_na_omie_em_uma_chamada(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $product = Product::create(['omie_code' => 'KIT-GUARD-01', 'name' => 'Kit Onboarding']);
        $project = Project::create([
            'company_id' => $company->id, 'name' => 'PV-68366 ACME', 'quantity' => 50,
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);
        $project->products()->attach($product->id, ['quantity' => 50]);

        // UMA chamada em lote, com os códigos do Projeto: uma consulta por kit
        // faria a Omie punir a conta por consumo redundante.
        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getStockPositions')
            ->once()
            ->with(['KIT-GUARD-01'])
            ->andReturn(['KIT-GUARD-01' => 999.0]);

        $this->actingAs($this->admin())
            ->patch(route('projects.stage', $project), ['pipeline_status' => Project::STATUS_IN_STOCK])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // O espelho ficou fresco e o Projeto ganhou a marca de estoque.
        $this->assertSame(999.0, (float) $product->fresh()->stock_qty);
        $this->assertNotNull($product->fresh()->stock_synced_at);
        $this->assertSame(Project::STATUS_IN_STOCK, $project->fresh()->pipeline_status);
        $this->assertNotNull($project->fresh()->stock_available_at);

        // Com saldo real no espelho, a liberação abre.
        $this->actingAs($this->admin())
            ->post(route('projects.release', $project))
            ->assertSessionHasNoErrors();

        $this->assertTrue($project->fresh()->isReleased());
    }

    /**
     * Replay da falha 68366 com a Omie fora do ar: o estágio muda mesmo assim
     * (a operação não pode ficar presa), mas a liberação continua barrada
     * porque o espelho nunca foi sincronizado.
     */
    public function test_falha_da_omie_nao_impede_o_estagio_manual_mas_a_liberacao_continua_barrada(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $product = Product::create(['omie_code' => 'KIT-GUARD-01', 'name' => 'Kit Onboarding']);
        $project = Project::create([
            'company_id' => $company->id, 'name' => 'PV-68366 ACME', 'quantity' => 50,
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);
        $project->products()->attach($product->id, ['quantity' => 50]);

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getStockPositions')
            ->andThrow(new \RuntimeException('Omie indisponivel'));

        $this->actingAs($this->admin())
            ->patch(route('projects.stage', $project), ['pipeline_status' => Project::STATUS_IN_STOCK])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(Project::STATUS_IN_STOCK, $project->fresh()->pipeline_status);
        $this->assertNull($product->fresh()->stock_synced_at);

        $this->actingAs($this->admin())
            ->post(route('projects.release', $project))
            ->assertSessionHasErrors('release');

        $this->assertFalse($project->fresh()->isReleased());
    }

    /** Projeto sem produtos (onboarding manual) não consulta a Omie nem trava. */
    public function test_projeto_sem_produtos_muda_de_estagio_sem_consultar_a_omie(): void
    {
        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $project = Project::create([
            'company_id' => $company->id, 'name' => 'Manual', 'quantity' => 10,
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);

        // Mock sem expectativa de chamada: qualquer consulta estouraria aqui.
        $this->mock(OmieGateway::class)->shouldNotReceive('getStockPositions');

        $this->actingAs($this->admin())
            ->patch(route('projects.stage', $project), ['pipeline_status' => Project::STATUS_IN_STOCK])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(Project::STATUS_IN_STOCK, $project->fresh()->pipeline_status);
    }

    // ------------------------------------------------------------------
    // Falha UAT 2: sinal automático × botão de liberar na tela
    // ------------------------------------------------------------------

    /**
     * Prova de causa-raiz da falha "mudou sozinho para Em Estoque e o botão
     * não apareceu": DEPOIS do sinal, um GET fresco da página já devolve todas
     * as props que o botão Liberar Resgates exige (`can_be_released`,
     * `in_stock`, `permissions.release` e ao menos um Usuário). O backend está
     * correto em qualquer recarga; o que falta é a página Inertia se atualizar
     * sozinha quando o webhook promove o Projeto (tarefa da onda de frontend:
     * botão Atualizar/polling).
     */
    public function test_apos_o_sinal_de_estoque_a_pagina_do_projeto_ja_volta_liberavel(): void
    {
        config(['integrations.omie.webhook_token' => 'test-token']);

        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $product = Product::create(['omie_code' => 'KIT-GUARD-01', 'name' => 'Kit Onboarding']);
        $project = Project::create([
            'company_id' => $company->id, 'name' => 'PV-70001 ACME', 'quantity' => 50,
            'pipeline_status' => Project::STATUS_DESIGN,
        ]);
        $project->products()->attach($product->id, ['quantity' => 50]);

        $pessoa = User::create([
            'name' => 'Bruno', 'email' => 'bruno@acme.com.br', 'password' => 'x',
            'role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id, 'active' => true,
            'password_set_at' => now(),
        ]);
        ProjectEntitlement::create([
            'project_id' => $project->id, 'user_id' => $pessoa->id, 'product_id' => $product->id,
        ]);

        $omie = $this->mock(OmieGateway::class);
        $omie->shouldReceive('getStockPositions')->andReturn(['KIT-GUARD-01' => 200.0]);

        // O sinal da Omie chega pelo webhook e promove o Projeto sozinho.
        $this->postJson('/webhooks/omie/test-token', [
            'messageId' => 'msg-stock-guard-1',
            'topic' => 'Estoque.Alterado',
            'event' => ['codigo_produto' => 'KIT-GUARD-01'],
        ])->assertOk();

        $this->assertSame(Project::STATUS_IN_STOCK, $project->fresh()->pipeline_status);

        // Recarregar é suficiente: a tela recebe o Projeto liberável.
        $operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->actingAs($operador)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->where('project.pipeline_status', Project::STATUS_IN_STOCK)
                ->where('project.in_stock', true)
                ->where('project.released', false)
                ->where('project.can_be_released', true)
                ->where('project.stock_available', true)
                ->where('project.products.0.stock.sufficient', true)
                ->where('permissions.release', true)
                ->has('colaboradores.data', 1)
                ->where('project.pessoas.total', 1),
            );
    }
}
