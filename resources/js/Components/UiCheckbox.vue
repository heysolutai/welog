<script setup>
const model = defineModel({ type: Boolean, default: false });

defineProps({
    label: { type: String, default: null },
    description: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <!-- A linha inteira é o alvo de toque (40px), não só o quadradinho de 16px. -->
    <label
        :class="[
            'flex min-h-10 items-start gap-2.5 py-2',
            disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        ]"
    >
        <input
            v-model="model"
            type="checkbox"
            :disabled="disabled"
            class="mt-0.5 size-4 shrink-0 accent-brand-600"
        />
        <span class="min-w-0">
            <span class="block text-sm text-ink">
                <slot>{{ label }}</slot>
            </span>
            <span v-if="description" class="mt-0.5 block text-xs text-ink-muted">{{ description }}</span>
        </span>
    </label>
</template>
