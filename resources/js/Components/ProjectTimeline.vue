<script setup>
import { computed } from 'vue';
import UiIcon from './UiIcon.vue';
import {
    STAGE_IN_STOCK,
    formatQuantity,
    pipelineIcons,
    pipelineLabels,
    pipelineStages,
} from '../lib/format';

/**
 * Onde ESTE Projeto está na esteira.
 *
 * Diferente do fluxo do painel, que conta quantos Projetos há em cada etapa,
 * aqui só existe um percurso e um ponto. O selo de estágio sozinho respondia
 * "onde está" e escondia "quanto falta" — a etapa é um nome isolado, sem
 * antes nem depois.
 *
 * Três estados, e nenhum deles depende só de cor: o passado tem ✓, o atual
 * tem anel e peso, o futuro fica esmaecido.
 *
 * "Em Estoque" ganha marcação própria porque é o único estágio em que o
 * resgate pode abrir — é a linha que a operação inteira está esperando
 * cruzar, e não pode ler como mais uma no meio das seis.
 */
const props = defineProps({
    current: { type: String, required: true },
    /**
     * Sub-status da etapa de estoque, escrito sob o marcador dela.
     *
     * O selo dizia "abre o resgate" nos dois momentos — antes e depois de
     * alguém liberar —, então a esteira não distinguia o Projeto esperando
     * autorização do Projeto com resgate aberto. É justamente a diferença que
     * se procura ao olhar para ela.
     */
    subStatus: { type: String, default: null },
    released: { type: Boolean, default: false },
    /**
     * As duas últimas etapas vêm dos KITS, não do `pipeline_status`.
     *
     *   started  o primeiro kit já saiu → "Envio" acende
     *   ratio    kits entregues ÷ kits do pedido
     *   total    o que o pedido pede: 150 requeridos, 150 para concluir
     *
     * A conta é de KITS, não de resgates: uma solicitação de admin leva trinta
     * unidades numa linha só, e contar linhas daria o Projeto por concluído
     * com um quinto do pedido na rua.
     *
     * @type {{ started: boolean, ratio: number, delivered: number, total: number }}
     */
    shipping: {
        type: Object,
        default: () => ({ started: false, ratio: 0, delivered: 0, total: 0 }),
    },
});

const currentIndex = computed(() => pipelineStages.indexOf(props.current));

const stages = computed(() =>
    pipelineStages.map((status, index) => ({
        status,
        // Rótulo COMPLETO, inclusive "Estoque Disponível".
        //
        // Era encurtado para "Estoque" por causa da largura do nó, mas ali o
        // que se lê é justamente a etapa que a operação espera cruzar — e
        // "Estoque" sozinho não diz se o kit está disponível ou ainda a
        // caminho. A coluna ganhou largura mínima para acomodar as duas
        // linhas; o fluxo do painel, que é outra tela e outro espaço, segue
        // com o rótulo curto.
        label: pipelineLabels[status] ?? status,
        icon: pipelineIcons[status] ?? 'projects',
        state: estadoDe(status, index),
        gate: status === STAGE_IN_STOCK,
    })),
);

/**
 * "84/150 kits", escrito sob o marcador de Envio.
 *
 * A barra sozinha diz a proporção e esconde o número, que é o que a operação
 * precisa para saber quanto falta despachar. Só aparece depois do primeiro
 * envio: antes dele, "0/150" seria um zero sem informação nenhuma.
 */
const progressoDeEnvio = computed(() => {
    const { started, delivered, total } = props.shipping;

    return started && total > 0 ? `${formatQuantity(delivered)}/${formatQuantity(total)} kits` : null;
});

const indiceEnvio = pipelineStages.indexOf('shipping');

/**
 * Envio acende com o PRIMEIRO kit despachado e só ganha o check quando o
 * último chega: enquanto houver entrega pendente ele está em andamento, não
 * concluído. Concluído espera todas.
 *
 * Essas duas últimas etapas vêm dos resgates, não do `pipeline_status` — o
 * Trello não move o cartão a cada entrega, então o Projeto continua "Em
 * Estoque" enquanto os kits saem. A partir do primeiro envio as etapas
 * anteriores viram passado aqui, senão a esteira teria dois marcadores
 * "atual" ao mesmo tempo, o do estoque e o do envio.
 *
 * As outras quatro seguem o `pipeline_status`, que é quem as governa.
 */
function estadoDe(status, index) {
    const { started, ratio, total } = props.shipping;

    if (started) {
        if (status === 'shipping') return ratio >= 1 ? 'past' : 'current';
        if (status === 'done') return ratio >= 1 && total > 0 ? 'past' : 'ahead';
        if (index < indiceEnvio) return 'past';
    }

    // Estágio desconhecido (dado antigo) não pinta ninguém de "passado": -1
    // deixaria a esteira inteira no futuro, que é a leitura honesta.
    if (currentIndex.value === -1) return 'ahead';

    return index < currentIndex.value ? 'past' : index === currentIndex.value ? 'current' : 'ahead';
}

/**
 * Quanto do segmento à ESQUERDA de cada marcador está preenchido, em %.
 *
 * Só o trecho Envio→Concluído é parcial, e ele é a metade direita do Envio
 * mais a metade esquerda do Concluído. Com metade das entregas feitas, o
 * primeiro pedaço está cheio e o segundo vazio — daí o corte em 0,5.
 */
function preenchimentoEsquerdo(stage) {
    if (stage.status === 'done' && props.shipping.started) {
        return Math.max(0, Math.min(1, props.shipping.ratio * 2 - 1)) * 100;
    }

    return stage.state === 'ahead' ? 0 : 100;
}

