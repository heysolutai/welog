<script setup>
import { computed, onUnmounted, ref } from 'vue';
import UiButton from './UiButton.vue';

const props = defineProps({
    value: { type: String, required: true },
    truncate: { type: String, default: 'none' }, // none | start | end
    copyable: { type: Boolean, default: false },
});

// Chave de NF tem 44 dígitos; acima disto o chip domina a linha da tabela.
const VISIBLE_CHARS = 14;

const shortened = computed(() => {
    const value = props.value ?? '';
    if (props.truncate === 'none' || value.length <= VISIBLE_CHARS) return value;
    return props.truncate === 'start' ? `…${value.slice(-VISIBLE_CHARS)}` : `${value.slice(0, VISIBLE_CHARS)}…`;
});

const isTruncated = computed(() => shortened.value !== props.value);

const copied = ref(false);
let timer = null;

async function copy() {
    try {
        await navigator.clipboard.writeText(props.value);
    } catch {
        return; // navegador sem permissão de área de transferência: nada a anunciar
    }
    copied.value = true;
    clearTimeout(timer);
    timer = setTimeout(() => (copied.value = false), 2000);
}

onUnmounted(() => clearTimeout(timer));
</script>

<template>
    <span class="inline-flex items-center gap-1.5">
        <code
            class="rounded-sm bg-surface-muted px-1.5 py-0.5 font-mono text-xs text-ink"
            :title="isTruncated ? value : null"
        >
            <span aria-hidden="true">{{ shortened }}</span>
            <span class="sr-only">{{ value }}</span>
        </code>
        <UiButton v-if="copyable" variant="ghost" size="md" icon="copy" @click="copy">
            {{ copied ? 'Copiado' : 'Copiar' }}
        </UiButton>
        <span v-if="copyable" class="sr-only" role="status">{{ copied ? 'Código copiado' : '' }}</span>
    </span>
</template>
