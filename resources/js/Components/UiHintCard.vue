<script setup>
import UiIcon from './UiIcon.vue';

/** Cartão "Dica rápida" do padrão de cadastro: orientação curta, sem alarme. */
defineProps({
    title: { type: String, default: 'Dica rápida' },
    icon: { type: String, default: 'info' },
});
</script>

<template>
    <div class="flex max-w-sm items-start gap-2.5 rounded-control border border-line bg-surface p-3.5">
        <UiIcon :name="icon" :size="18" class="mt-0.5 shrink-0 text-brand-600" />
        <div>
            <p class="text-sm font-semibold text-ink">{{ title }}</p>
            <p class="mt-0.5 text-sm text-ink-muted"><slot /></p>
        </div>
    </div>
</template>
