<script setup>
/**
 * Rótulo + controle + dica + erro, com a ligação acessível correta.
 *
 * A raiz NÃO é mais um <label> envolvendo tudo: isso fazia o leitor de tela
 * anunciar a mensagem de erro como parte do NOME do campo e produzia
 * <label> dentro de <label> nos grupos de checkbox.
 *
 * O controle recebe `id`, `aria-describedby`, `aria-invalid` e `required` por
 * `provide`/`inject` — nenhuma página precisa repetir esses atributos.
 */
import { computed, provide, toRef, useId, useSlots } from 'vue';

const props = defineProps({
    label: { type: String, default: null },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
    as: { type: String, default: 'label' }, // 'label' | 'group' (fieldset/legend)
});

const slots = useSlots();

const uid = useId();
const id = `${uid}-controle`;
const hintId = `${uid}-dica`;
const errorId = `${uid}-erro`;

const isGroup = computed(() => props.as === 'group');
const hasError = computed(() => !!props.error || !!slots.error);
// A dica some quando há erro: uma mensagem, um lugar.
const hasHint = computed(() => !hasError.value && (!!props.hint || !!slots.hint));

const describedBy = computed(() => {
    const ids = [hasHint.value ? hintId : null, hasError.value ? errorId : null].filter(Boolean);

    return ids.length ? ids.join(' ') : undefined;
});

provide('uiField', {
    id,
    describedBy,
    invalid: hasError,
    required: toRef(props, 'required'),
    // Em modo grupo a descrição já está no <fieldset>; o controle não repete.
    group: isGroup,
});
</script>

<template>
    <component
        :is="isGroup ? 'fieldset' : 'div'"
        class="min-w-0"
        :aria-describedby="isGroup ? describedBy : null"
        :aria-invalid="isGroup && hasError ? 'true' : null"
    >
        <component
            :is="isGroup ? 'legend' : 'label'"
            v-if="label"
            :for="isGroup ? null : id"
            class="mb-1.5 block p-0 text-sm font-medium text-ink"
        >
            {{ label }}<span v-if="required" class="ml-0.5 text-danger" aria-hidden="true">*</span
            ><span v-if="required" class="sr-only"> (obrigatório)</span>
        </component>

        <slot />

        <p v-if="hasHint" :id="hintId" class="mt-1.5 text-xs leading-relaxed text-ink-muted">
            <slot name="hint">{{ hint }}</slot>
        </p>

        <p v-if="hasError" :id="errorId" role="alert" class="mt-1.5 text-xs font-medium text-danger">
            <slot name="error">{{ error }}</slot>
        </p>
    </component>
</template>
