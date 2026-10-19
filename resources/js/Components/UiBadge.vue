<script setup>
import { computed } from 'vue';

const props = defineProps({
    // neutral | success | warning | danger | info | stage<Etapa>
    variant: { type: String, default: 'neutral' },
    dot: { type: Boolean, default: false },
    size: { type: String, default: 'sm' }, // sm | md
});

const variants = {
    neutral: 'bg-surface-muted text-ink-muted ring-line',
    success: 'bg-success-soft text-success ring-success-line',
    warning: 'bg-warning-soft text-warning ring-warning-line',
    danger: 'bg-danger-soft text-danger ring-danger-line',
    info: 'bg-info-soft text-info ring-info-line',

    // Posição na esteira, não juízo. O anel sai do próprio matiz, a 20% de
    // opacidade: sete pares soft/line seriam catorze tokens novos para uma
    // borda que ninguém lê isoladamente.
    stageDesign: 'bg-stage-design-soft text-stage-design ring-current/20',
    stageProduction: 'bg-stage-production-soft text-stage-production ring-current/20',
    stageAssembly: 'bg-stage-assembly-soft text-stage-assembly ring-current/20',
    stageShipping: 'bg-stage-shipping-soft text-stage-shipping ring-current/20',
    stageStock: 'bg-stage-stock-soft text-stage-stock ring-current/20',
    stageDone: 'bg-stage-done-soft text-stage-done ring-current/20',
};

const sizes = {
    sm: 'gap-1.5 px-2 py-0.5 text-xs',
    md: 'gap-2 px-2.5 py-1 text-sm',
};

const classes = computed(() => [
    'inline-flex items-center rounded-full font-medium whitespace-nowrap ring-1 ring-inset',
    sizes[props.size],
    variants[props.variant],
]);
</script>

<template>
    <span :class="classes">
        <!-- O ponto herda a cor do texto: uma variante, uma cor. -->
        <span v-if="dot" class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
        <slot />
    </span>
</template>
