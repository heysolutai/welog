<script setup>
import { computed } from 'vue';
import UiBadge from './UiBadge.vue';
import {
    pipelineLabels,
    redemptionStatusLabels,
    importRowStatusLabels,
    importStatusLabels,
    projectReleaseLabels,
} from '../lib/format';

const props = defineProps({
    status: { type: String, required: true },
    kind: { type: String, default: 'pipeline' }, // pipeline | redemption | importRow | import | projectRelease
});

const labels = {
    pipeline: pipelineLabels,
    redemption: redemptionStatusLabels,
    importRow: importRowStatusLabels,
    import: importStatusLabels,
    projectRelease: projectReleaseLabels,
};

// Variante por kind: a mesma chave significa coisas diferentes em contextos
// diferentes (`invoiced` de pipeline não é o `invoiced` de linha importada).
const variants = {
    // As sete etapas usam a paleta de estágio, uma cor cada. Antes cinco
    // delas eram o mesmo `info` azul: o dado mais repetido do sistema não
    // dizia nada pela cor, e quem varre uma lista de 20 Projetos tinha de
    // ler palavra por palavra para saber onde cada um está.
    pipeline: {
        design: 'stageDesign',
        production: 'stageProduction',
        assembly: 'stageAssembly',
        shipping: 'stageShipping',
        in_stock: 'stageStock',
        done: 'stageDone',
    },
    redemption: {
        confirmed: 'warning',
        shipped: 'info',
        delivered: 'success',
        canceled: 'danger',
    },
    importRow: {
        pending: 'warning',
        blocked_structural: 'danger',
        blocked_product: 'danger',
        order_created: 'info',
        invoiced: 'info',
        dispatched: 'info',
        delivered: 'success',
    },
    // Derivada do próprio status do lote — nunca de `rows_blocked`.
    import: {
        processing: 'info',
        completed: 'success',
        completed_with_blocks: 'warning',
        failed: 'danger',
    },
    projectRelease: {
        released: 'success',
        ready: 'info',
        producing: 'neutral',
    },
};

const label = computed(() => labels[props.kind]?.[props.status] ?? props.status);
const variant = computed(() => variants[props.kind]?.[props.status] ?? 'neutral');
</script>

<template>
    <UiBadge :variant="variant" dot>{{ label }}</UiBadge>
</template>
