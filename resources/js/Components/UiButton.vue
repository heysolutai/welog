<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    // primary | success | info | warning | secondary | ghost | ghost-nav | ghost-dark | danger | danger-soft
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' }, // sm (36px) | md (40px) | lg (48px)
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
    icon: { type: String, default: null },
    iconTrailing: { type: String, default: null },
    iconOnly: { type: Boolean, default: false },
    label: { type: String, default: null }, // obrigatório em iconOnly — vira aria-label
});

const inert = computed(() => props.disabled || props.loading);

/*
 * Botões em pílula, e o peso fora do contorno e da sombra (identidade aprovada
 * na tela do Projeto). O secundário era borda cinza escura com sombra e lia
 * como caixa; agora é branco com fio claro. O primário perdeu o halo laranja:
 * a cor já diz que é a ação da tela.
 */
const variants = {
    primary: 'bg-brand-600 text-ink-inverse shadow-xs hover:bg-brand-700',
    success: 'bg-success text-ink-inverse hover:bg-success-solid',
    info: 'bg-info text-ink-inverse hover:bg-info-solid',
    warning: 'bg-warning-soft text-warning hover:bg-warning-line',
    secondary: 'border border-line bg-surface text-ink hover:bg-surface-muted',
    ghost: 'text-ink-muted hover:bg-surface-muted hover:text-ink',
    // Único variante para superfície ESCURA (sidebar). O `ghost` normal fica
    // invisível ali: texto quase preto sobre fundo quase preto.
    'ghost-nav': 'text-nav-ink-muted hover:bg-nav-raised hover:text-nav-ink',
    // Superfície escura de verdade (cabeçalho do portal): branco translúcido.
    'ghost-dark': 'text-ink-inverse/70 hover:bg-ink-inverse/10 hover:text-ink-inverse',
    danger: 'bg-danger-solid text-ink-inverse hover:bg-danger',
    'danger-soft': 'bg-danger-soft text-danger hover:bg-danger-soft/70',
};

const sizes = {
    sm: { text: 'h-control-sm gap-1.5 px-3 text-sm', only: 'h-control-sm w-control-sm', icon: 16 },
    md: { text: 'h-control-md gap-2 px-4 text-sm', only: 'h-control-md w-control-md', icon: 16 },
    lg: { text: 'h-control-lg gap-2 px-5 text-base', only: 'h-control-lg w-control-lg', icon: 18 },
};

const sizing = computed(() => sizes[props.size] ?? sizes.md);
const iconSize = computed(() => sizing.value.icon);

if (import.meta.env.DEV && props.iconOnly && !props.label) {
    console.warn('[UiButton] iconOnly exige `label`: é ele que vira o aria-label.');
}

const classes = computed(() => [
    'inline-flex select-none items-center justify-center rounded-full font-medium whitespace-nowrap transition-all',
    props.iconOnly ? sizing.value.only : sizing.value.text,
    variants[props.variant],
    props.block && 'w-full',
    inert.value && 'cursor-not-allowed opacity-60',
    // Link desabilitado: o `@click.prevent` abaixo não basta porque o Inertia
    // sobrescreve o onClick herdado — quem bloqueia de fato é o pointer-events.
    props.href && inert.value && 'pointer-events-none',
]);

const attrs = computed(() =>
    props.href
        ? { href: props.href, ...(inert.value ? { 'aria-disabled': 'true', tabindex: -1 } : {}) }
        : { type: props.type, disabled: inert.value },
);
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        v-bind="attrs"
        :class="classes"
        :aria-label="iconOnly ? label : null"
        :aria-busy="loading ? 'true' : null"
        @click="inert && $event.preventDefault()"
    >
        <span
            v-if="loading"
            class="size-4 animate-spin rounded-full border-2 border-current border-r-transparent"
            aria-hidden="true"
        />
        <UiIcon v-else-if="icon" :name="icon" :size="iconSize" />
        <slot v-if="!iconOnly" />
        <UiIcon v-if="iconTrailing && !iconOnly" :name="iconTrailing" :size="iconSize" />
    </component>
</template>
