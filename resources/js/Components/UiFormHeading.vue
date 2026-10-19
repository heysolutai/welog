<script setup>
import UiIcon from './UiIcon.vue';

/**
 * Título de seção DENTRO de um formulário/card, no padrão "telas de
 * cadastro": ícone num chip da cor da marca + texto. Para títulos de seção
 * de PÁGINA continua valendo o UiSectionHeading.
 */
defineProps({
    icon: { type: String, required: true },
    title: { type: String, required: true },
});
</script>

<template>
    <h2 class="flex items-center gap-2 text-sm font-semibold text-ink">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-control bg-brand-50 text-brand-700">
            <UiIcon :name="icon" :size="16" />
        </span>
        {{ title }}
        <slot />
    </h2>
</template>
