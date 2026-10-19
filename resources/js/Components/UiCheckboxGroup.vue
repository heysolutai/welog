<script setup>
import { computed, inject, ref } from 'vue';
import UiCheckbox from './UiCheckbox.vue';
import UiSearchInput from './UiSearchInput.vue';

const model = defineModel({ type: Array, default: () => [] });

const props = defineProps({
    options: { type: Array, required: true }, // [{ value, label, hint? }]
    columns: { type: Number, default: 1 },
    searchable: { type: Boolean, default: false },
    maxHeight: { type: String, default: null },
    emptyLabel: { type: String, default: 'Nenhum resultado.' },
    invalid: { type: Boolean, default: false },
});

const field = inject('uiField', null);

const controlId = computed(() => field?.id);
// Dentro de <fieldset> a descrição já está na raiz do UiField; não repetir.
const describedBy = computed(() => (field?.group.value ? undefined : field?.describedBy.value));
const invalid = computed(() => props.invalid || field?.invalid.value === true);

const query = ref('');

/** Busca sem acento e sem caixa: "canecas" acha "Canecas Térmicas". */
function normalize(value) {
    return String(value ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '');
}

const filtered = computed(() => {
    const term = normalize(query.value).trim();

    if (!props.searchable || !term) return props.options;

    return props.options.filter(
        (option) => normalize(option.label).includes(term) || normalize(option.hint).includes(term),
    );
});

const selected = computed(() => model.value ?? []);
const count = computed(() => selected.value.length);

function isChecked(value) {
    return selected.value.includes(value);
}

function toggle(value, checked) {
    model.value = checked
        ? [...selected.value, value]
        : selected.value.filter((item) => item !== value);
}

const gridColumns = {
    1: '',
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
};
</script>

<template>
    <div>
        <UiSearchInput
            v-if="searchable"
            v-model="query"
            :debounce="0"
            placeholder="Buscar na lista…"
            class="mb-2"
        />

        <div
            :id="controlId"
            :role="field?.group.value ? null : 'group'"
            :aria-describedby="describedBy"
            :aria-invalid="invalid ? 'true' : null"
            :style="maxHeight ? { maxHeight } : null"
            :class="[
                'scrollbar-slim rounded-control border bg-surface p-1',
                maxHeight ? 'overflow-y-auto' : '',
                invalid ? 'border-danger' : 'border-line',
            ]"
        >
            <div v-if="filtered.length" :class="['grid gap-x-4', gridColumns[columns] ?? '']">
                <UiCheckbox
                    v-for="option in filtered"
                    :key="option.value"
                    :model-value="isChecked(option.value)"
                    :label="option.label"
                    :description="option.hint"
                    :disabled="option.disabled"
                    class="rounded-control px-2 hover:bg-surface-muted"
                    @update:model-value="toggle(option.value, $event)"
                />
            </div>
            <p v-else class="px-3 py-6 text-center text-sm text-ink-muted">{{ emptyLabel }}</p>
        </div>

        <p aria-live="polite" class="mt-1.5 text-xs tabular-nums text-ink-muted">
            <slot name="summary" :count="count">
                {{ count }} {{ count === 1 ? 'selecionado' : 'selecionados' }}
            </slot>
        </p>
    </div>
</template>
