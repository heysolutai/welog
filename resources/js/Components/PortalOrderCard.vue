<script setup>
import { computed } from 'vue';
import PortalDeliverySteps from './PortalDeliverySteps.vue';
import UiBadge from './UiBadge.vue';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';
import { formatDate } from '../lib/format';

/**
 * O presente DEPOIS de resgatado: acompanhamento do pedido.
 *
 * É outro cartão, e não o ladrilho da vitrine com mais conteúdo dentro.
 * A pergunta mudou — antes era "o que eu ganho?", agora é "cadê?" — e a
 * resposta tem cinco blocos (selo, frase, dados, etapas, atalho) que não
 * cabem numa coluna de 288px.
 *
 * Duas decisões de enquadramento, ambas medidas e não estimadas:
 *
 * 1. As colunas viram lado a lado só a partir de `lg` (1024px), não de `sm`.
 *    Em `sm` a coluna da direita ficava com ~66px por etapa e os rótulos
 *    quebravam em duas linhas; pior, os marcadores perdiam a equidistância
 *    (vãos de 74/70/66px). Entre 640px e 1024px o cartão fica empilhado, a
 *    esteira ganha a largura inteira e volta a caber numa linha.
 *
 * 2. Lado a lado, as duas colunas são centradas entre si (`items-center`).
 *    Alinhadas pelo topo, a foto terminava na metade da altura e o resto da
 *    coluna esquerda virava um vazio.
 */
const props = defineProps({
    /** Item de `gifts[]` com `redemption` preenchido. */
    gift: { type: Object, required: true },
    /** Catálogo com mais de uma empresa: o cartão diz de quem é o presente. */
    showCompany: { type: Boolean, default: false },
});

const r = computed(() => props.gift.redemption);

const entregue = computed(() => !!(r.value?.delivered_at || r.value?.status === 'delivered'));
const enviado = computed(() => !!(r.value?.shipped_at || r.value?.status === 'shipped'));

/** 0-based: última etapa CONCLUÍDA do pedido. */
const doneStep = computed(() => {
    if (!r.value) return -1;
    if (entregue.value) return 3;
    if (enviado.value) return 2;

    return 0; // confirmado; "Em preparação" é a etapa atual
});

const steps = computed(() => {
    const definicao = [
        { label: 'Confirmado', data: r.value?.created_at },
        { label: 'Em preparação', data: null },
        { label: 'Em transporte', data: r.value?.shipped_at },
        { label: 'Entregue', data: r.value?.delivered_at },
    ];

    return definicao.map((etapa, index) => ({
        ...etapa,
        state: index <= doneStep.value
            ? 'done'
            : index === doneStep.value + 1 ? 'current' : 'ahead',
    }));
});

const badge = computed(() => {
    if (entregue.value) return { variant: 'success', label: 'Entregue' };
    if (enviado.value) return { variant: 'info', label: 'Em transporte' };

    return { variant: 'success', label: 'Pedido confirmado' };
});

const statusLine = computed(() => {
    if (entregue.value) return 'Seu presente foi entregue. Aproveite!';
    if (enviado.value) return 'Seu presente saiu para entrega e está a caminho.';

    return 'Seu pedido foi confirmado e está sendo preparado para envio.';
});

/** Dados do pedido; só entram os que existem, para não sobrar rótulo vazio. */
const meta = computed(() => {
    const linhas = [];

    if (r.value) linhas.push({ rotulo: 'Pedido', valor: `#${r.value.id}`, numerico: true });
    if (r.value?.created_at) linhas.push({ rotulo: 'Data do pedido', valor: formatDate(r.value.created_at) });
    linhas.push({ rotulo: 'Empresa', valor: props.gift.company });

    return linhas;
});
</script>

<template>
    <article class="w-full rounded-card bg-surface p-6 shadow-card sm:p-8">
        <!-- Coluna da foto com largura FIXA, e não uma fração: a esteira ao
             lado precisa do espaço que sobrar, e em fração ela encolhia junto
             com a tela até voltar a quebrar os rótulos. -->
        <div class="grid gap-6 lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-center lg:gap-10">
            <div class="flex h-44 items-center justify-center overflow-hidden sm:h-52">
                <img
                    v-if="gift.product.image_url"
                    :src="gift.product.image_url"
                    :alt="`Foto de ${gift.product.name}`"
                    loading="lazy"
                    decoding="async"
                    class="max-h-full max-w-full object-contain"
                />
                <UiIcon v-else name="gift" :size="64" :stroke-width="1.1" class="text-ink-subtle" aria-hidden="true" />
            </div>

            <div class="min-w-0">
                <h3 class="text-lg font-semibold text-ink">{{ gift.product.name }}</h3>
                <!-- A variação que a pessoa escolheu ("Preto · P"). -->
                <p v-if="gift.redemption?.variant" class="mt-0.5 text-base text-ink">{{ gift.redemption.variant }}</p>
                <p v-if="showCompany" class="mt-0.5 text-sm text-ink-muted">Presente de {{ gift.company }}</p>

                <UiBadge :variant="badge.variant" dot class="mt-3">{{ badge.label }}</UiBadge>

                <p class="mt-3 text-sm text-ink-muted">{{ statusLine }}</p>

                <!-- Dados do pedido numa faixa de apoio: sem borda, separados
                     por espaço. A caixa com contorno que havia aqui era mais
                     um retângulo dentro de um cartão que já é um retângulo.

                     Sem a caixa, quem separa esta faixa da esteira logo abaixo
                     é o ESPAÇO — e ele precisa ser maior que o espaço interno
                     da própria faixa, senão os dois blocos leem como um só e a
                     linha da esteira passa rente ao "Pedido #3". -->
                <dl class="mt-5 flex flex-wrap gap-x-10 gap-y-3">
                    <div v-for="item in meta" :key="item.rotulo" class="min-w-0">
                        <dt class="text-xs text-ink-muted">{{ item.rotulo }}</dt>
                        <dd :class="['text-sm font-medium text-ink', item.numerico && 'tabular-nums']">
                            {{ item.valor }}
                        </dd>
                    </div>
                </dl>

                <PortalDeliverySteps :steps="steps" class="mt-8" />

                <UiButton
                    class="mt-8"
                    size="lg"
                    icon-trailing="chevron-right"
                    :href="route('portal.redemptions')"
                >
                    Acompanhar entrega
                </UiButton>
            </div>
        </div>
    </article>
</template>
