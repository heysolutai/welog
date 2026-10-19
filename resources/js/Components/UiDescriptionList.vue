<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    items: { type: Array, required: true }, // [{ key, label, value, mono?, href? }]
    layout: { type: String, default: 'inline' }, // inline | stacked | grid
    columns: { type: Number, default: 2 }, // só em grid
});

const roots = {
    inline: 'flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted',
    stacked: 'space-y-3',
    // Colunas por variável CSS: o número vem de prop, e abaixo de `sm` é sempre 1.
    grid: 'grid grid-cols-1 gap-x-6 gap-y-3 sm:[grid-template-columns:repeat(var(--ui-cols),minmax(0,1fr))]',
};

const itemClass = computed(() =>
    props.layout === 'inline'
        ? 'flex items-center gap-2 border-l border-line pl-3 first:border-l-0 first:pl-0'
        : 'min-w-0',
);

const labelClass = computed(() => (props.layout === 'inline' ? 'sr-only' : 'text-xs text-ink-muted'));

const valueClass = computed(() => (props.layout === 'inline' ? '' : 'mt-0.5 text-sm text-ink'));

const isExternal = (href) => /^https?:\/\//.test(href);
const isEmpty = (value) => value === null || value === undefined || value === '';
</script>

<template>
    <dl :class="roots[layout]" :style="layout === 'grid' ? { '--ui-cols': columns } : null">
        <div v-for="item in items" :key="item.key" :class="itemClass">
            <dt :class="labelClass">{{ item.label }}</dt>
            <dd :class="[valueClass, item.mono && 'font-mono']">
                <slot :name="`value-${item.key}`" :item="item">
                    <a
                        v-if="item.href && isExternal(item.href)"
                        :href="item.href"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 text-brand-700 hover:underline"
                    >
                        {{ item.value }}
                        <UiIcon name="external" :size="14" />
                        <span class="sr-only">(abre em nova aba)</span>
                    </a>
                    <Link v-else-if="item.href" :href="item.href" class="text-brand-700 hover:underline">
                        {{ item.value }}
                    </Link>
                    <template v-else-if="isEmpty(item.value)">
                        <span class="text-ink-muted">Não informado</span>
                    </template>
                    <template v-else>{{ item.value }}</template>
                </slot>
            </dd>
        </div>
    </dl>
</template>
