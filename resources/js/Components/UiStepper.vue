<script setup>
import UiIcon from './UiIcon.vue';

/**
 * Trilha de etapas vertical do padrão "telas de cadastro" (nasceu em
 * Projects/Create). Mostra onde a pessoa está e o que já ficou pronto.
 *
 *   <UiStepper :steps="[{ title, hint, done }]" :current="2" />
 *
 * `current` é 1-based. Etapa `done` ganha o círculo cheio com ✓; a atual,
 * anel na cor da marca; as futuras ficam neutras.
 */
defineProps({
    steps: { type: Array, required: true }, // [{ title, hint?, done? }]
    current: { type: Number, required: true },
});
</script>

<template>
    <ol class="rounded-card bg-surface shadow-card p-5" aria-label="Etapas">
        <li v-for="(step, index) in steps" :key="step.title" class="relative flex gap-3 pb-6 last:pb-0">
            <span
                v-if="index < steps.length - 1"
                class="absolute top-8 left-[13px] h-[calc(100%-2rem)] w-px bg-line"
                aria-hidden="true"
            />
            <span
                :class="[
                    'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                    step.done
                        ? 'bg-brand-600 text-ink-inverse'
                        : current === index + 1
                            ? 'border-2 border-brand-600 bg-surface text-brand-700'
                            : 'border border-line bg-surface text-ink-muted',
                ]"
            >
                <UiIcon v-if="step.done" name="check" :size="14" />
                <template v-else>{{ index + 1 }}</template>
            </span>
            <div class="min-w-0">
                <p :class="['text-sm font-semibold', current === index + 1 ? 'text-brand-700' : 'text-ink']">
                    {{ step.title }}
                </p>
                <p v-if="step.hint" class="text-xs text-ink-muted">{{ step.hint }}</p>
            </div>
        </li>
    </ol>
</template>
