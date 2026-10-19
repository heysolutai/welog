<script setup>
import { computed, ref } from 'vue';
import UiIcon from './UiIcon.vue';

/**
 * Visualizador estruturado de JSON: árvore com chaves à esquerda e valores
 * tipados à direita, ramos abertos/fechados por clique.
 *
 * Nasceu no log de webhooks, onde o payload cru (um bloco só de texto) obrigava
 * a caçar o campo no meio da chave. Aqui o objeto vira lista legível e cada
 * ramo diz quantos itens tem antes de abrir.
 *
 * Recursivo: cada ramo renderiza outro UiJsonView.
 */
const props = defineProps({
    value: { required: true },
    /** Profundidade que já nasce aberta. Além dela, o ramo abre no clique. */
    openDepth: { type: Number, default: 1 },
    depth: { type: Number, default: 0 },
});

defineOptions({ name: 'UiJsonView' });

const isObject = (v) => v !== null && typeof v === 'object';

const entries = computed(() => {
    if (!isObject(props.value)) return [];

    return Array.isArray(props.value)
        ? props.value.map((v, i) => [String(i), v])
        : Object.entries(props.value);
});

const open = ref(new Set());

// Ramos rasos já nascem abertos: o que importa costuma estar no topo.
entries.value.forEach(([key]) => {
    if (props.depth < props.openDepth) open.value.add(key);
});

function toggle(key) {
    if (open.value.has(key)) open.value.delete(key);
    else open.value.add(key);
    // Set não é reativo em profundidade: troca a referência.
    open.value = new Set(open.value);
}

/** Resumo do ramo fechado: diz o tamanho sem precisar abrir. */
function summary(v) {
    if (Array.isArray(v)) return `${v.length} ${v.length === 1 ? 'item' : 'itens'}`;

    const n = Object.keys(v).length;

    return `${n} ${n === 1 ? 'campo' : 'campos'}`;
}

/** Cor e texto do valor simples, para o tipo ser reconhecido de relance. */
function display(v) {
    if (v === null) return { text: 'null', tone: 'text-ink-subtle italic' };
    if (v === '') return { text: 'vazio', tone: 'text-ink-subtle italic' };
    if (typeof v === 'boolean') return { text: v ? 'true' : 'false', tone: 'text-brand-700 font-medium' };
    if (typeof v === 'number') return { text: String(v), tone: 'text-ink tabular-nums font-medium' };

    return { text: String(v), tone: 'text-ink' };
}
</script>

<template>
    <ul class="space-y-0.5 text-sm">
        <li v-for="[key, val] in entries" :key="key">
            <!-- Ramo: objeto ou lista -->
            <template v-if="isObject(val)">
                <button
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded px-1 py-1 text-left hover:bg-surface-muted"
                    :aria-expanded="open.has(key) ? 'true' : 'false'"
                    @click="toggle(key)"
                >
                    <UiIcon
                        :name="open.has(key) ? 'chevron-down' : 'chevron-right'"
                        :size="14"
                        class="shrink-0 text-ink-subtle"
                    />
                    <span class="font-medium text-ink">{{ key }}</span>
                    <span class="text-xs text-ink-muted">{{ summary(val) }}</span>
                </button>

                <div v-if="open.has(key)" class="ml-2 border-l border-line pl-3">
                    <UiJsonView :value="val" :open-depth="openDepth" :depth="depth + 1" />
                </div>
            </template>

            <!-- Folha: chave e valor lado a lado -->
            <div v-else class="flex items-start gap-3 px-1 py-1 pl-[22px]">
                <span class="w-40 shrink-0 truncate text-ink-muted" :title="key">{{ key }}</span>
                <span :class="['min-w-0 flex-1 break-words', display(val).tone]">{{ display(val).text }}</span>
            </div>
        </li>

        <li v-if="!entries.length" class="px-1 py-1 text-ink-subtle italic">vazio</li>
    </ul>
</template>
