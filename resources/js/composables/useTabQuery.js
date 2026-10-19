import { ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Aba ativa sincronizada com a querystring:
 *
 *   const aba = useTabQuery('aba', 'clientes', { allowed: ['clientes', 'resgates', 'kits'] });
 *
 * A troca de aba usa a visita do lado do cliente do Inertia (`router.replace`):
 * só a URL e o histórico mudam, com `preserveState`/`preserveScroll` — nenhuma
 * requisição, nenhuma prop recarregada. É o que faz a aba sobreviver sem o
 * `:key="$page.url"` e o que torna o link com `?aba=` compartilhável.
 */
function partsOf(url) {
    const [beforeHash, hash = ''] = String(url ?? '/').split('#');
    const [path, query = ''] = beforeHash.split('?');

    return { path, params: new URLSearchParams(query), hash: hash ? `#${hash}` : '' };
}

export function useTabQuery(param = 'aba', fallback = null, options = {}) {
    const page = usePage();
    const allowed = options.allowed?.map((tab) => (typeof tab === 'string' ? tab : tab.key)) ?? null;

    function normalize(value) {
        if (!value) return fallback;
        if (allowed && !allowed.includes(value)) return fallback;
        return value;
    }

    function fromUrl() {
        return normalize(partsOf(page.url).params.get(param));
    }

    const current = ref(fromUrl());

    // A URL manda: link direto, voltar e avançar do navegador trocam a aba.
    watch(
        () => page.url,
        () => {
            const value = fromUrl();
            if (value !== current.value) current.value = value;
        },
    );

    watch(current, (value) => {
        const { path, params, hash } = partsOf(page.url);

        if (!value || value === fallback) params.delete(param);
        else params.set(param, value);

        const query = params.toString();
        const next = `${path}${query ? `?${query}` : ''}${hash}`;
        if (next === page.url) return;

        router.replace({ url: next, preserveState: true, preserveScroll: true });
    });

    return current;
}

export default useTabQuery;
