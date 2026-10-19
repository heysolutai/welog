import { ref } from 'vue';

/**
 * Fila de notificações. Vive no módulo (e não dentro do `Toaster`) para
 * sobreviver à troca de layout: uma navegação que remonta o layout não pode
 * engolir o aviso que acabou de ser enfileirado.
 *
 * Os temporizadores NÃO moram aqui — quem os cria é o `Toaster`, que os
 * limpa no `onUnmounted`.
 */
const MAX_VISIBLE = 3;

export const toasts = ref([]);

let sequence = 0;

export function pushToast(variant, message) {
    const text = typeof message === 'string' ? message.trim() : '';
    if (!text) return null;

    const id = ++sequence;
    // Fila com limite: o mais antigo sai quando chega o quarto.
    toasts.value = [...toasts.value, { id, variant, message: text }].slice(-MAX_VISIBLE);

    return id;
}

export function dismissToast(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

/**
 * O backend devolve sucesso e fracasso pelo mesmo `flash.status` — reprocesso
 * que não destravou a linha chegava aqui pintado de verde com ícone de check.
 */
export function classifyStatus(message) {
    return /continua bloqueada|ainda ausente|não foi possível/i.test(message) ? 'warning' : 'success';
}

export function useToast() {
    return {
        success: (message) => pushToast('success', message),
        warning: (message) => pushToast('warning', message),
        error: (message) => pushToast('error', message),
    };
}

export default useToast;
