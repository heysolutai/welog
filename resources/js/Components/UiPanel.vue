<script setup>
import { computed } from 'vue';

const props = defineProps({
    tone: { type: String, default: 'default' }, // default | muted
    padding: { type: String, default: 'sm' }, // sm | md
});

const tones = {
    default: 'border-line bg-surface',
    muted: 'border-line bg-surface-muted',
};

const paddings = { sm: 'p-3', md: 'p-4' };

const classes = computed(() => ['rounded-control border', tones[props.tone], paddings[props.padding]]);
</script>

<template>
    <div :class="classes">
        <slot />
    </div>
</template>
