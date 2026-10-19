<script setup>
import { useId } from 'vue';

defineProps({
    title: { type: String, default: null },
    description: { type: String, default: null },
    columns: { type: Number, default: 1 },
});

const titleId = useId();

// Uma coluna por padrão. Mais de uma só para campos curtos da mesma família.
const gridColumns = {
    1: '',
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
    4: 'sm:grid-cols-2 lg:grid-cols-4',
};
</script>

<template>
    <section :aria-labelledby="title ? titleId : null">
        <h3 v-if="title" :id="titleId" class="text-xs font-semibold uppercase tracking-wide text-ink-muted">
            {{ title }}
        </h3>
        <p v-if="description" class="mt-1 text-xs text-ink-muted">{{ description }}</p>

        <div :class="['grid gap-4', (title || description) && 'mt-3', gridColumns[columns] ?? '']">
            <slot />
        </div>
    </section>
</template>