function preenchimentoDireito(stage) {
    if (stage.status === 'shipping' && props.shipping.started) {
        return Math.min(1, props.shipping.ratio * 2) * 100;
    }

    return stage.state === 'past' ? 100 : 0;
}

const currentLabel = computed(() => pipelineLabels[props.current] ?? props.current);

</script>

<template>
    <div>
        <p class="sr-only">Etapa atual: {{ currentLabel }}.</p>

        <!--
            Colunas de largura IGUAL (`auto-cols`), não `flex-1` com mínimo.
            Com flex, o selo "Pendente de Liberação" — mais largo que a coluna
            — esticava só o item de Estoque e empurrava os seguintes: os
            centros deixavam de ser equidistantes e a esteira ficava torta.

            `pb-8` em todos reserva a altura do selo, que é posicionado
            absoluto. Sem a reserva, a única coluna com selo ficaria mais alta
            que as outras.

            `pt-1.5` no `ol`: `overflow-x-auto` também recorta na vertical, e
            sem essa folga a borda e a sombra do marcador ficavam raspando o
            topo da esteira.
        -->
        <ol class="scrollbar-slim grid grid-flow-col auto-cols-[minmax(7.5rem,1fr)] overflow-x-auto pt-1.5 pb-1">
            <li
                v-for="(stage, i) in stages"
                :key="stage.status"
                class="relative flex flex-col items-center gap-1.5 px-1 pb-8"
                :aria-current="stage.state === 'current' ? 'step' : null"
            >
                <!-- A linha liga os marcadores por trás; parte do centro de um
                     para o centro do outro, e por isso não existe na primeira.

                     Trilho cinza com uma barra colorida dentro, e não um
                     segmento que só sabe estar aceso ou apagado: o trecho
                     Envio→Concluído precisa encher aos poucos, na medida em
                     que os resgates vão sendo entregues. -->
                <span
                    v-if="i > 0"
                    aria-hidden="true"
                    class="absolute top-4 right-1/2 left-0 h-0.5 -translate-y-1/2 bg-line"
                >
                    <span
                        class="block h-full bg-trail transition-[width] duration-500"
                        :style="{ width: `${preenchimentoEsquerdo(stage)}%` }"
                    />
                </span>
                <span
                    v-if="i < stages.length - 1"
                    aria-hidden="true"
                    class="absolute top-4 right-0 left-1/2 h-0.5 -translate-y-1/2 bg-line"
                >
                    <span
                        class="block h-full bg-trail transition-[width] duration-500"
                        :style="{ width: `${preenchimentoDireito(stage)}%` }"
                    />
                </span>

                <span
                    :class="[
                        'relative z-10 flex size-8 items-center justify-center rounded-full border-2 transition-colors',
                        stage.state === 'past' && 'border-trail bg-trail text-ink-inverse shadow-trail',
                        stage.state === 'current' && `stage-dot stage-dot--${stage.status} border-current`,
                        stage.state === 'ahead' && 'border-line bg-surface text-ink-subtle',
                    ]"
                >
                    <UiIcon :name="stage.state === 'past' ? 'check' : stage.icon" :size="15" aria-hidden="true" />
                </span>

                <span
                    :class="[
                        'text-center text-xs leading-tight',
                        stage.state === 'current' ? 'font-semibold text-ink' : 'text-ink-muted',
                    ]"
                >
                    {{ stage.label }}
                </span>

                <!-- Absoluto e centrado no próprio marcador: assim o selo pode
                     ser mais largo que a coluna sem mover ninguém de lugar. -->
                <span
                    v-if="stage.gate"
                    :class="[
                        'absolute bottom-0 left-1/2 -translate-x-1/2 rounded-full px-2 py-0.5 text-center text-[0.625rem] leading-none font-semibold whitespace-nowrap',
                        subStatus
                            ? (released ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning')
                            : 'bg-surface-muted text-ink-muted',
                    ]"
                >
                    {{ subStatus ?? 'abre o resgate' }}
                </span>

                <!-- Mesmo lugar do selo de estoque, no marcador de Envio: o
                     número que a barra esconde. -->
                <span
                    v-else-if="stage.status === 'shipping' && progressoDeEnvio"
                    class="absolute bottom-0 left-1/2 -translate-x-1/2 rounded-full bg-surface-muted px-2 py-0.5 text-center text-[0.625rem] leading-none font-semibold whitespace-nowrap text-ink-muted tabular-nums"
                >
                    {{ progressoDeEnvio }}
                </span>
            </li>
        </ol>

        <!-- Sem linha de resumo embaixo: a etapa atual já está em destaque na
             própria esteira, com o marcador colorido e o sub-status. Repetir
             em texto ("Estoque Disponível · faltam 2 etapas") era dizer de
             novo o que a figura acabou de dizer. -->
    </div>
</template>

<style scoped>
/* O marcador da etapa atual veste a cor do próprio estágio — a mesma que o
   selo e o fluxo do painel usam, para as três telas concordarem. */
.stage-dot--design     { background-color: var(--color-stage-design-soft);     color: var(--color-stage-design); }
.stage-dot--production { background-color: var(--color-stage-production-soft); color: var(--color-stage-production); }
.stage-dot--assembly   { background-color: var(--color-stage-assembly-soft);   color: var(--color-stage-assembly); }
.stage-dot--shipping   { background-color: var(--color-stage-shipping-soft);   color: var(--color-stage-shipping); }
.stage-dot--in_stock   { background-color: var(--color-stage-stock-soft);      color: var(--color-stage-stock); }
.stage-dot--done       { background-color: var(--color-stage-done-soft);       color: var(--color-stage-done); }
</style>
