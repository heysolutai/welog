import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Ação disparada de dentro de uma linha de tabela, com estado POR LINHA.
 *
 *   const { run, pending, error } = useRowAction();
 *   run(row.id, route('imports.rows.reprocess', row.id), { errorKey: 'row' });
 *
 * Regra do sistema: toda ação por linha passa por aqui. `form.processing`
 * compartilhado acende o botão de todas as linhas ao mesmo tempo, e o erro
 * de uma linha acaba no topo da página, longe de onde foi disparado (P1).
 */
export function useRowAction() {
    const rows = reactive({});

    function entry(id) {
        if (!rows[id]) rows[id] = { pending: false, error: null };
        return rows[id];
    }

    function pending(id) {
        return rows[id]?.pending === true;
    }

    function error(id) {
        return rows[id]?.error ?? null;
    }

    function clearError(id) {
        if (rows[id]) rows[id].error = null;
    }

    function reset() {
        Object.keys(rows).forEach((key) => delete rows[key]);
    }

    function messageOf(errors, key) {
        if (key && typeof errors?.[key] === 'string') return errors[key];
        const first = Object.values(errors ?? {}).find((value) => typeof value === 'string' && value);
        return first ?? null;
    }

    /**
     * @param {String|Number} id      identificador da linha
     * @param {String} url            destino da escrita
     * @param {Object} options        { method, data, errorKey, onSuccess, onError, onFinish, ...opções de visita }
     */
    function run(id, url, options = {}) {
        if (pending(id)) return; // reentrada do mesmo id bloqueada

        const { method = 'post', data = {}, errorKey = null, onSuccess, onError, onFinish, ...visit } = options;
        const row = entry(id);
        row.pending = true;
        row.error = null;

        router.visit(url, {
            method,
            data,
            preserveScroll: true,
            preserveState: true,
            ...visit,
            onSuccess: (page) => onSuccess?.(page),
            onError: (errors) => {
                row.error = messageOf(errors, errorKey);
                onError?.(errors);
            },
            onFinish: (event) => {
                row.pending = false;
                onFinish?.(event);
            },
        });
    }

    return { rows, run, pending, error, clearError, reset };
}

export default useRowAction;
