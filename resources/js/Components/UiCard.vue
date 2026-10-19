<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    padding: { type: String, default: 'md' }, // none | sm | md
    interactive: { type: Boolean, default: false },
});

// 20px no padrão (era 16px): a especificação do desenho pede 20-24px, e o
// aperto anterior era parte do que fazia a tela parecer amontoada.
const paddings = { none: '', sm: 'p-4', md: 'p-5' };

// Sem borda: quem separa o cartão do canvas é a sombra. O traço em volta
// somado ao raio curto era o que fazia a tela ler como grade de caixas.
const classes = computed(() => [
    'rounded-card bg-surface shadow-card',
    props.interactive && 'transition-all hover:-translate-y-0.5 hover:shadow-card-hover',
]);
</script>

<template>
    <div :class="classes">
        <div
            v-if="title || $slots.header"
            class="flex items-start justify-between gap-4 px-5 pt-5 pb-3"
        >
            <div class="min-w-0">
                <!-- Nível 3. Fica em 14px de propósito: card não compete com
                     seção. O que o separa do corpo é peso e o espaço abaixo. -->
                <h3 v-if="title" class="text-sm font-semibold text-ink">{{ title }}</h3>
                <p v-if="subtitle" class="mt-1 text-xs text-ink-muted">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.header" class="shrink-0">
                <slot name="header" />
            </div>
        </div>

        <div :class="paddings[padding]">
            <slot />
        </div>

        <div v-if="$slots.footer" class="border-t border-line px-5 py-4">
            <slot name="footer" />
        </div>
    </div>
</template>
