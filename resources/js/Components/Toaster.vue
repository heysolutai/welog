<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';
import { classifyStatus, dismissToast, pushToast, toasts } from '../composables/useToast';

const DURATION = 6000;

const page = usePage();
const paused = ref(false);
const timers = new Map();

const variants = {
    success: { box: 'border-success-line', icon: 'text-success', name: 'check' },
    warning: { box: 'border-warning-line', icon: 'text-warning', name: 'alert' },
    error: { box: 'border-danger-line', icon: 'text-danger', name: 'alert' },
};

function schedule(id, ms = DURATION) {
    const timer = timers.get(id) ?? {};
    timer.remaining = ms;
    timer.startedAt = Date.now();
    timer.handle = setTimeout(() => {
        timers.delete(id);
        dismissToast(id);
    }, ms);
    timers.set(id, timer);
}

function hold(id) {
    const timer = timers.get(id);
    if (!timer?.handle) return;

    clearTimeout(timer.handle);
    timer.handle = null;
    timer.remaining = Math.max(1000, timer.remaining - (Date.now() - timer.startedAt));
}

function pause() {
    paused.value = true;
    toasts.value.forEach((toast) => hold(toast.id));
}

function resume() {
    paused.value = false;
    toasts.value.forEach((toast) => {
        const timer = timers.get(toast.id);
        if (timer && !timer.handle) schedule(toast.id, timer.remaining);
    });
}

function close(id) {
    hold(id);
    timers.delete(id);
    dismissToast(id);
}

// Cada aviso novo ganha o seu próprio temporizador; os órfãos (fechados à mão
// ou expulsos pelo limite da fila) somem junto.
watch(
    toasts,
    (list) => {
        const alive = new Set(list.map((toast) => toast.id));

        timers.forEach((timer, id) => {
            if (alive.has(id)) return;
            clearTimeout(timer.handle);
            timers.delete(id);
        });

        list.forEach((toast) => {
            if (timers.has(toast.id)) return;
            if (paused.value) timers.set(toast.id, { handle: null, remaining: DURATION, startedAt: Date.now() });
            else schedule(toast.id);
        });
    },
    { immediate: true },
);

/**
 * Enfileira por evento de navegação, não por mudança de valor: com `watch`
 * sobre `flash.status`, salvar duas vezes seguidas com a mesma mensagem
 * mostrava o aviso só na primeira vez.
 */
const stopListening = router.on('success', (event) => {
    // Recarga parcial: o flash que vem na página é o da visita anterior.
    if (parciais.delete(event.detail.visitId)) return;

    const status = event.detail.page.props.flash?.status;
    if (status) pushToast(classifyStatus(status), status);
});

/*
 * Recargas parciais (`only`) devolvem só as props pedidas, e o Inertia mantém
 * o resto da página como estava — inclusive o flash. A tela do Projeto relê o
 * progresso da importação a cada três segundos, e o "1500 linhas recebidas"
 * reaparecia a cada volta. Marcadas na saída, pelo id da visita, elas não
 * reenfileiram o aviso.
 */
const parciais = new Set();

const stopStart = router.on('start', (event) => {
    const { visit } = event.detail;

    if (visit.only?.length && !visit.only.includes('flash')) parciais.add(visit.id);
});

// `finish` vem depois do `success`, e também na visita cancelada ou com erro,
// que nunca chegam ao `success`: é aqui que a marca sai de vez.
const stopFinish = router.on('finish', (event) => parciais.delete(event.detail.visit.id));

// Carga completa da página (redirecionamento fora do Inertia) não dispara
// `success`. O controle é de módulo: remontar o layout não repete o aviso.
onMounted(() => {
    if (initialFlashConsumed) return;
    initialFlashConsumed = true;

    const status = page.props.flash?.status;
    if (status) pushToast(classifyStatus(status), status);
});

onUnmounted(() => {
    stopListening();
    stopStart();
    stopFinish();
    timers.forEach((timer) => clearTimeout(timer.handle));
    timers.clear();
});
</script>

<script>
// Marcador de módulo: sobrevive à remontagem do layout, então trocar de
// layout logo após um flash não repete o aviso.
let initialFlashConsumed = false;
</script>

<template>
    <div
        role="region"
        aria-label="Notificações"
        class="pointer-events-none fixed inset-x-0 bottom-4 z-60 flex flex-col items-center gap-2 px-4 lg:inset-x-auto lg:top-4 lg:right-6 lg:bottom-auto lg:items-end"
    >
        <div aria-live="polite" aria-atomic="true" class="flex w-full flex-col items-center gap-2 lg:items-end">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :class="[
                    'animate-toast-in pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-control border bg-surface px-4 py-3 shadow-overlay',
                    variants[toast.variant]?.box ?? 'border-line',
                ]"
                @pointerenter="pause"
                @pointerleave="resume"
                @focusin="pause"
                @focusout="resume"
            >
                <UiIcon
                    :name="variants[toast.variant]?.name ?? 'info'"
                    :size="18"
                    :class="['mt-0.5', variants[toast.variant]?.icon ?? 'text-ink-muted']"
                />

                <p class="min-w-0 flex-1 text-sm text-ink">{{ toast.message }}</p>

                <UiButton
                    variant="ghost"
                    size="sm"
                    icon="close"
                    icon-only
                    label="Fechar notificação"
                    class="-mt-0.5 -mr-2 shrink-0"
                    @click="close(toast.id)"
                />
            </div>
        </div>
    </div>
</template>
