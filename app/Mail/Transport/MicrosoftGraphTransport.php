<?php

namespace App\Mail\Transport;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Envio pela conta Microsoft 365, sem SMTP: a API do Microsoft Graph
 * (`POST /users/{caixa}/sendMail`), com um aplicativo do Entra ID que tem a
 * permissão de aplicativo Mail.Send.
 *
 * Existe porque a caixa do 365 pode não ter "SMTP autenticado" — e a
 * Microsoft está desligando o SMTP com usuário e senha. O Graph usa o token do
 * aplicativo (client credentials), que não depende de senha de usuário nem de
 * MFA.
 *
 * A mensagem vai em JSON, não em MIME: no MIME o Symfony tira o Bcc, e a cópia
 * oculta sumiria sem aviso.
 */
class MicrosoftGraphTransport extends AbstractTransport
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        /** Caixa que envia. Vazio = o remetente (From) de cada mensagem. */
        private readonly ?string $caixa = null,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $remetente = $email->getFrom()[0] ?? null;
        $caixa = filled($this->caixa) ? $this->caixa : $remetente?->getAddress();

        if (blank($caixa)) {
            throw new TransportException('Microsoft Graph: sem caixa para enviar. Defina MICROSOFT_GRAPH_SENDER ou MAIL_FROM_ADDRESS.');
        }

        $html = $this->conteudo($email->getHtmlBody());

        $mensagem = array_filter([
            'subject' => (string) $email->getSubject(),
            'body' => [
                'contentType' => $html !== null ? 'HTML' : 'Text',
                'content' => $html ?? $this->conteudo($email->getTextBody()) ?? '',
            ],
            'toRecipients' => $this->destinatarios($email->getTo()),
            'ccRecipients' => $this->destinatarios($email->getCc()),
            'bccRecipients' => $this->destinatarios($email->getBcc()),
            'replyTo' => $this->destinatarios($email->getReplyTo()),
            // Remetente diferente da caixa só sai com "Enviar como" dado a ela.
            'from' => $remetente !== null && strcasecmp($remetente->getAddress(), $caixa) !== 0
                ? $this->destinatario($remetente)
                : null,
            'attachments' => array_map($this->anexo(...), $email->getAttachments()),
        ], fn ($valor) => $valor !== null && $valor !== []);

        $resposta = $this->http
            ->withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            // O "@" fica como está, como nos exemplos do próprio Graph.
            ->post(self::GRAPH.'/users/'.str_replace('%40', '@', rawurlencode($caixa)).'/sendMail', [
                'message' => $mensagem,
                'saveToSentItems' => false,
            ]);

        if (! $resposta->successful()) {
            throw new TransportException(sprintf(
                'Microsoft Graph recusou o envio (HTTP %d): %s',
                $resposta->status(),
                $resposta->json('error.message') ?? $resposta->body(),
            ));
        }
    }

    /** Token do aplicativo, guardado até um minuto antes de vencer. */
    private function token(): string
    {
        $chave = 'microsoft-graph:token:'.$this->tenantId.':'.$this->clientId;

        if (is_string($token = Cache::get($chave))) {
            return $token;
        }

        $resposta = $this->http->asForm()->timeout(30)->post(
            'https://login.microsoftonline.com/'.rawurlencode($this->tenantId).'/oauth2/v2.0/token',
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        );

        $token = $resposta->json('access_token');

        if (! $resposta->successful() || ! is_string($token)) {
            throw new TransportException(sprintf(
                'Microsoft Graph: token recusado (HTTP %d): %s',
                $resposta->status(),
                $resposta->json('error_description') ?? $resposta->body(),
            ));
        }

        Cache::put($chave, $token, now()->addSeconds(max(60, (int) $resposta->json('expires_in', 3600) - 60)));

        return $token;
    }

    /** @param  resource|string|null  $corpo */
    private function conteudo(mixed $corpo): ?string
    {
        return is_resource($corpo) ? (string) stream_get_contents($corpo) : $corpo;
    }

    /**
     * @param  array<int, Address>  $enderecos
     * @return array<int, array{emailAddress: array{address: string, name?: string}}>
     */
    private function destinatarios(array $enderecos): array
    {
        return array_map($this->destinatario(...), $enderecos);
    }

    /** @return array{emailAddress: array{address: string, name?: string}} */
    private function destinatario(Address $endereco): array
    {
        return ['emailAddress' => array_filter([
            'address' => $endereco->getAddress(),
            'name' => $endereco->getName(),
        ], fn ($valor) => $valor !== '')];
    }

    /** @return array<string, mixed> */
    private function anexo(DataPart $parte): array
    {
        $inline = $parte->getDisposition() === 'inline';

        return array_filter([
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $parte->getFilename() ?? 'anexo',
            'contentType' => $parte->getMediaType().'/'.$parte->getMediaSubtype(),
            'contentBytes' => base64_encode($parte->getBody()),
            'isInline' => $inline,
            'contentId' => $inline ? $parte->getContentId() : null,
        ], fn ($valor) => $valor !== null);
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }
}
