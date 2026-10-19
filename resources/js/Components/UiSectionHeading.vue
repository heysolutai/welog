<script setup>
defineProps({
    title: { type: String, required: true },
    count: { type: Number, default: null },
    hint: { type: String, default: null },
});
</script>

<template>
    <!-- O espaço ACIMA é o que enquadra a seção: precisa ser bem maior que o
         espaço interno dela, senão o olho não sabe onde uma termina e a outra
         começa. Agrupamento por proximidade só funciona com contraste. -->
    <div class="mt-block mb-4 flex items-end justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-section font-semibold text-ink">
                {{ title }}
                <span v-if="count !== null" class="ml-1.5 text-base font-normal tabular-nums text-ink-muted">{{ count }}</span>
            </h2>
            <p v-if="hint" class="mt-1 text-sm text-ink-muted">{{ hint }}</p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
