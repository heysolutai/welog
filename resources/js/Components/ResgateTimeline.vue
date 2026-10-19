<script setup>
import { computed } from 'vue';
import UiIcon from './UiIcon.vue';
import { formatDate } from '../lib/format';

/**
 * Onde ESTE resgate da Reconhece está: do webhook até a porta da pessoa.
 *
 * É a esteira da página de Projeto aplicada a um resgate só, com as etapas do
 * Fluxo B — quem lê as duas telas reconhece a figura sem aprender outra.
 * Mesmos três estados (passado com ✓, atual com anel, futuro esmaecido) e um
 * quarto, o bloqueio: o resgate parado antes do pedido na Omie fica vermelho
 * NA etapa em que travou, que é onde a ação está.
 */
const props = defineProps({
    status: { type: String, required: true },
    // Datas conhecidas de cada etapa, escritas sob o marcador quando houver.
    dates: { type: Object, default: () => ({}) },
});

const ETAPAS = [
    { key: 'received', label: 'Recebido', icon: 'gift' },
    { key: 'order', label: 'Pedido na Omie', icon: 'fields' },
    { key: 'invoice', label: 'NF emitida', icon: 'info' },
    { key: 'transport', label: 'Em transporte', icon: 'truck' },
    { key: 'delivered', label: 'Entregue', icon: 'stageAssembly' },
];

/** Status da linha → índice da etapa alcançada. */
const INDICE = {
    pending: 0,
    blocked_structural: 1,
    blocked_product: 1,
    order_created: 1,
    invoiced: 2,
    dispatched: 3,
    delivered: 4,
};

const bloqueado = computed(() => props.status.startsWith('blocked_'));
const atual = computed(() => INDICE[props.status] ?? 0);

const etapas = computed(() =>
    ETAPAS.map((etapa, index) => ({
        ...etapa,
        date: props.dates[etapa.key] ?? null,
        state: estadoDe(index),
    })),
);

function estadoDe(index) {
    if (index < atual.value) return 'past';
    if (index > atual.value) return 'ahead';
    if (bloqueado.value) return 'blocked';

    // Entregue é o fim: não há o que esperar, então já nasce concluída.
    return props.status === 'delivered' ? 'past' : 'current';
}

const resumo = computed(() => {
    const etapa = ETAPAS[atual.value];

    return bloqueado.value ? `Bloqueado antes de: ${etapa.label}` : etapa.label;
});
</script>

<template>
    <div>
        <p class="sr-only">Etapa atual: {{ resumo }}.</p>

        <ol class="scrollbar-slim grid grid-flow-col auto-cols-[minmax(7.5rem,1fr)] overflow-x-auto pt-1.5 pb-1">
            <li
                v-for="(etapa, i) in etapas"
                :key="etapa.key"
                class="relative flex flex-col items-center gap-1.5 px-1"
                :aria-current="etapa.state === 'current' || etapa.state === 'blocked' ? 'step' : null"
            >
                <!-- Trilho de centro a centro, aceso até a etapa alcançada. -->
                <span
                    v-if="i > 0"
                    aria-hidden="true"
                    :class="[
                        'absolute top-4 right-1/2 left-0 h-0.5 -translate-y-1/2',
                        etapa.state === 'ahead' ? 'bg-line' : 'bg-trail',
                    ]"
                />
                <span
                    v-if="i < etapas.length - 1"
                    aria-hidden="true"
                    :class="[
                        'absolute top-4 right-0 left-1/2 h-0.5 -translate-y-1/2',
                        etapa.state === 'past' ? 'bg-trail' : 'bg-line',
                    ]"
                />

                <span
                    :class="[
                        'relative z-10 flex size-8 items-center justify-center rounded-full border-2 transition-colors',
                        etapa.state === 'past' && 'border-trail bg-trail text-ink-inverse shadow-trail',
                        etapa.state === 'current' && 'border-brand-600 bg-brand-50 text-brand-700',
                        etapa.state === 'blocked' && 'border-danger bg-danger-soft text-danger',
                        etapa.state === 'ahead' && 'border-line bg-surface text-ink-subtle',
                    ]"
                >
                    <UiIcon
                        :name="etapa.state === 'past' ? 'check' : etapa.state === 'blocked' ? 'alert' : etapa.icon"
                        :size="15"
                        aria-hidden="true"
                    />
                </span>

                <span
                    :class="[
                        'text-center text-xs leading-tight',
                        etapa.state === 'blocked' && 'font-semibold text-danger',
                        etapa.state === 'current' && 'font-semibold text-ink',
                        (etapa.state === 'past' || etapa.state === 'ahead') && 'text-ink-muted',
                    ]"
                >
                    {{ etapa.state === 'blocked' ? 'Bloqueado' : etapa.label }}
                </span>

                <span
                    v-if="etapa.date && (etapa.state === 'past' || etapa.state === 'current')"
                    class="text-center text-[0.6875rem] text-ink-subtle tabular-nums"
                >
                    {{ formatDate(etapa.date, true) }}
                </span>
            </li>
        </ol>
    </div>
</template>
