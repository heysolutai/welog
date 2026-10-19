<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * MAIL_MAILER=microsoft-graph: a conta do Microsoft 365 envia pela API do
 * Graph, sem SMTP, com o token do aplicativo do Entra ID.
 */
class MicrosoftGraphMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'microsoft-graph',
            'mail.from.address' => 'atendimento@wegift.com.br',
            'mail.from.name' => 'WeGift',
            'services.microsoft_graph' => [
                'tenant_id' => 'tenant-1',
                'client_id' => 'app-1',
                'client_secret' => 'segredo',
                'sender' => null,
            ],
        ]);
    }

    public function test_envia_pela_api_do_graph_com_o_token_do_aplicativo(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-123', 'expires_in' => 3599]),
            'graph.microsoft.com/*' => Http::response(null, 202),
        ]);

        Mail::html('<p>Olá</p>', fn ($m) => $m->to('yago@exemplo.com', 'Yago')->bcc('copia@exemplo.com')->subject('Teste'));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token'
            && $r['client_id'] === 'app-1'
            && $r['client_secret'] === 'segredo'
            && $r['grant_type'] === 'client_credentials');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.microsoft.com/v1.0/users/atendimento@wegift.com.br/sendMail'
            && $r->hasHeader('Authorization', 'Bearer token-123')
            && $r['message']['subject'] === 'Teste'
            && $r['message']['body'] === ['contentType' => 'HTML', 'content' => '<p>Olá</p>']
            && $r['message']['toRecipients'] === [['emailAddress' => ['address' => 'yago@exemplo.com', 'name' => 'Yago']]]
            // A cópia oculta não some no caminho.
            && $r['message']['bccRecipients'] === [['emailAddress' => ['address' => 'copia@exemplo.com']]]
            // Remetente = a própria caixa: sem "Enviar como".
            && ! isset($r['message']['from']));
    }

    public function test_o_token_e_reaproveitado_entre_envios(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-123', 'expires_in' => 3599]),
            'graph.microsoft.com/*' => Http::response(null, 202),
        ]);

        Mail::raw('Um', fn ($m) => $m->to('a@exemplo.com')->subject('1'));
        Mail::raw('Dois', fn ($m) => $m->to('b@exemplo.com')->subject('2'));

        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'login.microsoftonline.com')));
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.microsoft.com')));
    }

    public function test_recusa_do_graph_falha_o_envio_com_o_motivo(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-123', 'expires_in' => 3599]),
            'graph.microsoft.com/*' => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403),
        ]);

        // Falha de verdade: o job da fila tenta de novo, em vez de dar o
        // e-mail como enviado.
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Access is denied.');

        Mail::raw('Oi', fn ($m) => $m->to('yago@exemplo.com')->subject('Teste'));
    }
}
