<script>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Motor das sobreposições — usado por `UiModal`, `UiDrawer` e `UiMenu`.
 *
 * Mora aqui, e não num módulo próprio, porque a estrutura de arquivos alvo
 * (§5) não prevê um arquivo para ele. Resolve, de uma vez só, o que cada
 * sobreposição precisaria repetir:
 *
 * - foco inicial no painel (respeita `[data-autofocus]`);
 * - Tab e Shift+Tab circulando dentro do painel, foco devolvido ao gatilho;
 * - Esc fechando só quando a sobreposição é dispensável;
 * - trava de rolagem do `<body>` com contagem — dois painéis em sequência (ou
 *   empilhados) não deixam a página travada nem destravam cedo demais;
 * - um painel por vez: abrir um `panel` fecha os outros `panel`. As camadas
 *   `dialog` (confirmação) e `menu` empilham por cima sem fechar nada.
 */
const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const stack = [];
let scrollLocks = 0;
let previousOverflow = '';

function lockScroll() {
    if (scrollLocks === 0) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
    scrollLocks += 1;
}

function unlockScroll() {
    scrollLocks = Math.max(0, scrollLocks - 1);
    if (scrollLocks === 0) document.body.style.overflow = previousOverflow;
}

export function useOverlay(options) {
    const panel = ref(null);
    const entry = { layer: options.layer ?? 'panel', close: () => options.close(), locked: false };
    let opener = null;

    const isTop = () => stack[stack.length - 1] === entry;
    const dismissible = () => options.dismissible?.() ?? true;

    function focusables() {
        if (!panel.value) return [];

        return Array.from(panel.value.querySelectorAll(FOCUSABLE)).filter(
            (el) => !el.hasAttribute('disabled') && el.getClientRects().length > 0,
        );
    }

    function focusFirst() {
        const preferred = panel.value?.querySelector('[data-autofocus]:not([disabled])');
        (preferred ?? focusables()[0] ?? panel.value)?.focus();
    }

    function onKeydown(event) {
        if (!isTop() || !panel.value) return;

        if (event.key === 'Escape') {
            if (!dismissible()) return;
            event.preventDefault();
            event.stopPropagation();
            options.close();
            return;
        }

        if (event.key !== 'Tab') return;

        const items = focusables();
        if (items.length === 0) {
            event.preventDefault();
            panel.value.focus();
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];
        const inside = panel.value.contains(document.activeElement);

        if (event.shiftKey && (!inside || document.activeElement === first)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (!inside || document.activeElement === last)) {
            event.preventDefault();
            first.focus();
        }
    }

    // Foco que escapa por clique ou por atalho do navegador volta para dentro.
    // O menu fica de fora: o gatilho dele mora do lado de fora do painel, e
    // puxar o foco de volta atrapalharia o clique seguinte na página.
    function onFocusIn(event) {
        if (entry.layer === 'menu' || !isTop() || !panel.value) return;
        if (panel.value.contains(event.target)) return;
        focusFirst();
    }

    async function activate() {
        opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;

        if (entry.layer === 'panel') {
            stack.filter((other) => other.layer === 'panel').forEach((other) => other.close());
        }

        stack.push(entry);

        if (options.lockScroll !== false) {
            lockScroll();
            entry.locked = true;
        }

        document.addEventListener('keydown', onKeydown, true);
        document.addEventListener('focusin', onFocusIn, true);

        await nextTick();
        focusFirst();
    }

    function deactivate() {
        const index = stack.indexOf(entry);
        if (index === -1) return;

        stack.splice(index, 1);
        document.removeEventListener('keydown', onKeydown, true);
        document.removeEventListener('focusin', onFocusIn, true);

        if (entry.locked) {
            unlockScroll();
            entry.locked = false;
        }

        if (opener && document.contains(opener)) opener.focus();
        opener = null;
    }

    watch(() => options.open(), (open) => (open ? activate() : deactivate()), { immediate: true });
    onBeforeUnmount(deactivate);

    return { panel, focusables, isTop };
}
</script>

<script setup>
import { Comment, Fragment, Text, computed, useId, useSlots } from 'vue';
import UiButton from './UiButton.vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    description: { type: String, default: null },
    size: { type: String, default: 'md' }, // sm (400px) | md (560px) | lg (720px) | xl (1408px)
    dismissible: { type: Boolean, default: true },
    // Interno: `dialog` é a camada da confirmação, que empilha sobre um painel.
    layer: { type: String, default: 'panel' },
});

const emit = defineEmits(['update:open', 'close']);

const titleId = useId();
const descriptionId = useId();

const sizes = {
    sm: 'max-w-[25rem]',
    md: 'max-w-[35rem]',
    lg: 'max-w-[45rem]',
    // Tabela larga (a prévia da planilha, com uma coluna por item): em 720px
    // ela rolava de lado com a tela do desktop sobrando dos dois lados.
    xl: 'max-w-[88rem]',
};

function requestClose() {
    if (!props.dismissible) return;
    emit('update:open', false);
    emit('close');
}

const { panel } = useOverlay({
    open: () => props.open,
    close: requestClose,
    dismissible: () => props.dismissible,
    layer: props.layer,
});

const zIndex = computed(() => (props.layer === 'panel' ? 'z-40' : 'z-50'));

// Um diálogo cujo corpo é só `v-if` (a confirmação, quando não há aviso nem
// erro) não pode render uma faixa branca vazia entre título e ações.
const slots = useSlots();

function hasContent(nodes) {
    return (nodes ?? []).some((node) => {
        if (node.type === Comment) return false;
        if (node.type === Fragment) return hasContent(node.children);
        if (node.type === Text) return String(node.children ?? '').trim() !== '';
        return true;
    });
}

const showBody = computed(() => hasContent(slots.default?.()));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" :class="['fixed inset-0 flex items-center justify-center p-4', zIndex]">
            <div class="animate-overlay-in absolute inset-0 bg-ink/40" aria-hidden="true" @click="requestClose" />

            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                tabindex="-1"
                :aria-labelledby="titleId"
                :aria-describedby="description ? descriptionId : null"
                :class="[
                    'animate-overlay-in relative flex max-h-full w-full flex-col rounded-control bg-surface shadow-overlay',
                    sizes[size] ?? sizes.md,
                ]"
            >
                <header class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                    <div class="min-w-0">
                        <h2 :id="titleId" class="text-sm font-semibold text-ink">{{ title }}</h2>
                        <p v-if="description" :id="descriptionId" class="mt-1 text-sm text-ink-muted">
                            {{ description }}
                        </p>
                    </div>

                    <UiButton
                        v-if="dismissible"
                        variant="ghost"
                        size="sm"
                        icon="close"
                        icon-only
                        label="Fechar"
                        class="-mt-1 -mr-2 shrink-0"
                        @click="requestClose"
                    />
                </header>

                <div v-if="showBody" class="scrollbar-slim min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4">
                    <slot />
                </div>

                <!-- Sem corpo, a borda do cabeçalho já separa: duas seguidas
                     viravam uma linha dupla. -->
                <footer
                    v-if="$slots.footer"
                    :class="['flex flex-wrap items-center gap-2 px-5 py-4', showBody && 'border-t border-line']"
                >
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
