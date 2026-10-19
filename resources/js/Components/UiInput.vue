<script setup>
import { computed, inject, useSlots } from 'vue';

defineOptions({ inheritAttrs: false });

const model = defineModel({ type: [String, Number], default: '' });

const props = defineProps({
    type: { type: String, default: 'text' },
    invalid: { type: Boolean, default: false },
    size: { type: String, default: 'md' }, // sm (36px) | md (40px) | lg (48px)
    width: { type: String, default: 'full' }, // full | auto | sm | md
    loading: { type: Boolean, default: false },
    prefix: { type: String, default: null },
    suffix: { type: String, default: null },
});

const field = inject('uiField', null);

const controlId = computed(() => field?.id);
const required = computed(() => field?.required.value ?? false);
const describedBy = computed(() => field?.describedBy.value);
const invalid = computed(() => props.invalid || field?.invalid.value === true);

const sizes = {
    sm: 'h-control-sm text-sm',
    md: 'h-control-md text-sm',
    lg: 'h-control-lg text-base',
};

// A largura é prop, não classe do pai: acaba com a colisão entre o `w-full`
// do componente e o `class="w-40"` escrito na página.
const widths = {
    full: 'w-full',
    auto: 'w-auto',
    sm: 'w-32', // 128px — CEP, número, quantidade
    md: 'w-48', // 192px — código de rastreio, nota fiscal
};

const slots = useSlots();

// A calha da direita é maior quando há AÇÃO (o olho da senha): ela é um alvo
// de clique, não um rótulo, e precisa dos 44px de toque sem o texto passando
// por baixo.
const padding = computed(() => [
    props.prefix ? 'pl-9' : 'pl-3',
    slots.action ? 'pr-12' : (props.suffix || props.loading ? 'pr-10' : 'pr-3'),
]);
</script>

<template>
    <div :class="['relative', widths[width] ?? widths.full]" :aria-busy="loading ? 'true' : null">
        <span
            v-if="prefix"
            class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-ink-muted"
        >
            {{ prefix }}
        </span>

        <input
            :id="controlId"
            v-model="model"
            :type="type"
            :required="required || null"
            :aria-invalid="invalid ? 'true' : null"
            :aria-describedby="describedBy"
            :class="[
                'block w-full rounded-control border bg-surface text-ink shadow-xs transition-colors',
                'placeholder:text-ink-muted',
                'disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-ink-muted',
                sizes[size] ?? sizes.md,
                padding,
                invalid ? 'border-danger' : 'border-line-strong hover:border-ink-subtle',
            ]"
            v-bind="$attrs"
        />

        <!-- Ação dentro do campo (mostrar/ocultar senha, limpar). Fica acima
             do input e não é `pointer-events-none`, ao contrário do sufixo. -->
        <span v-if="$slots.action" class="absolute inset-y-0 right-0 flex items-center">
            <slot name="action" />
        </span>

        <span v-if="loading" class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
            <span
                class="size-4 animate-spin rounded-full border-2 border-line-strong border-t-brand-600"
                aria-hidden="true"
            />
        </span>
        <span
            v-else-if="suffix"
            class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-ink-muted"
        >
            {{ suffix }}
        </span>
    </div>
</template>
