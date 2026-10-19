import * as Sentry from '@sentry/vue';

/*
 * Sentry no navegador.
 *
 * O mesmo cuidado do backend vale aqui, e mais: o que sai daqui é o navegador
 * do Usuário, com CPF e endereço de entrega digitados no formulário de
 * resgate. Sem `beforeSend`, uma exceção de JavaScript na tela de resgate
 * levaria o formulário inteiro junto.
 *
 * Sem VITE_SENTRY_DSN nada é inicializado — é assim que dev e testes ficam de
 * fora.
 */

const REDACTED = '[filtrado]';

// CPF (11 dígitos) e CNPJ (14), com ou sem pontuação — iguais ao SentryScrubber.
const DOCUMENT = /\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b|\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/g;
const EMAIL = /[\w.+-]+@[\w-]+\.[\w.-]+/g;

const SENSITIVE_KEYS = [
    'cpf', 'cnpj', 'documento', 'document',
    'password', 'senha', 'token', 'secret', 'authorization',
    'email', 'e_mail', 'phone', 'telefone', 'celular',
    'recipient_name', 'customer_name', 'zip', 'cep', 'street', 'endereco',
    'logradouro', 'complement', 'complemento', 'district', 'bairro', 'city', 'cidade',
];

function maskPatterns(value) {
    return typeof value === 'string'
        ? value.replace(DOCUMENT, REDACTED).replace(EMAIL, REDACTED)
        : value;
}

function redact(value, depth = 0) {
    // Guarda contra estrutura cíclica ou muito profunda vinda de um erro do Vue
    if (depth > 6 || value === null || typeof value !== 'object') {
        return maskPatterns(value);
    }

    if (Array.isArray(value)) {
        return value.map((item) => redact(item, depth + 1));
    }

    return Object.fromEntries(
        Object.entries(value).map(([key, item]) => [
            key,
            SENSITIVE_KEYS.some((s) => key.toLowerCase().includes(s))
                ? REDACTED
                : redact(item, depth + 1),
        ]),
    );
}

export function initSentry(app) {
    const dsn = import.meta.env.VITE_SENTRY_DSN;

    if (!dsn) {
        return;
    }

    Sentry.init({
        app,
        dsn,
        environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || 'production',
        release: import.meta.env.VITE_SENTRY_RELEASE || undefined,

        // Sem replay nem captura de input: gravaria a digitação do CPF e do
        // endereço na tela de resgate.
        integrations: [],

        // Erro de rede e extensão de navegador poluem sem indicar defeito nosso.
        ignoreErrors: [
            'ResizeObserver loop limit exceeded',
            'ResizeObserver loop completed with undelivered notifications',
            'Non-Error promise rejection captured',
            /^Network request failed$/,
            /^Failed to fetch$/,
            /^Load failed$/,
        ],

        beforeSend(event) {
            if (event.request) {
                // A URL pode carregar e-mail em querystring (link de convite)
                event.request.url = maskPatterns(event.request.url);
                delete event.request.cookies;
                event.request.data = redact(event.request.data);
            }

            event.extra = redact(event.extra);
            event.contexts = redact(event.contexts);

            if (event.user) {
                // Identidade só pelo ID, como no backend
                event.user = event.user.id ? { id: event.user.id } : undefined;
            }

            if (event.message) {
                event.message = maskPatterns(event.message);
            }

            event.breadcrumbs = event.breadcrumbs?.map((crumb) => ({
                ...crumb,
                message: maskPatterns(crumb.message),
                data: redact(crumb.data),
            }));

            return event;
        },
    });
}
