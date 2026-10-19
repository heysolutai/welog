<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    value: { type: [String, Number], required: true },
    label: { type: String, required: true },
    tone: { type: String, default: 'default' }, // default | warning | danger | success
    href: { type: String, default: null },
});

const tones = {
    default: 'text-ink',
    warning: 'text-warning',
    danger: 'text-danger',
    success: 'text-success',
};

const classes = computed(() => [
    'block rounded-control border border-line bg-surface p-4',
    props.href && 'transition-colors hover:border-line-strong',
]);
</script>

<template>
    <component :is="href ? Link : 'div'" v-bind="href ? { href } : {}" :class="classes">
        <div :class="['text-stat font-semibold tabular-nums', tones[tone]]">{{ value }}</div>
        <div class="mt-1 text-xs text-ink-muted">{{ label }}</div>
    </component>
</template>
