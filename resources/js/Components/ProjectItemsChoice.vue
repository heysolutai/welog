<script setup>
import { computed, watch } from 'vue';
import ProjectItemPicker from './ProjectItemPicker.vue';
import UiField from './UiField.vue';

/**
 * O que uma pessoa pode resgatar neste Projeto: quais itens e, com dois ou
 * mais, se recebe todos ou escolhe um.
 *
 * Mora aqui porque são dois cadastros com a mesma pergunta: o do Colaborador
 * e o do Admin do Projeto que também resgata. Duas cópias da mesma escolha
 * acabariam dizendo coisas diferentes.
 */
const productIds = defineModel('productIds', { type: Array, required: true });
const mode = defineModel('mode', { type: String, default: 'all' });

const props = defineProps({
    // [{ value, label, code?, photo?, grade? }]: os itens do pedido.
    options: { type: Array, required: true },
    // Os itens como o cadastro os mostra: os tamanhos de uma grade são UM.
    entradas: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
    label: { type: String, default: 'Quais itens esta pessoa pode receber?' },
});

/** O modo só é pergunta com 2+ itens marcados; com um, "todos" e "um" são iguais. */
const showMode = computed(
    () => props.entradas.filter((e) => e.values.some((v) => productIds.value.includes(v))).length >= 2,
);

// Desmarcou até sobrar um item: "escolhe um deles" deixa de fazer sentido e o
// campo some da tela. O valor não pode ficar preso em `choose` invisível.
watch(showMode, (visible) => {
    if (!visible) mode.value = 'all';
});
</script>

<template>
    <UiField
        as="group"
        :label="label"
        required
        :error="errors.product_ids ?? errors.product_id"
    >
        <ProjectItemPicker v-model="productIds" :options="options" />
    </UiField>

    <UiField
        v-if="showMode"
        as="group"
        label="Como funciona com mais de um item?"
        required
        :error="errors.mode"
    >
        <div class="divide-y divide-line rounded-control border border-line bg-surface">
            <label class="flex min-h-10 cursor-pointer items-start gap-2.5 px-3 py-2.5">
                <input
                    v-model="mode"
                    type="radio"
                    value="all"
                    class="mt-0.5 size-4 shrink-0 accent-brand-600"
                />
                <span class="min-w-0">
                    <span class="block text-sm text-ink">Recebe todos os itens selecionados</span>
                    <span class="mt-0.5 block text-xs text-ink-muted">
                        A pessoa resgata cada um dos itens.
                    </span>
                </span>
            </label>

            <label class="flex min-h-10 cursor-pointer items-start gap-2.5 px-3 py-2.5">
                <input
                    v-model="mode"
                    type="radio"
                    value="choose"
                    class="mt-0.5 size-4 shrink-0 accent-brand-600"
                />
                <span class="min-w-0">
                    <span class="block text-sm text-ink">Escolhe apenas um deles</span>
                    <span class="mt-0.5 block text-xs text-ink-muted">
                        Os itens viram opções no portal; a pessoa resgata só um.
                    </span>
                </span>
            </label>
        </div>
    </UiField>
</template>
