<?php

namespace App\Support;

/**
 * Contato de suporte da WeGift (feedback do UAT): o Usuário resgatou com o
 * endereço errado e não tinha para quem pedir a correção.
 *
 * Fonte única do dado formatado. Os dois consumidores são os e-mails
 * parametrizáveis (variável {{ contato }}, injetada em EmailTemplate::render)
 * e o portal (prop Inertia `support`, em HandleInertiaRequests). Os valores
 * crus vêm de config/welog.php; formatar AQUI evita máscara de WhatsApp
 * divergente entre e-mail e rodapé.
 *
 * Tudo é opcional: sem nada configurado, `line()` degrada para "responda este
 * e-mail" (nunca "null" no meio da frase) e `channels()` devolve nulos, que o
 * front usa para esconder o bloco.
 */
final class SupportContact
{
    /**
     * Canais para o front (portal/rodapé da onda 3): e-mail, WhatsApp mascarado
     * para exibição e o link wa.me pronto (com DDI, que a máscara omite).
     *
     * @return array{email: ?string, whatsapp: ?string, whatsapp_link: ?string}
     */
    public static function channels(): array
    {
        $email = trim((string) config('welog.support.email')) ?: null;
        $whatsapp = trim((string) config('welog.support.whatsapp')) ?: null;

        return [
            'email' => $email,
            'whatsapp' => $whatsapp ? self::formatWhatsapp($whatsapp) : null,
            'whatsapp_link' => $whatsapp ? self::whatsappLink($whatsapp) : null,
        ];
    }

    /**
     * Frase pronta para o {{ contato }} dos modelos de e-mail, no formato
     * "suporte@wegift.com.br ou WhatsApp (11) 99999-0000". Sempre devolve algo
     * que fecha a frase "Fale com a gente: ...".
     */
    public static function line(): string
    {
        $canais = self::channels();

        return match (true) {
            $canais['email'] !== null && $canais['whatsapp'] !== null => $canais['email'].' ou WhatsApp '.$canais['whatsapp'],
            $canais['email'] !== null => $canais['email'],
            $canais['whatsapp'] !== null => 'WhatsApp '.$canais['whatsapp'],
            default => 'responda este e-mail',
        };
    }

    /**
     * Máscara de exibição do WhatsApp: (DD) 99999-0000, sem o DDI 55 — padrão
     * de apresentação do projeto. Número que não parece brasileiro (com ou sem
     * o 55 na frente) volta como foi digitado: melhor mostrar o que o .env tem
     * do que mutilar um número internacional.
     */
    private static function formatWhatsapp(string $raw): string
    {
        $local = self::localDigits($raw);

        return match (strlen((string) $local)) {
            11 => preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $local),
            10 => preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $local),
            default => trim($raw),
        };
    }

    /** Link wa.me: exige o número com DDI, então o 55 é reposto se faltar. */
    private static function whatsappLink(string $raw): ?string
    {
        $local = self::localDigits($raw);

        if ($local === null) {
            return null;
        }

        return 'https://wa.me/55'.$local;
    }

    /** DDD + número (10 ou 11 dígitos), sem o DDI; null quando não é um fixo/celular BR. */
    private static function localDigits(string $raw): ?string
    {
        $digitos = preg_replace('/\D/', '', $raw) ?? '';

        // 12 ou 13 dígitos começando com 55 = veio com DDI (ex.: +55 11 9...).
        if (str_starts_with($digitos, '55') && in_array(strlen($digitos), [12, 13], true)) {
            $digitos = substr($digitos, 2);
        }

        return in_array(strlen($digitos), [10, 11], true) ? $digitos : null;
    }
}
