/*
 * Preenchimento de endereço a partir do CEP.
 *
 * Distingue de propósito três desfechos: preenchido, CEP inexistente e serviço
 * indisponível. Tratar os dois últimos como a mesma coisa faria o operador
 * acreditar que digitou errado quando o provedor apenas está fora do ar.
 */

const digits = (value) => String(value ?? '').replace(/\D/g, '');

export function isCompleteCep(value) {
    return digits(value).length === 8;
}

export function formatCep(value) {
    const d = digits(value).slice(0, 8);

    return d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d;
}

/**
 * Tempo máximo que o navegador espera pela consulta.
 *
 * Do outro lado, o servidor fala com o provedor com timeout de 30s e novas
 * tentativas com backoff: a resposta pode demorar mais de um minuto. Esperar
 * isso é o mesmo que travar a tela — quem está resgatando um presente no
 * celular precisa poder digitar o endereço à mão e seguir. Passados 8s a
 * consulta vira "indisponível" e o preenchimento manual assume.
 */
const TIMEOUT_MS = 8000;

/**
 * @param {string} cep
 * @param {{ signal?: AbortSignal }} options  `signal` cancela a consulta quando
 *        ela deixa de interessar (o CEP mudou, a tela saiu).
 * @returns {Promise<{status: 'ok'|'not_found'|'unavailable', address?: object}>}
 */
export async function lookupCep(cep, { signal } = {}) {
    const d = digits(cep);

    if (d.length !== 8) {
        return { status: 'not_found' };
    }

    // Um controlador próprio por consulta: ele soma o corte por tempo ao
    // cancelamento de quem chamou, sem depender de AbortSignal.any.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
    const abort = () => controller.abort();

    if (signal?.aborted) {
        controller.abort();
    } else {
        signal?.addEventListener('abort', abort, { once: true });
    }

    try {
        const response = await fetch(route('cep.lookup', d), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (response.status === 404) {
            return { status: 'not_found' };
        }

        // Sessão expirada: o backend responde 401 nesta rota (ver
        // shouldRenderJsonWhen em bootstrap/app.php). Recarregar leva ao login,
        // em vez de deixar o operador achando que o serviço caiu.
        if (response.status === 401 || response.status === 419) {
            window.location.reload();

            return { status: 'unavailable' };
        }

        // Inclui o 429 do `throttle:60,1` e o 503 do provedor fora do ar: nos
        // dois casos a saída é a mesma — avisar e liberar o campo à mão.
        if (!response.ok) {
            return { status: 'unavailable' };
        }

        return { status: 'ok', address: await response.json() };
    } catch {
        // Rede do navegador, tempo esgotado ou cancelamento: quem chamou
        // precisa de um desfecho agora, não de uma exceção nem de espera.
        return { status: 'unavailable' };
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abort);
    }
}

/**
 * Aplica o endereço no formulário do Inertia.
 *
 * Não sobrescreve campo que já tem conteúdo: se o operador ajustou o
 * logradouro à mão, uma nova consulta ao mesmo CEP não desfaz o ajuste.
 */
export function applyCepToForm(form, address) {
    ['street', 'district', 'city', 'state'].forEach((field) => {
        if (address[field] && !form[field]) {
            form[field] = address[field];
        }
    });
}
