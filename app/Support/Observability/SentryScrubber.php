<?php

namespace App\Support\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Última barreira antes de um evento sair do servidor para o Sentry.
 *
 * A WeLog trafega CPF, endereço de entrega, telefone e e-mail de clientes
 * finais. Um stack trace com o corpo da requisição de um resgate levaria tudo
 * isso para um serviço de terceiros — problema de LGPD, não de configuração.
 *
 * Duas camadas, porque nenhuma sozinha basta:
 *  1. Por NOME do campo (`cpf`, `zip`, `recipient_name`…), que pega o dado
 *     estruturado;
 *  2. Por PADRÃO no valor (CPF, CNPJ, e-mail), que pega o mesmo dado embutido
 *     numa mensagem de exceção ou numa URL.
 */
class SentryScrubber
{
    private const REDACTED = '[filtrado]';

    /**
     * Campos removidos pelo nome. Comparação por "contém", em minúsculas —
     * cobre `cpf`, `customer_cpf` e `cliente.cpf` de uma vez.
     */
    private const SENSITIVE_KEYS = [
        // Documentos e credenciais
        'cpf', 'cnpj', 'documento', 'document', 'cnpj_cpf',
        'password', 'senha', 'token', 'secret', 'authorization', 'cookie',
        'app_key', 'app_secret', 'api_key', 'access_key',
        // Contato
        'email', 'e_mail', 'phone', 'telefone', 'celular',
        // Endereço de entrega
        'recipient_name', 'customer_name', 'zip', 'cep', 'street', 'endereco',
        'logradouro', 'complement', 'complemento', 'district', 'bairro',
        'city', 'cidade',
    ];

    /** CPF (11 dígitos) e CNPJ (14), com ou sem pontuação. */
    private const DOCUMENT_PATTERN = '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b|\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/';

    private const EMAIL_PATTERN = '/[\w.+-]+@[\w-]+\.[\w.-]+/';

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    /**
     * Breadcrumbs de query carregam a SQL; as de HTTP carregam a URL. Ambas
     * podem trazer documento ou e-mail no meio.
     */
    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        $metadata = self::redact($breadcrumb->getMetadata());

        $scrubbed = new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            self::maskPatterns($breadcrumb->getMessage()),
            [],
            $breadcrumb->getTimestamp(),
        );

        foreach ($metadata as $name => $value) {
            $scrubbed = $scrubbed->withMetadata($name, $value);
        }

        return $scrubbed;
    }

    private static function scrub(Event $event): Event
    {
        $event->setRequest(self::redact($event->getRequest()));
        $event->setExtra(self::redact($event->getExtra()));

        // Identificar o usuário pelo ID é suficiente para investigar; nome,
        // e-mail e IP não acrescentam nada que justifique guardá-los fora daqui.
        if ($user = $event->getUser()) {
            $user->setEmail(null);
            $user->setUsername(null);
            $user->setIpAddress(null);
        }

        $breadcrumbs = array_map(
            fn (Breadcrumb $crumb) => self::beforeBreadcrumb($crumb),
            $event->getBreadcrumbs(),
        );
        $event->setBreadcrumb(array_values(array_filter($breadcrumbs)));

        return $event;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            $data[$key] = match (true) {
                is_array($value) => self::redact($value),
                is_string($value) => self::maskPatterns($value),
                default => $value,
            };
        }

        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function maskPatterns(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $value = preg_replace(self::DOCUMENT_PATTERN, self::REDACTED, $value) ?? $value;

        return preg_replace(self::EMAIL_PATTERN, self::REDACTED, $value) ?? $value;
    }
}
