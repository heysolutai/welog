<?php

namespace Tests\Unit;

use App\Support\Observability\SentryScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventId;
use Sentry\UserDataBag;

/**
 * O scrubber é a única coisa entre os dados de clientes finais (CPF, endereço,
 * e-mail) e um serviço de terceiros. Falha aqui é incidente de LGPD, não bug —
 * e seria silenciosa, porque o evento sai mesmo assim.
 */
class SentryScrubberTest extends TestCase
{
    private function event(): Event
    {
        return Event::createEvent(new EventId(str_repeat('a', 32)));
    }

    public function test_remove_campos_sensiveis_do_corpo_da_requisicao(): void
    {
        $event = $this->event();
        $event->setRequest([
            'url' => 'https://welog.test/portal/resgatar/1',
            'method' => 'POST',
            'data' => [
                'recipient_name' => 'João da Silva',
                'cpf' => '529.982.247-25',
                'zip' => '80010-000',
                'street' => 'Rua XV de Novembro',
                'complement' => 'Apto 42',
                'city' => 'Curitiba',
                'state' => 'PR',          // UF sozinha não identifica ninguém
                'product_id' => 7,
            ],
        ]);

        $data = SentryScrubber::beforeSend($event)->getRequest()['data'];

        foreach (['recipient_name', 'cpf', 'zip', 'street', 'complement', 'city'] as $campo) {
            $this->assertSame('[filtrado]', $data[$campo], "{$campo} deveria ser filtrado");
        }

        // O que não é PII precisa sobreviver, senão o evento perde utilidade
        $this->assertSame(7, $data['product_id']);
        $this->assertSame('PR', $data['state']);
    }

    public function test_mascara_cpf_e_email_embutidos_em_texto(): void
    {
        $event = $this->event();
        $event->setExtra([
            'mensagem' => 'Falha ao processar 529.982.247-25 de joao@acme.com.br',
            'cnpj_no_texto' => 'Pedido da 12.345.678/0001-90',
        ]);

        $extra = SentryScrubber::beforeSend($event)->getExtra();

        $this->assertStringNotContainsString('529.982.247-25', $extra['mensagem']);
        $this->assertStringNotContainsString('joao@acme.com.br', $extra['mensagem']);
        $this->assertStringNotContainsString('12.345.678/0001-90', $extra['cnpj_no_texto']);
    }

    public function test_pega_documento_sem_pontuacao(): void
    {
        $event = $this->event();
        $event->setExtra(['log' => 'cliente 52998224725 nao encontrado']);

        $this->assertStringNotContainsString(
            '52998224725',
            SentryScrubber::beforeSend($event)->getExtra()['log'],
        );
    }

    public function test_filtra_estrutura_aninhada(): void
    {
        $event = $this->event();
        $event->setExtra([
            'payload' => [
                'cliente' => [
                    'cnpj_cpf' => '12345678000190',
                    'razao_social' => 'ACME Ltda',   // razão social é público
                ],
            ],
        ]);

        $payload = SentryScrubber::beforeSend($event)->getExtra()['payload'];

        $this->assertSame('[filtrado]', $payload['cliente']['cnpj_cpf']);
        $this->assertSame('ACME Ltda', $payload['cliente']['razao_social']);
    }

    public function test_usuario_fica_so_com_o_id(): void
    {
        $event = $this->event();
        $user = UserDataBag::createFromUserIdentifier(42);
        $user->setEmail('joao@acme.com.br');
        $user->setUsername('joao');
        $user->setIpAddress('203.0.113.10');
        $event->setUser($user);

        $limpo = SentryScrubber::beforeSend($event)->getUser();

        $this->assertSame(42, $limpo->getId());
        $this->assertNull($limpo->getEmail());
        $this->assertNull($limpo->getUsername());
        $this->assertNull($limpo->getIpAddress());
    }

    public function test_breadcrumb_de_query_nao_leva_documento(): void
    {
        $crumb = new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'sql.query',
            'select * from users where cpf = 52998224725',
            ['email' => 'joao@acme.com.br', 'tempo_ms' => 12],
        );

        $limpo = SentryScrubber::beforeBreadcrumb($crumb);

        $this->assertStringNotContainsString('52998224725', $limpo->getMessage());
        $this->assertSame('[filtrado]', $limpo->getMetadata()['email']);
        $this->assertSame(12, $limpo->getMetadata()['tempo_ms']);
    }

    public function test_token_e_senha_saem_do_evento(): void
    {
        $event = $this->event();
        $event->setRequest(['data' => [
            'password' => 'SenhaForte#2026',
            'token' => 'abc123',
            'app_secret' => 'segredo-da-omie',
        ]]);

        foreach (SentryScrubber::beforeSend($event)->getRequest()['data'] as $campo => $valor) {
            $this->assertSame('[filtrado]', $valor, "{$campo} deveria ser filtrado");
        }
    }
}
