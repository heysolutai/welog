<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
// Mesmo motor do UiMenu: Esc fecha, foco preso no painel e devolvido ao sino.
import { useOverlay } from './UiModal.vue';

/**
 * O sino do cabeçalho como painel de notificações de verdade (UAT: "o ícone
 * abre a aba Projetos, não as notificações").
 *
 * Não é um sistema de notificação: o que ele lista são os Projetos REAIS
 * parados aguardando liberação (prop `pendingProjects`, compartilhada pelo
 * HandleInertiaRequests), cada um com link direto. O clique deixa de teleportar
 * o operador para uma lista genérica e passa a mostrar O QUE está pendente.
 */
const props = defineProps({
    /** Total de pendências (Projetos + linhas bloqueadas) — o número do selo. */
    count: { type: Number, default: 0 },
    /** [{ id, name, omie_order_number }] — no máximo 6 (ver middleware). */
    projects: { type: Array, default: () => [] },
    /** Destino do "Ver todos": a lista de Projetos já filtrada. */
    allHref: { type: String, required: true },
});

// Dois nós raiz (gatilho + Teleport) desligam a herança de atributos: sem o
// v-bind explícito no botão, a `class` passada pelo layout cairia no vazio.
defineOptions({ inheritAttrs: false });

const open = ref(false);
const trigger = ref(null);
const panelId = useId();
const position = ref({ top: 0, left: 0 });
const positioned = ref(false);

const { panel } = useOverlay({
    open: () => open.value,
    close: () => (open.value = false),
    layer: 'menu',
    lockScroll: false,
});

/**
 * Posicionamento idêntico ao UiMenu: medido sobre o body, alinhado à direita
 * do sino e reposicionado em scroll/resize enquanto aberto.
 */
function place() {
    const anchor = trigger.value;
    if (!anchor) return;

    const box = anchor.getBoundingClientRect();
    const size = panel.value?.getBoundingClientRect() ?? { width: 320, height: 0 };
    const margin = 8;

    const below = window.innerHeight - box.bottom;
    const top = below < size.height + margin ? box.top - size.height - 4 : box.bottom + 4;
    const raw = box.right - size.width;

    position.value = {
        top: Math.max(margin, Math.min(top, window.innerHeight - size.height - margin)),
        left: Math.max(margin, Math.min(raw, window.innerWidth - size.width - margin)),
    };
    positioned.value = true;
}

function items() {
    return Array.from(panel.value?.querySelectorAll('a[href]') ?? []);
}

function move(step) {
    const links = items();
    if (links.length === 0) return;

    const current = links.indexOf(document.activeElement);
    const next = current === -1 ? 0 : (current + step + links.length) % links.length;
    links[next].focus();
}

function onKeydown(event) {
    const keys = { ArrowDown: () => move(1), ArrowUp: () => move(-1) };

    if (keys[event.key]) {
        event.preventDefault();
        keys[event.key]();
    }
}

function onPointerDown(event) {
    if (panel.value?.contains(event.target) || trigger.value?.contains(event.target)) return;
    open.value = false;
}

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

const triggerLabel = () => (props.count > 0
    ? `Notificações: ${props.count} ${props.count === 1 ? 'item precisa' : 'itens precisam'} da sua atenção`
    : 'Notificações');
</script>

<template>
    <button
        ref="trigger"
        type="button"
        v-bind="$attrs"
        class="relative flex size-10 shrink-0 items-center justify-center rounded-control text-ink-muted transition-colors hover:bg-surface-muted hover:text-ink"
        :aria-label="triggerLabel()"
        aria-haspopup="menu"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="open ? panelId : null"
        @click="open = !open"
    >
        <UiIcon name="bell" :size="19" aria-hidden="true" />
        <span
            v-if="count > 0"
            aria-hidden="true"
            class="absolute top-1.5 right-1.5 flex min-w-4 items-center justify-center rounded-full bg-danger-solid px-1 text-[10px] leading-4 font-bold text-ink-inverse"
        >
            {{ count > 9 ? '9+' : count }}
        </span>
    </button>

    <Teleport to="body">
        <div
            v-if="open"
            :id="panelId"
            ref="panel"
            role="menu"
            tabindex="-1"
            aria-label="Notificações"
            :style="{ top: `${position.top}px`, left: `${position.left}px` }"
            :class="[
                'animate-overlay-in fixed z-50 w-80 max-w-[calc(100vw-1rem)] rounded-control border border-line bg-surface shadow-overlay',
                !positioned && 'opacity-0 pointer-events-none',
            ]"
            @keydown="onKeydown"
        >
            <p
                role="presentation"
                class="border-b border-line px-4 pt-3 pb-2 text-[10px] font-semibold tracking-wide text-ink-subtle uppercase"
            >
                Aguardando liberação
            </p>

            <ul v-if="projects.length" class="max-h-80 overflow-y-auto py-1">
                <li v-for="project in projects" :key="project.id">
                    <Link
                        :href="route('projects.show', project.id)"
                        role="menuitem"
                        class="flex items-start gap-2.5 px-4 py-2.5 hover:bg-surface-muted"
                        @click="open = false"
                    >
                        <span
                            aria-hidden="true"
                            class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-control bg-warning-soft text-warning"
                        >
                            <UiIcon name="clock" :size="13" />
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-ink">{{ project.name }}</span>
                            <span class="block text-xs text-ink-muted">
                                <template v-if="project.omie_order_number">Pedido {{ project.omie_order_number }} · </template>estoque disponível, aguardando liberação
                            </span>
                        </span>
                    </Link>
                </li>
            </ul>

            <!-- Sem pendência não é erro: é o estado que a operação persegue. -->
            <div v-else class="flex flex-col items-center gap-2 px-4 py-6 text-center">
                <span class="flex size-9 items-center justify-center rounded-full bg-success-soft text-success">
                    <UiIcon name="check" :size="18" aria-hidden="true" />
                </span>
                <p class="text-sm font-medium text-ink">Tudo em dia por aqui!</p>
                <p class="text-xs text-ink-muted">Nenhum Projeto aguardando liberação no momento.</p>
            </div>

            <div class="border-t border-line p-1">
                <Link
                    :href="allHref"
                    role="menuitem"
                    class="flex min-h-9 items-center justify-center gap-1.5 rounded-control px-3 text-sm font-medium text-brand-700 hover:bg-brand-50"
                    @click="open = false"
                >
                    Ver todos
                    <UiIcon name="arrowRight" :size="14" aria-hidden="true" />
                </Link>
            </div>
        </div>
    </Teleport>
</template>
