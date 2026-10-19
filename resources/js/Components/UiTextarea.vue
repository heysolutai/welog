<script setup>
import { computed, inject, nextTick, onMounted, ref, watch } from 'vue';

defineOptions({ inheritAttrs: false });

const model = defineModel({ type: String, default: '' });

const props = defineProps({
    rows: { type: Number, default: 3 },
    invalid: { type: Boolean, default: false },
    mono: { type: Boolean, default: false },
    autogrow: { type: Boolean, default: false },
    maxlength: { type: Number, default: null },
    counter: { type: Boolean, default: false },
});

const field = inject('uiField', null);

const controlId = computed(() => field?.id);
const required = computed(() => field?.required.value ?? false);
const describedBy = computed(() => field?.describedBy.value);
const invalid = computed(() => props.invalid || field?.invalid.value === true);

const length = computed(() => String(model.value ?? '').length);

const el = ref(null);

function resize() {
    if (!props.autogrow || !el.value) return;

    el.value.style.height = 'auto';
    el.value.style.height = `${el.value.scrollHeight}px`;
}

onMounted(resize);
watch(model, () => nextTick(resize));
</script>

<template>
    <div>
        <textarea
            :id="controlId"
            ref="el"
            v-model="model"
            :rows="rows"
            :maxlength="maxlength ?? undefined"
            :required="required || null"
            :aria-invalid="invalid ? 'true' : null"
            :aria-describedby="describedBy"
            :class="[
                'block w-full rounded-control border bg-surface px-3 py-2 text-ink shadow-xs transition-colors',
                'placeholder:text-ink-muted',
                'disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-ink-muted',
                mono ? 'font-mono text-xs leading-relaxed' : 'text-sm',
                autogrow ? 'resize-none overflow-hidden' : 'resize-y',
                invalid ? 'border-danger' : 'border-line-strong hover:border-ink-subtle',
            ]"
            v-bind="$attrs"
        />

        <p v-if="counter" class="mt-1 text-right text-xs tabular-nums text-ink-muted">
            {{ length }}<template v-if="maxlength"> / {{ maxlength }}</template>
        </p>
    </div>
</template>
