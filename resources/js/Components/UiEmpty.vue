<script setup>
import UiIcon from './UiIcon.vue';

defineProps({
    message: { type: String, required: true },
    title: { type: String, default: null },
    icon: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-col items-center gap-3 rounded-control border border-dashed border-line px-6 py-12 text-center">
        <span
            v-if="icon"
            class="flex size-10 items-center justify-center rounded-full bg-surface-muted text-ink-muted"
        >
            <UiIcon :name="icon" :size="20" />
        </span>

        <div class="max-w-md space-y-1">
            <p v-if="title" class="text-sm font-semibold text-ink">{{ title }}</p>
            <p class="text-sm text-ink-muted">{{ message }}</p>
        </div>

        <!-- A ação mora aqui dentro, no campo de visão de quem leu a mensagem. -->
        <div v-if="$slots.action" class="mt-1">
            <slot name="action" />
        </div>
    </div>
</template>
