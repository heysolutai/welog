<?php

namespace Tests\Feature;

use App\Integrations\Omie\OmieAccounts;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\FluxoA\OmieProjectMarker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Mais de um CNPJ na Omie: o token da URL diz de qual conta veio o evento, e
 * é a chave dessa conta que consulta o pedido depois.
 */
class OmieMultiAccountTest extends TestCase
{
    use RefreshDatabase;

    private function configurarDuasContas(): void
    {
        config([
            'integrations.omie.driver' => 'api',
            'integrations.omie.accounts' => ['matriz', 'filial'],
            'integrations.omie.base_url' => 'https://omie.test/api/v1',
        ]);

        putenv('OMIE_MATRIZ_APP_KEY=chave-matriz');
        putenv('OMIE_MATRIZ_APP_SECRET=segredo-matriz');
        putenv('OMIE_MATRIZ_WEBHOOK_TOKEN=token-matriz');
        putenv('OMIE_FILIAL_APP_KEY=chave-filial');
        putenv('OMIE_FILIAL_APP_SECRET=segredo-filial');
        putenv('OMIE_FILIAL_WEBHOOK_TOKEN=token-filial');
    }

    protected function tearDown(): void
    {
        foreach (['MATRIZ', 'FILIAL'] as $c) {
            foreach (['APP_KEY', 'APP_SECRET', 'WEBHOOK_TOKEN'] as $k) {
                putenv("OMIE_{$c}_{$k}");
            }
        }

        parent::tearDown();
    }

    public function test_cada_conta_tem_credenciais_e_gateway_proprios(): void
    {
        $this->configurarDuasContas();
        $accounts = app(OmieAccounts::class);

        $this->assertSame(['matriz', 'filial'], $accounts->names());
        $this->assertSame('matriz', $accounts->defaultName());
        $this->assertTrue($accounts->isMultiAccount());
        $this->assertSame('chave-matriz', $accounts->credentials('matriz')['app_key']);
        $this->assertSame('chave-filial', $accounts->credentials('filial')['app_key']);
        $this->assertNotSame($accounts->gateway('matriz'), $accounts->gateway('filial'));
    }

    public function test_token_da_url_identifica_a_conta_e_token_desconhecido_e_recusado(): void
    {
        $this->configurarDuasContas();
        $accounts = app(OmieAccounts::class);

        $this->assertSame('matriz', $accounts->accountForToken('token-matriz'));
        $this->assertSame('filial', $accounts->accountForToken('token-filial'));
        $this->assertNull($accounts->accountForToken('token-de-ninguem'));
    }

    public function test_webhook_grava_a_conta_de_origem(): void
    {
        $this->configurarDuasContas();

        $this->postJson('/webhooks/omie/token-filial', [
            'messageId' => 'msg-1',
            'topic' => 'Financeiro.Alterado',
            'event' => [],
        ])->assertOk();

        $this->assertSame('filial', WebhookEvent::where('external_event_id', 'msg-1')->value('omie_account'));

        // Token que não é de nenhuma conta continua 403.
        $this->postJson('/webhooks/omie/token-invalido', ['messageId' => 'x', 'topic' => 'y'])
            ->assertForbidden();
    }

    public function test_conta_unica_continua_funcionando_como_antes(): void
    {
        config([
            'integrations.omie.accounts' => [],
            'integrations.omie.webhook_token' => 'token-unico',
        ]);

        $accounts = app(OmieAccounts::class);

        $this->assertSame(['default'], $accounts->names());
        $this->assertFalse($accounts->isMultiAccount());
        $this->assertSame('default', $accounts->accountForToken('token-unico'));

        $this->postJson('/webhooks/omie/token-unico', [
            'messageId' => 'msg-2', 'topic' => 'Financeiro.Alterado', 'event' => [],
        ])->assertOk();

        $this->assertSame('default', WebhookEvent::where('external_event_id', 'msg-2')->value('omie_account'));
    }

    public function test_conta_desconhecida_no_gateway_e_erro_explicito(): void
    {
        $this->configurarDuasContas();

        $this->expectException(\InvalidArgumentException::class);
        app(OmieAccounts::class)->gateway('conta-que-nao-existe');
    }

    public function test_rotulo_da_conta_vem_do_env_e_cai_no_nome(): void
    {
        $this->configurarDuasContas();
        putenv('OMIE_MATRIZ_LABEL=WeBox');

        $accounts = app(OmieAccounts::class);

        $this->assertSame('WeBox', $accounts->label('matriz'));
        $this->assertSame('Filial', $accounts->label('filial'));
        $this->assertSame(
            [['value' => 'matriz', 'label' => 'WeBox'], ['value' => 'filial', 'label' => 'Filial']],
            $accounts->options(),
        );

        putenv('OMIE_MATRIZ_LABEL');
    }

    public function test_lista_de_projetos_filtra_por_conta(): void
    {
        $this->seed();
        $this->configurarDuasContas();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);

        Project::create(['company_id' => $empresa->id, 'name' => 'Da matriz', 'quantity' => 1, 'omie_account' => 'matriz']);
        Project::create(['company_id' => $empresa->id, 'name' => 'Da filial', 'quantity' => 1, 'omie_account' => 'filial']);
        Project::create(['company_id' => $empresa->id, 'name' => 'Antigo sem conta', 'quantity' => 1]);

        $admin = User::where('email', 'admin@welog.local')->first();

        $this->actingAs($admin)
            ->get(route('projects.index', ['account' => 'filial']))
            ->assertInertia(fn (Assert $p) => $p->component('Projects/Index')
                ->has('projects.data', 1)
                ->where('projects.data.0.name', 'Da filial')
                ->where('projects.data.0.origem', 'Filial'));

        // A conta padrão leva junto o histórico gravado antes do multi-CNPJ.
        $this->actingAs($admin)
            ->get(route('projects.index', ['account' => 'matriz']))
            ->assertInertia(fn (Assert $p) => $p->has('projects.data', 2));
    }

    public function test_projeto_guarda_a_conta_para_reconsulta(): void
    {
        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $p = Project::create([
            'company_id' => $empresa->id, 'name' => 'X', 'quantity' => 1,
            'omie_order_id' => '999', 'omie_account' => 'filial',
        ]);

        $this->assertSame('filial', $p->fresh()->omie_account);
    }

    /**
     * Cada CNPJ tem o próprio cadastro de Projetos na Omie: o "Venda" da conta
     * A tem código diferente do "Venda" da conta B. Filtrar com o código errado
     * reprovaria pedido legítimo em silêncio.
     */
    public function test_codigo_de_venda_e_por_conta_com_o_global_como_padrao(): void
    {
        $this->configurarDuasContas();
        config(['integrations.omie.project_codes' => '111']);
        putenv('OMIE_FILIAL_PROJECT_CODES=999');

        $marker = app(OmieProjectMarker::class);
        $pedidoMatriz = ['informacoes_adicionais' => ['codProj' => 111]];
        $pedidoFilial = ['informacoes_adicionais' => ['codProj' => 999]];

        // Sem variável própria, a conta usa o código global.
        $this->assertSame(['111'], $marker->projectCodes('matriz'));
        $this->assertTrue($marker->matches($pedidoMatriz, 'matriz'));
        $this->assertFalse($marker->matches($pedidoFilial, 'matriz'));

        // Com variável própria, vale o código da conta.
        $this->assertSame(['999'], $marker->projectCodes('filial'));
        $this->assertTrue($marker->matches($pedidoFilial, 'filial'));
        $this->assertFalse($marker->matches($pedidoMatriz, 'filial'));

        putenv('OMIE_FILIAL_PROJECT_CODES');
    }
}
