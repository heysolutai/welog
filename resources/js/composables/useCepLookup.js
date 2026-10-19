import { computed, onScopeDispose, ref, watch } from 'vue';
import { applyCepToForm, formatCep, isCompleteCep, lookupCep } from '../lib/cep';

/**
 * Máquina de estados da busca de CEP, compartilhada pelo portal e pelo
 * cadastro de empresa. `lib/cep.js` continua sendo quem fala com a rota.
 *
 * Estados: 'idle' (ocioso) · 'searching' (buscando) · 'found' (encontrado) ·
 * 'not_found' (não encontrado) · 'unavailable' (serviço fora do ar).
 *
 * Não encontrado e indisponível são desfechos distintos de propósito: tratar
 * os dois como a mesma coisa faz a pessoa acreditar que digitou errado quando
 * o provedor apenas caiu.
 */

const MESSAGES = {
    not_found: 'CEP não encontrado. Confira ou preencha à mão.',
    unavailable: 'Serviço de CEP indisponível. Preencha à mão.',
};

/**
 * @param {object} form  useForm do Inertia (ou objeto reativo) com zip, street,
 *                       district, city e state.
 * @param {{ field?: string }} options  nome do campo do CEP, quando não for `zip`.
 */
export function useCepLookup(form, { field = 'zip' } = {}) {
    const state = ref('idle');
    const address = ref(null);

    // Último CEP consultado, com qualquer desfecho — a rota tem throttle:60,1 e
    // o `blur` dispara a cada saída do campo, mesmo sem edição nenhuma.
    let lastQuery = null;
    // Consulta em andamento: { query, promise, controller }.
    let inFlight = null;

    const loading = computed(() => state.value === 'searching');
    const failed = computed(() => state.value === 'not_found' || state.value === 'unavailable');
    const found = computed(() => state.value === 'found');
    const error = computed(() => (failed.value ? MESSAGES[state.value] : null));

    /** Mensagem positiva/neutra do campo. O desfecho ruim vai em `error`. */
    const hint = computed(() => {
        if (state.value === 'searching') return 'buscando…';
        if (state.value !== 'found' || !address.value) return null;

        const place = [address.value.city, address.value.state].filter(Boolean).join('/');

        return address.value.district ? `${place}, ${address.value.district}` : place || null;
    });

    /** Aborta a consulta em andamento: a resposta dela já não interessa. */
    function cancel() {
        inFlight?.controller.abort();
        inFlight = null;
    }

    function reset() {
        cancel();
        state.value = 'idle';
        address.value = null;
    }

    async function run(query, controller) {
        state.value = 'searching';

        const { status, address: result } = await lookupCep(query, { signal: controller.signal });

        // Cancelada no meio (CEP editado, consulta nova, tela desmontada) ou o
        // campo já mostra outro CEP: o desfecho é de algo que saiu de jogo.
        if (controller.signal.aborted || form[field] !== query) return;

        inFlight = null;
        lastQuery = query;

        if (status === 'ok') {
            applyCepToForm(form, result);
            address.value = result;
            state.value = 'found';

            return;
        }

        address.value = null;
        state.value = status === 'not_found' ? 'not_found' : 'unavailable';
    }

    /**
     * @param {{ force?: boolean }} options  `force` refaz a consulta mesmo com
     *        desfecho já conhecido para o mesmo CEP — é o "tentar de novo" que
     *        a pessoa aciona quando o serviço estava fora do ar.
     * @returns {Promise<void>} resolve quando o desfecho está em `state`.
     */
    async function lookup({ force = false } = {}) {
        form[field] = formatCep(form[field]);

        const query = form[field];

        if (!isCompleteCep(query)) {
            reset();

            return;
        }

        // Tocar no botão tira o foco do campo e dispara o `blur` no mesmo
        // instante do clique: quem chega depois espera a consulta que já está
        // no ar, em vez de abrir uma segunda.
        if (inFlight?.query === query) return inFlight.promise;

        // Desfecho já conhecido para este CEP — inclusive erro. Insistir
        // sozinho num serviço fora do ar só prende a pessoa esperando; para
        // tentar de novo existe `force`, e editar o CEP zera o desfecho.
        if (!force && query === lastQuery && state.value !== 'idle') return;

        cancel();

        const controller = new AbortController();
        const promise = run(query, controller);

        inFlight = { query, promise, controller };

        return promise;
    }

    // Editar o CEP invalida o desfecho anterior — a dica não pode ficar
    // dizendo "Curitiba/PR" enquanto a pessoa digita outro CEP — e cancela a
    // consulta em andamento, que já é de outro CEP.
    watch(
        () => form[field],
        (value) => {
            // O próprio `lookup` normaliza o campo (80010000 → 80010-000): essa
            // mudança é da consulta que está no ar, não uma edição.
            if (inFlight?.query === value) return;

            if (state.value !== 'idle') reset();
        },
    );

    // Sair da tela com uma consulta pendurada deixaria a conexão aberta e um
    // `state` sendo escrito depois do desmonte.
    onScopeDispose(cancel, true);

    return { state, loading, found, failed, error, hint, address, lookup, reset };
}

export default useCepLookup;
