<script setup>
import { computed } from 'vue';
import { formatDate } from '../lib/format';

const props = defineProps({
    // [{ description, occurredAt, location }]
    events: { type: Array, required: true },
});

const items = computed(() =>
    props.events.map((event, index) => {
        const when = event.occurredAt ?? event.occurred_at;
        const meta = [when ? formatDate(when, true) : null, event.location].filter(Boolean).join(' · ');

        return { description: event.description, meta, latest: index === 0 };
    }),
);
</script>

<template>
    <ol>
        <li v-for="(item, index) in items" :key="index" class="flex gap-3">
            <div class="flex w-2.5 flex-col items-center" aria-hidden="true">
                <span
                    :class="['mt-1.5 size-2.5 shrink-0 rounded-full', item.latest ? 'bg-brand-600' : 'bg-line-strong']"
                />
                <!-- Fio ligando os pontos; o último evento não puxa linha. -->
                <span v-if="index < items.length - 1" class="mt-1 w-px flex-1 bg-line" />
            </div>

            <div :class="['min-w-0', index < items.length - 1 && 'pb-4']">
                <p class="text-sm text-ink">{{ item.description }}</p>
                <p v-if="item.meta" class="mt-0.5 text-sm text-ink-muted">{{ item.meta }}</p>
            </div>
        </li>
    </ol>
</template>
