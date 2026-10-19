<script setup>
import { computed, ref, useId } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    modelValue: { type: String, required: true },
    tabs: { type: Array, required: true }, // [{ key, label, count? }]
    variant: { type: String, default: 'underline' }, // underline | segmented
    /**
     * Prefixo dos ids. Informe quando a página quiser ligar o painel:
     * `<section :id="`aba-${chave}`" role="tabpanel" tabindex="0">` com
     * `panel-prefix="aba"`. Sem ele, os ids são únicos mas imprevisíveis.
     */
    panelPrefix: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const uid = useId();
const prefix = computed(() => props.panelPrefix ?? uid);
const buttons = ref([]);

const tabId = (key) => `${prefix.value}-aba-${key}`;
const panelId = (key) => `${prefix.value}-${key}`;

function select(key) {
    if (key !== props.modelValue) emit('update:modelValue', key);
}

// ←/→ movem e ativam; Home/End vão às pontas.
function onKeydown(event, index) {
    const steps = { ArrowRight: 1, ArrowLeft: -1 };
    let next = null;

    if (steps[event.key]) next = (index + steps[event.key] + props.tabs.length) % props.tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = props.tabs.length - 1;
    if (next === null) return;

    event.preventDefault();
    select(props.tabs[next].key);
    buttons.value[next]?.focus();
}

const styles = computed(() =>
    props.variant === 'segmented'
        ? {
              list: 'inline-flex gap-1 rounded-control bg-surface-muted p-1',
              tab: 'h-10 rounded-control px-3',
              active: 'bg-surface text-ink shadow-xs',
              idle: 'text-ink-muted hover:text-ink',
          }
        : {
              list: 'flex gap-1 border-b border-line',
              tab: 'h-10 -mb-px border-b-2 px-3',
              active: 'border-brand-600 text-brand-700',
              idle: 'border-transparent text-ink-muted hover:text-ink',
          },
);

defineExpose({ tabId, panelId });
</script>

<template>
    <div :class="['scrollbar-slim overflow-x-auto', variant === 'segmented' && 'flex']">
        <div role="tablist" :class="styles.list">
            <button
                v-for="(tab, index) in tabs"
                :key="tab.key"
                :ref="(el) => (buttons[index] = el)"
                type="button"
                role="tab"
                :id="tabId(tab.key)"
                :aria-selected="tab.key === modelValue ? 'true' : 'false'"
                :aria-current="tab.key === modelValue ? 'page' : null"
                :aria-controls="panelId(tab.key)"
                :tabindex="tab.key === modelValue ? 0 : -1"
                :class="[
                    'inline-flex shrink-0 items-center gap-1.5 text-sm font-medium whitespace-nowrap transition-colors',
                    styles.tab,
                    tab.key === modelValue ? styles.active : styles.idle,
                ]"
                @click="select(tab.key)"
                @keydown="onKeydown($event, index)"
            >
                <UiIcon v-if="tab.icon" :name="tab.icon" :size="15" />
                {{ tab.label }}
                <span v-if="tab.count !== undefined && tab.count !== null" class="tabular-nums">
                    ({{ tab.count }})
                </span>
            </button>
        </div>
    </div>
</template>
