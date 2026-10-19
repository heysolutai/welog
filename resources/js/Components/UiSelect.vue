<script setup>
import { computed, inject } from 'vue';

defineOptions({ inheritAttrs: false });

const model = defineModel({ type: [String, Number], default: '' });

const props = defineProps({
    options: { type: Array, default: null }, // [{ value, label, disabled? }]
    placeholder: { type: String, default: null },
    invalid: { type: Boolean, default: false },
    size: { type: String, default: 'md' },
    width: { type: String, default: 'full' },
});

const field = inject('uiField', null);

const controlId = computed(() => field?.id);
const required = computed(() => field?.required.value ?? false);
const describedBy = computed(() => field?.describedBy.value);
const invalid = computed(() => props.invalid || field?.invalid.value === true);

const empty = computed(() => model.value === '' || model.value === null || model.value === undefined);

const sizes = {
    sm: 'h-control-sm text-sm',
    md: 'h-control-md text-sm',
    lg: 'h-control-lg text-base',
};

const widths = {
    full: 'w-full',
    auto: 'w-auto',
    sm: 'w-32',
    md: 'w-48',
};
</script>

<template>
    <select
        :id="controlId"
        v-model="model"
        :required="required || null"
        :aria-invalid="invalid ? 'true' : null"
        :aria-describedby="describedBy"
        :class="[
            'ui-select appearance-none rounded-control border bg-surface py-0 pl-3 pr-9 shadow-xs transition-colors',
            'disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-ink-muted',
            sizes[size] ?? sizes.md,
            widths[width] ?? widths.full,
            empty ? 'text-ink-muted' : 'text-ink',
            invalid ? 'border-danger' : 'border-line-strong hover:border-ink-subtle',
        ]"
        v-bind="$attrs"
    >
        <option v-if="placeholder" value="">{{ placeholder }}</option>
        <option
            v-for="option in options ?? []"
            :key="option.value"
            :value="option.value"
            :disabled="option.disabled"
        >
            {{ option.label }}
        </option>
        <slot />
    </select>
</template>

<style scoped>
/* Chevron próprio (a seta nativa some com `appearance-none`).
 *
 * ⚠️ ÚNICO ponto do frontend onde uma cor é escrita à mão, e não por
 * preguiça: `var()` não interpola dentro de `url()`, e `mask` não serve
 * aqui porque mascararia o <select> inteiro, apagando o texto da opção.
 * Pintar por pseudo-elemento exigiria um wrapper em volta de todo select
 * do sistema — custo alto por uma seta.
 *
 * O valor abaixo é --color-ink-muted (app.css). Ao mudar aquele token,
 * mude este junto: nada no build acusa a divergência.
 */
.ui-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b6157' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.625rem center;
    background-size: 1.1rem 1.1rem;
}
</style>
