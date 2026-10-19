<script setup>
import UiIcon from './UiIcon.vue';
import { formatDate } from '../lib/format';

/**
 * As quatro etapas da entrega — horizontal onde cabe, vertical onde não cabe.
 *
 * Não é preferência estética: é medida. Os rótulos "Em preparação" e "Em
 * transporte" precisam de ~90px cada para caber numa linha. Abaixo de 640px
 * de viewport a faixa disponível cai para ~80px por etapa e os dois quebram em
 * duas linhas, desalinhando a fileira inteira — foi o que aconteceu quando
 * esta esteira morava num ladrilho estreito.
 *
 * Então: horizontal a partir de `sm`, vertical abaixo. Na vertical cada etapa
 * tem a linha inteira para si e nunca quebra, e ali ela não custa altura ao
 * lado nenhum, porque nessa largura o cartão já está empilhado.
 *
 * Os dois desenhos existem no HTML e um deles fica em `display:none`, que
 * também o remove da árvore de acessibilidade — o leitor de tela lê uma
 * esteira só.
 *
 * Cor nunca sozinha: ✓ marca o que já aconteceu, o número marca o que falta.
 */
defineProps({
    /** [{ label, state: 'done'|'current'|'ahead', data: string|null }] */
    steps: { type: Array, required: true },
});

const marcador = (state) => [
    'flex shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
    state === 'done'
        ? 'bg-brand-600 text-ink-inverse'
        : state === 'current'
            ? 'border-2 border-brand-600 bg-surface text-brand-700'
            : 'border border-line bg-surface text-ink-subtle',
];

const rotulo = (state) =>
    state === 'current'
        ? 'font-semibold text-brand-700'
        : state === 'done'
            ? 'font-medium text-ink'
            : 'text-ink-muted';
</script>

<template>
    <!--
        Raiz ÚNICA envolvendo as duas orientações.

        Com dois elementos no topo, este componente seria multi-raiz — e o Vue
        NÃO repassa `class` para componente multi-raiz: ele descarta o atributo
        sem avisar em produção. O `mt-8` que o cartão passava aqui sumia, e a
        esteira encostava na faixa de dados logo acima (vão medido: 0px).
    -->
    <div>
    <!--
        Horizontal (sm+). Cada etapa ocupa uma fração IGUAL da largura
        (`flex-1`) com o marcador no centro dela e meia linha de cada lado — é
        o que mantém os quatro círculos equidistantes mesmo com rótulos de
        comprimentos diferentes.
    -->
    <ol class="hidden items-start sm:flex" aria-label="Etapas da entrega">
        <li v-for="(step, i) in steps" :key="step.label" class="flex flex-1 flex-col items-center">
            <div class="flex w-full items-center">
                <span
                    :class="[
                        'h-0.5 flex-1',
                        i === 0 ? 'bg-transparent' : step.state === 'ahead' ? 'bg-line' : 'bg-brand-600',
                    ]"
                />
                <span :class="[...marcador(step.state), 'size-7']">
                    <UiIcon v-if="step.state === 'done'" name="check" :size="13" />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span
                    :class="[
                        'h-0.5 flex-1',
                        i === steps.length - 1 ? 'bg-transparent' : step.state === 'done' ? 'bg-brand-600' : 'bg-line',
                    ]"
                />
            </div>

            <span :class="['mt-2 px-1 text-center text-xs leading-tight', rotulo(step.state)]">
                {{ step.label }}
            </span>
        </li>
    </ol>

    <!-- Vertical (abaixo de sm). A linha é filha do item para acompanhar a
         altura dele, qualquer que seja o texto ao lado. -->
    <ol class="sm:hidden" aria-label="Etapas da entrega">
        <li v-for="(step, i) in steps" :key="step.label" class="flex gap-3">
            <div class="flex flex-col items-center">
                <span :class="[...marcador(step.state), 'size-6']">
                    <UiIcon v-if="step.state === 'done'" name="check" :size="12" />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span
                    v-if="i < steps.length - 1"
                    :class="['w-0.5 flex-1', step.state === 'done' ? 'bg-brand-600' : 'bg-line']"
                    aria-hidden="true"
                />
            </div>

            <div :class="['min-w-0 flex-1', i < steps.length - 1 && 'pb-4']">
                <p :class="['text-sm leading-6', rotulo(step.state)]">{{ step.label }}</p>
                <p v-if="step.data" class="text-xs text-ink-muted">{{ formatDate(step.data) }}</p>
            </div>
        </li>
    </ol>
    </div>
</template>
