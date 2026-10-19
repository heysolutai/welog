<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
import { pipelineIcons, pipelineShortLabels } from '../lib/format';

/**
 * Fluxo dos projetos pela esteira, em chevrons encaixados.
 *
 * A forma importa: setas encaixadas dizem "isto anda para a direita" sem
 * precisar de legenda, e cada etapa tem matiz próprio para o olho achar onde
 * está. Uma fileira de caixas iguais mostra os mesmos números e não comunica
 * percurso nenhum.
 *
 * Cada etapa é um <Link> para a lista já filtrada: o número é a pergunta
 * ("8 em Design"), a lista filtrada é a resposta.
 */
const props = defineProps({
    // [{ status, total, percent }] — já na ordem do pipeline
    stages: { type: Array, required: true },
    hrefFor: { type: Function, default: null },
    updatedAt: { type: String, default: null },
});

const total = computed(() => props.stages.reduce((n, s) => n + s.total, 0));
const done = computed(() => props.stages.find((s) => s.status === 'done')?.total ?? 0);
const inProgress = computed(() => total.value - done.value);
const donePercent = computed(() => (total.value > 0 ? Math.round((done.value / total.value) * 100) : 0));

// Rótulo curto: dentro do chevron de 112px o completo de "Estoque: pronto
// para resgate/envio" empilharia três linhas e desalinharia a esteira.
const label = (status) => pipelineShortLabels[status] ?? status;
</script>

<template>
    <div>
        <ol class="scrollbar-slim flex items-stretch gap-1.5 overflow-x-auto pb-2">
            <li v-for="(stage, i) in stages" :key="stage.status" class="min-w-0 flex-1">
                <component
                    :is="hrefFor ? Link : 'div'"
                    :href="hrefFor ? hrefFor(stage.status) : undefined"
                    :class="[
                        'stage group relative flex h-full min-w-28 flex-col items-center justify-center gap-1.5 px-3 py-3.5 text-center transition-all',
                        i === 0 ? 'stage--first' : '',
                        i === stages.length - 1 ? 'stage--last' : '',
                        stage.total > 0 ? `stage--on stage--${stage.status}` : 'stage--off',
                        hrefFor && 'cursor-pointer hover:-translate-y-0.5',
                    ]"
                    :style="{ '--stage-delay': `${i * 45}ms` }"
                >
                    <span class="flex size-9 items-center justify-center rounded-full bg-surface/75 shadow-xs">
                        <UiIcon :name="pipelineIcons[stage.status] ?? 'projects'" :size="17" aria-hidden="true" />
                    </span>

                    <span class="text-[11px] leading-none font-medium opacity-80">{{ label(stage.status) }}</span>

                    <span class="flex items-baseline gap-1">
                        <span class="text-xl leading-none font-bold tabular-nums">{{ stage.total }}</span>
                        <span class="text-[11px] tabular-nums opacity-70">{{ stage.percent }}%</span>
                    </span>
                </component>
            </li>
        </ol>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <div
                class="h-1.5 min-w-40 flex-1 overflow-hidden rounded-full bg-surface-muted"
                role="img"
                :aria-label="`${donePercent}% dos projetos concluídos`"
            >
                <div
                    class="h-full rounded-full bg-brand-600 transition-[width] duration-700 ease-out"
                    :style="{ width: `${donePercent}%` }"
                />
            </div>

            <p class="flex items-center gap-3 text-xs text-ink-muted">
                <span class="font-medium text-ink">
                    {{ inProgress }} {{ inProgress === 1 ? 'em andamento' : 'em andamento' }}
                </span>
                <span aria-hidden="true" class="text-ink-subtle">·</span>
                <span>{{ done }} {{ done === 1 ? 'concluído' : 'concluídos' }}</span>
                <template v-if="updatedAt">
                    <span aria-hidden="true" class="text-ink-subtle">·</span>
                    <span>{{ updatedAt }}</span>
                </template>
            </p>
        </div>
    </div>
</template>

<style scoped>
/* Chevron encaixado: o entalhe à esquerda recebe a ponta do anterior. O
   primeiro não tem entalhe e o último não tem ponta, para a esteira ter
   começo e fim visíveis. */
.stage {
    --notch: 0.85rem;
    clip-path: polygon(
        0 0,
        calc(100% - var(--notch)) 0,
        100% 50%,
        calc(100% - var(--notch)) 100%,
        0 100%,
        var(--notch) 50%
    );
    border-radius: 0.5rem;
}

.stage--first {
    clip-path: polygon(0 0, calc(100% - var(--notch)) 0, 100% 50%, calc(100% - var(--notch)) 100%, 0 100%);
}

.stage--last {
    clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%, var(--notch) 50%);
}

.stage--first.stage--last {
    clip-path: none;
}

.stage--off {
    background-color: var(--color-surface-muted);
    color: var(--color-ink-subtle);
}

/* Um matiz por etapa: a cor diz POSIÇÃO na esteira, não juízo. */
.stage--design     { background-color: var(--color-stage-design-soft);     color: var(--color-stage-design); }
.stage--production { background-color: var(--color-stage-production-soft); color: var(--color-stage-production); }
.stage--assembly   { background-color: var(--color-stage-assembly-soft);   color: var(--color-stage-assembly); }
.stage--shipping   { background-color: var(--color-stage-shipping-soft);   color: var(--color-stage-shipping); }
.stage--in_stock   { background-color: var(--color-stage-stock-soft);      color: var(--color-stage-stock); }
.stage--done       { background-color: var(--color-stage-done-soft);       color: var(--color-stage-done); }

/* Entrada em cascata da esquerda para a direita: reforça a leitura de
   percurso no primeiro olhar, e só na primeira renderização. */
.stage--on {
    animation: stage-in 0.42s var(--ease-out-soft) both;
    animation-delay: var(--stage-delay, 0ms);
}

@keyframes stage-in {
    from { opacity: 0; transform: translateX(-8px); }
    to   { opacity: 1; transform: translateX(0); }
}

@media (prefers-reduced-motion: reduce) {
    .stage--on { animation: none; }
    .stage { transition: none; }
    .stage:hover { transform: none; }
}
</style>
