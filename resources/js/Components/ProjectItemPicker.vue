<script setup>
import { computed, inject } from 'vue';
import ProjectItemThumb from './ProjectItemThumb.vue';
import UiCode from './UiCode.vue';
import { agruparPorGrade } from '../lib/grade';

/**
 * Seleção múltipla de itens do pedido, com foto e nome.
 *
 * Variante do UiCheckboxGroup para o cadastro de Usuário: a pessoa que autoriza
 * reconhece o brinde pela FOTO antes do nome (os nomes vindos da Omie são
 * códigos de catálogo, "KIT PIPOCA PP 2026"). O UiCheckboxGroup não comporta
 * miniatura na opção; este componente existe só para isso e mantém o mesmo
 * contrato (v-model de array + integração com UiField via provide/inject).
 *
 * Tamanhos da mesma grade da Omie aparecem como UM item ("Colete", com os
 * tamanhos embaixo): marcá-lo marca todos os tamanhos, e o servidor os junta
 * num grupo de escolha — a pessoa fica com um, no tamanho que escolher.
 */
const model = defineModel({ type: Array, default: () => [] });

const props = defineProps({
    // [{ value, label, code?, photo?, grade? }]
    options: { type: Array, required: true },
    invalid: { type: Boolean, default: false },
});

const field = inject('uiField', null);

const controlId = computed(() => field?.id);
// Dentro de <fieldset> a descrição já está na raiz do UiField; não repetir.
const describedBy = computed(() => (field?.group.value ? undefined : field?.describedBy.value));
const invalid = computed(() => props.invalid || field?.invalid.value === true);

const selected = computed(() => model.value ?? []);

const entradas = computed(() => agruparPorGrade(props.options));

function isChecked(entrada) {
    return entrada.values.every((value) => selected.value.includes(value));
}

function toggle(entrada) {
    model.value = isChecked(entrada)
        ? selected.value.filter((item) => !entrada.values.includes(item))
        : [...new Set([...selected.value, ...entrada.values])];
}

// Conta o que a pessoa vê: o Colete com sete tamanhos é UM item.
const quantos = computed(() => entradas.value.filter(isChecked).length);
</script>

<template>
    <div>
        <div
            :id="controlId"
            :role="field?.group.value ? null : 'group'"
            :aria-describedby="describedBy"
            :aria-invalid="invalid ? 'true' : null"
            :class="[
                'divide-y divide-line rounded-control border bg-surface',
                invalid ? 'border-danger' : 'border-line',
            ]"
        >
            <label
                v-for="entrada in entradas"
                :key="entrada.key"
                class="flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors hover:bg-surface-muted"
            >
                <input
                    type="checkbox"
                    class="size-4 shrink-0 accent-brand-600"
                    :checked="isChecked(entrada)"
                    @change="toggle(entrada)"
                />
                <ProjectItemThumb :src="entrada.photo" size="sm" />
                <span class="min-w-0">
                    <span class="block truncate text-sm text-ink">{{ entrada.label }}</span>
                    <UiCode v-if="entrada.code" class="mt-0.5" :value="entrada.code" />
                    <span v-if="entrada.tamanhos.length" class="mt-0.5 block text-xs text-ink-muted">
                        Escolhe o tamanho no portal: {{ entrada.tamanhos.join(' · ') }}
                    </span>
                </span>
            </label>
        </div>

        <p aria-live="polite" class="mt-1.5 text-xs tabular-nums text-ink-muted">
            {{ quantos }} {{ quantos === 1 ? 'item selecionado' : 'itens selecionados' }}
        </p>
    </div>
</template>
