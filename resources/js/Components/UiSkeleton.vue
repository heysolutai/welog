<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'text' }, // text | block | row
    lines: { type: Number, default: 3 },
    columns: { type: Number, default: 4 }, // só em row
});

// A última linha de texto é mais curta — parágrafo, não bloco.
const textWidths = computed(() =>
    Array.from({ length: props.lines }, (_, i) => (i === props.lines - 1 ? 'w-2/3' : 'w-full')),
);

const rows = computed(() => Array.from({ length: props.lines }, (_, i) => i));
const cells = computed(() => Array.from({ length: props.columns }, (_, i) => i));
</script>

<template>
    <div role="status" aria-live="polite">
        <span class="sr-only">Carregando…</span>

        <div v-if="variant === 'text'" class="space-y-2" aria-hidden="true">
            <div v-for="(width, i) in textWidths" :key="i" :class="['skeleton h-4 rounded-control', width]" />
        </div>

        <div v-else-if="variant === 'block'" class="skeleton h-32 w-full rounded-control" aria-hidden="true" />

        <div v-else class="divide-y divide-line rounded-control border border-line" aria-hidden="true">
            <div v-for="row in rows" :key="row" class="flex items-center gap-4 px-4 py-3">
                <div
                    v-for="cell in cells"
                    :key="cell"
                    :class="['skeleton h-4 rounded-control', cell === 0 ? 'w-2/5' : 'flex-1']"
                />
            </div>
        </div>
    </div>
</template>
