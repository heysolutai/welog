<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Buscar…' },
    debounce: { type: Number, default: 300 },
    resultLabel: { type: String, default: null }, // ex.: '18 produtos'
});

const emit = defineEmits(['update:modelValue', 'search', 'clear']);

const value = ref(props.modelValue);
const input = ref(null);
let timer = null;

watch(
    () => props.modelValue,
    (incoming) => {
        if (incoming !== value.value) value.value = incoming;
    },
);

/** `immediate` pula o debounce: Enter e limpar não devem esperar. */
function apply(next, immediate = false) {
    value.value = next;
    emit('update:modelValue', next);

    clearTimeout(timer);

    if (immediate) {
        emit('search', next);

        return;
    }

    timer = setTimeout(() => emit('search', next), props.debounce);
}

function clear() {
    apply('', true);
    emit('clear');
    input.value?.focus();
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex w-full flex-wrap items-center gap-x-3 gap-y-2">
        <div class="relative min-w-0 flex-1 sm:max-w-sm">
            <UiIcon
                name="search"
                :size="16"
                class="pointer-events-none absolute inset-y-0 left-3 my-auto text-ink-subtle"
            />
            <input
                ref="input"
                type="search"
                role="searchbox"
                :value="value"
                :placeholder="placeholder"
                :aria-label="placeholder"
                class="h-control-md block w-full rounded-control border border-line-strong bg-surface pl-9 pr-10 text-sm text-ink shadow-xs transition-colors placeholder:text-ink-muted hover:border-ink-subtle [&::-webkit-search-cancel-button]:hidden"
                @input="apply($event.target.value)"
                @keydown.enter.prevent="apply(value, true)"
            />
            <button
                v-if="value"
                type="button"
                aria-label="Limpar busca"
                class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-control text-ink-muted transition-colors hover:text-ink"
                @click="clear"
            >
                <UiIcon name="close" :size="16" />
            </button>
        </div>

        <span
            v-if="resultLabel"
            aria-live="polite"
            class="ml-auto shrink-0 text-xs tabular-nums text-ink-muted"
        >
            {{ resultLabel }}
        </span>
    </div>
</template>
