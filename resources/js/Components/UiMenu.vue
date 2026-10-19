<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';
// Mesmo motor de foco preso, Esc e devolução do foco ao gatilho do modal.
import { useOverlay } from './UiModal.vue';

const props = defineProps({
    /**
     * [{ label, icon?, onSelect, danger?, disabled?, separatorBefore?, href?, heading? }]
     *
     * `href` faz o item virar link de verdade em vez de botão — item que NAVEGA
     * precisa abrir em nova aba pelo clique do meio e aparecer no menu de
     * contexto, coisas que um `<button>` com onSelect não dá.
     * `heading` é rótulo de seção: não é focável nem conta como menuitem.
     */
    items: { type: Array, required: true },
    align: { type: String, default: 'end' }, // end | start
    label: { type: String, default: 'Ações' },
    // `nav` só troca o GATILHO, para ele não sumir na sidebar escura. O painel
    // é teleportado para o body e continua claro de propósito: menu suspenso é
    // superfície flutuante, não pedaço da barra de onde saiu.
    tone: { type: String, default: 'default' }, // default | nav | dark
});

const open = ref(false);
const trigger = ref(null);
const menuId = useId();
const position = ref({ top: 0, left: 0 });
const positioned = ref(false);

const { panel: menu } = useOverlay({
    open: () => open.value,
    close: () => (open.value = false),
    layer: 'menu',
    lockScroll: false, // menu não trava a página; ele se reposiciona
});

// O primeiro item destrutivo ganha separador mesmo sem `separatorBefore`.
const firstDangerIndex = computed(() => props.items.findIndex((item) => item.danger));

function needsSeparator(item, index) {
    return index > 0 && (item.separatorBefore === true || index === firstDangerIndex.value);
}

/**
 * Posição calculada sobre o `<body>`: ancorar dentro da tabela deixaria o menu
 * preso no `overflow-x-auto` do container rolável.
 *
 * Síncrona de propósito: quem chama já garante o painel no DOM (o watch de
 * abertura usa `flush: 'post'`, os listeners de scroll/resize só existem com o
 * menu aberto). Um `nextTick()` aqui dentro jogaria a medição para depois do
 * foco inicial do `useOverlay` — era essa corrida que perdia o foco.
 */
function place() {
    const anchor = trigger.value?.$el ?? trigger.value;
    if (!anchor) return;

    const box = anchor.getBoundingClientRect();
    const size = menu.value?.getBoundingClientRect() ?? { width: 224, height: 0 };
    const margin = 8;

    const below = window.innerHeight - box.bottom;
    const top = below < size.height + margin ? box.top - size.height - 4 : box.bottom + 4;
    const raw = props.align === 'start' ? box.left : box.right - size.width;

    position.value = {
        top: Math.max(margin, Math.min(top, window.innerHeight - size.height - margin)),
        left: Math.max(margin, Math.min(raw, window.innerWidth - size.width - margin)),
    };
    positioned.value = true;
}

function enabledItems() {
    return Array.from(menu.value?.querySelectorAll('[role="menuitem"]:not([disabled])') ?? []);
}

function move(step) {
    const items = enabledItems();
    if (items.length === 0) return;

    const current = items.indexOf(document.activeElement);
    const next = current === -1 ? 0 : (current + step + items.length) % items.length;
    items[next].focus();
}

function onKeydown(event) {
    const keys = { ArrowDown: () => move(1), ArrowUp: () => move(-1) };

    if (keys[event.key]) {
        event.preventDefault();
        keys[event.key]();
        return;
    }

    if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        const items = enabledItems();
        items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
    }
}

function select(item) {
    if (item.disabled) return;
    open.value = false;
    // Fecha antes de agir: assim o foco volta ao gatilho e um drawer aberto
    // pela ação registra o "⋯" como quem o abriu.
    nextTick(() => item.onSelect?.());
}

function onPointerDown(event) {
    const anchor = trigger.value?.$el ?? trigger.value;
    if (menu.value?.contains(event.target) || anchor?.contains(event.target)) return;
    open.value = false;
}

/**
 * `flush: 'post'` é o que ordena a abertura: a fila de efeitos pós-render roda
 * depois do DOM atualizado (o painel já existe, `menu` já está preenchido) e
 * antes das continuações de `nextTick()`. Assim o menu já está posicionado e
 * visível quando o `useOverlay` — cujo watch é `pre` e adia o foco com
 * `await nextTick()` — coloca o foco no primeiro item.
 */
watch(
    open,
    (value) => {
        if (!value) {
            positioned.value = false;
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
            document.removeEventListener('pointerdown', onPointerDown, true);
            return;
        }

        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        document.addEventListener('pointerdown', onPointerDown, true);
    },
    { flush: 'post' },
);

onBeforeUnmount(() => {
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('resize', place);
    document.removeEventListener('pointerdown', onPointerDown, true);
});
</script>

<template>
    <UiButton
        ref="trigger"
        :variant="{ nav: 'ghost-nav', dark: 'ghost-dark' }[tone] ?? 'ghost'"
        icon="more"
        icon-only
        :label="label"
        aria-haspopup="menu"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="open ? menuId : null"
        @click="open = !open"
    />

    <Teleport to="body">
        <div
            v-if="open"
            :id="menuId"
            ref="menu"
            role="menu"
            tabindex="-1"
            :aria-label="label"
            :style="{ top: `${position.top}px`, left: `${position.left}px` }"
            :class="[
                'animate-overlay-in fixed z-50 min-w-56 rounded-control border border-line bg-surface py-1 shadow-overlay',
                // Enquanto se mede a posição o painel some, mas segue no fluxo
                // e FOCÁVEL: opacidade zerada preserva o alcance de `focus()`,
                // ao contrário de esconder o elemento pela visibilidade — era
                // assim que o foco inicial se perdia no vazio.
                !positioned && 'opacity-0 pointer-events-none',
            ]"
            @keydown="onKeydown"
        >
            <template v-for="(item, index) in items" :key="item.heading ?? item.label">
                <div v-if="needsSeparator(item, index)" role="separator" class="my-1 border-t border-line" />

                <p
                    v-if="item.heading"
                    role="presentation"
                    class="px-3 pt-2 pb-1 text-[10px] font-semibold tracking-wide text-ink-subtle uppercase"
                >
                    {{ item.heading }}
                </p>

                <component
                    :is="item.href ? Link : 'button'"
                    v-else
                    v-bind="item.href ? { href: item.href } : { type: 'button', disabled: item.disabled }"
                    role="menuitem"
                    :class="[
                        'flex min-h-10 w-full items-center gap-2 px-3 py-2 text-left text-sm',
                        item.disabled
                            ? 'cursor-not-allowed text-ink-muted'
                            : item.danger
                              ? 'text-danger hover:bg-danger-soft'
                              : 'text-ink hover:bg-surface-muted',
                    ]"
                    @click="select(item)"
                >
                    <UiIcon v-if="item.icon" :name="item.icon" :size="16" class="text-ink-muted" />
                    {{ item.label }}
                </component>
            </template>
        </div>
    </Teleport>
</template>
