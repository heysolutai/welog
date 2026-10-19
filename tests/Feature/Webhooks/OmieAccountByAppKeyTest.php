<?php

namespace Tests\Feature\Webhooks;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * De qual CNPJ veio o evento: a `appKey` do corpo manda mais que o token.
 *
 * O token da URL é a credencial e continua autorizando, mas quem o registrou
 * no painel da Omie foi uma pessoa — e uma conta pode acabar registrada com a
 * URL da outra. Quando isso acontece, a conta gravada fica errada e toda
 * consulta seguinte usa a chave que não enxerga aquele pedido.
 */
class OmieAccountByAppKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        config(['integrations.omie.accounts' => ['webox', 'santa']]);

        // Credencial de conta nomeada vem de `env()` com prefixo, não de
        // config — é assim que o .env de produção a declara.
        $this->definirEnv([
            'OMIE_WEBOX_APP_KEY' => 'KEY-WEBOX',
            'OMIE_WEBOX_APP_SECRET' => 's1',
            'OMIE_WEBOX_WEBHOOK_TOKEN' => 'tok-webox',
            'OMIE_SANTA_APP_KEY' => 'KEY-SANTA',
            'OMIE_SANTA_APP_SECRET' => 's2',
            'OMIE_SANTA_WEBHOOK_TOKEN' => 'tok-santa',
        ]);
    }

    /** @var list<string> */
    private array $envDefinidas = [];

    /** @param  array<string, string>  $vars */
    private function definirEnv(array $vars): void
    {
        foreach ($vars as $chave => $valor) {
            putenv("{$chave}={$valor}");
            $_ENV[$chave] = $valor;
            $_SERVER[$chave] = $valor;
            $this->envDefinidas[] = $chave;
        }
    }

    protected function tearDown(): void
    {
        // Sem isto a conta fictícia vaza para os testes seguintes do mesmo
        // processo, e a falha aparece em outro arquivo.
        foreach ($this->envDefinidas as $chave) {
            putenv($chave);
            unset($_ENV[$chave], $_SERVER[$chave]);
        }

        parent::tearDown();
    }

    private function enviar(string $token, ?string $appKey): void
    {
        $this->postJson("/webhooks/omie/{$token}", array_filter([
            'messageId' => 'msg-'.uniqid(),
            'topic' => 'VendaProduto.Incluida',
            'appKey' => $appKey,
            'event' => ['idPedido' => 123],
        ]))->assertSuccessful();
    }

    public function test_conta_vem_do_token_quando_a_appkey_confere(): void
    {
        $this->enviar('tok-webox', 'KEY-WEBOX');

        $this->assertSame('webox', WebhookEvent::latest('id')->first()->omie_account);
    }

    /** O caso que motiva tudo: URL registrada na conta errada. */
    public function test_appkey_corrige_a_conta_quando_o_token_e_de_outra(): void
    {
        $this->enviar('tok-webox', 'KEY-SANTA');

        $this->assertSame('santa', WebhookEvent::latest('id')->first()->omie_account);
    }

    /** Chave que não é de ninguém não derruba o evento: o token conferiu. */
    public function test_appkey_desconhecida_mantem_a_conta_do_token(): void
    {
        $this->enviar('tok-santa', 'KEY-DE-OUTRO-SISTEMA');

        $this->assertSame('santa', WebhookEvent::latest('id')->first()->omie_account);
    }

    public function test_sem_appkey_vale_o_token(): void
    {
        $this->enviar('tok-santa', null);

        $this->assertSame('santa', WebhookEvent::latest('id')->first()->omie_account);
    }
}
