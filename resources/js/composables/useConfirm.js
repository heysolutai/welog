import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Confirmação de ação crítica — substitui o diálogo nativo do navegador.
 *
 *   const confirm = useConfirm();
 *   const ok = await confirm({ title: 'Descartar as alterações?', variant: 'danger' });
 *   if (!ok) return;
 *
 * Quando a confirmação dispara uma escrita, passe a escrita em `action` e o bag
 * da recusa em `errorBag`:
 *
 *   await confirm({
 *       title: 'Liberar "Kit Natal 2026"?',
 *       description: '128 pessoas receberão o e-mail de resgate agora. Esta ação não pode ser desfeita.',
 *       warning: '12 dessas pessoas ainda não criaram a senha de acesso.',
 *       confirmLabel: 'Liberar e notificar 128 pessoas',
 *       variant: 'danger',
 *       errorBag: 'release',
 *       action: () => form.post(route('projects.release', id), { preserveScroll: true }),
 *   });
 *
 * Com `action`, o diálogo NÃO fecha ao confirmar: ele mesmo dispara a escrita,
 * espera o desfecho e, se o backend recusar, continua aberto mostrando a
 * mensagem daquele bag dentro dele — consequência e erro no mesmo lugar do
 * clique (P1).
 *
 * ── Confirmar de novo depois de uma recusa ──────────────────────────────────
 * A promessa devolvida ao chamador resolve UMA vez só: `await` roda o que vem
 * depois dele uma vez, e ponto. Por isso a intenção mora numa função e não na
 * promessa — cada clique em confirmar chama `action()` de novo, com o mesmo
 * método, a mesma URL e os mesmos callbacks do `useForm`/`useRowAction` que o
 * chamador escreveu. Retentativa é requisição de verdade, não botão morto.
 *
 * A promessa resolve `false` no cancelamento e `true` quando a ação conclui.
 *
 * ── Sempre existe saída ─────────────────────────────────────────────────────
 * Esc, clique fora, X e Cancelar fecham o diálogo mesmo enquanto a resposta não
 * chegou. Fechar só desliga o diálogo da resposta; não aborta a requisição, de
 * propósito: a escrita já saiu do navegador e o servidor vai processá-la de
 * qualquer jeito — abortar no cliente só jogaria fora a resposta que atualiza a
 * tela, deixando a página mentindo até o F5.
 *
 * O estado é único: um host `<UiConfirm />` por layout lê `confirmState`.
 */
const EMPTY = {
    title: '',
    description: null,
    warning: null,
    confirmLabel: 'Confirmar',
    cancelLabel: 'Cancelar',
    variant: 'default', // 'default' | 'danger'
    errorBag: null,
    action: null,
};

export const confirmState = reactive({ ...EMPTY, open: false, pending: false, error: null });

let resolver = null;
let unsubscribe = [];

function settle(value) {
    const resolve = resolver;
    resolver = null;
    resolve?.(value);
}

function release() {
    unsubscribe.forEach((stop) => stop());
    unsubscribe = [];
}

/** Fecha e zera o diálogo sem mexer na promessa. */
function reset() {
    release();
    Object.assign(confirmState, EMPTY, { open: false, pending: false, error: null });
}

/**
 * Espera o desfecho da escrita disparada por `action()` para decidir se fecha
 * ou mostra o erro. Visita GET é ignorada: recarga parcial e polling de outra
 * tela não podem ser confundidos com o desfecho da ação.
 */
function awaitOutcome(bag) {
    release();

    let errors = null;

    unsubscribe = [
        router.on('error', (event) => {
            errors = event.detail.errors ?? {};
        }),
        router.on('finish', (event) => {
            const method = event.detail.visit?.method ?? 'get';
            if (method.toLowerCase() === 'get') return;

            const message = errors?.[bag];

            if (typeof message === 'string' && message) {
                // Recusa: o diálogo continua aberto com o motivo dentro, pronto
                // para chamar `action()` de novo.
                release();
                confirmState.pending = false;
                confirmState.error = message;
            } else {
                reset();
                settle(true);
            }
        }),
    ];
}

export function useConfirm() {
    return function confirm(options = {}) {
        // Um diálogo por vez: o anterior, se existir, conta como recusado.
        settle(false);
        reset();

        Object.assign(confirmState, EMPTY, options, { open: true, pending: false, error: null });

        return new Promise((resolve) => {
            resolver = resolve;
        });
    };
}

/** Usado pelo host `UiConfirm`. */
export function acceptConfirm() {
    if (confirmState.pending) return;

    const { action, errorBag } = confirmState;

    // Sem `action`, quem escreve é o chamador, ao receber o "sim".
    if (!action) {
        settle(true);
        reset();

        return;
    }

    confirmState.error = null;
    confirmState.pending = true;

    // O ouvinte entra antes do disparo: a resposta nunca chega antes dele.
    awaitOutcome(errorBag);
    action();
}

/**
 * Usado pelo host `UiConfirm`: botão cancelar/fechar, Esc, X e clique fora.
 * Vale inclusive durante o `pending` — é a saída que impede o diálogo de
 * prender a interface quando a resposta demora ou nunca vem.
 */
export function cancelConfirm() {
    settle(false); // no-op se o chamador já recebeu o "sim"
    reset();
}

export default useConfirm;
