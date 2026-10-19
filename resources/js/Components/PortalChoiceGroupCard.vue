<script setup>
import { computed } from 'vue';
import PortalGiftTile from './PortalGiftTile.vue';
import PortalGradeCard from './PortalGradeCard.vue';
import PortalOrderCard from './PortalOrderCard.vue';
import { agruparPresentesPorGrade } from '../lib/grade';
import { preencher } from '../lib/portalTexts';

/**
 * Escolha flexível: brindes que dividem o mesmo `choice_group` são
 * alternativas e a pessoa fica com UMA.
 *
 * A tela tem dois momentos, e cada um pede uma peça diferente:
 *
 *   antes da escolha   vitrine — ladrilhos iguais, lado a lado
 *   depois da escolha  acompanhamento — cartão largo do pedido escolhido,
 *                      e as irmãs recolhidas abaixo
 *
 * Antes os dois cabiam no mesmo ladrilho de 288px, e o acompanhamento
 * (selo, frase, dados, quatro etapas e o botão) não cabia: a esteira quebrava
 * os rótulos em duas linhas e o nome da empresa saía com reticências.
 *
 * NÃO é um cartão. Era um `UiCard` com borda e, dentro dele, ladrilhos que
 * também tinham borda — dois contornos para dizer a mesma coisa. O que agrupa
 * as opções agora é o título acima delas e o espaço em volta.
 */
const props = defineProps({
    // Brindes do mesmo `choice_group`, na ordem em que vieram do catálogo.
    options: { type: Array, required: true },
    // Catálogo com mais de uma empresa: o bloco diz de quem é o presente.
    showCompany: { type: Boolean, default: false },
    // Primeiro bloco da tela: a primeira foto é o LCP e baixa com prioridade.
    firstImageEager: { type: Boolean, default: false },
});

const chosen = computed(() => props.options.find((option) => option.redeemed) ?? null);

// As opções como a pessoa as vê: os tamanhos de um mesmo pai contam como uma.
const entradas = computed(() => agruparPresentesPorGrade(props.options));

/**
 * As irmãs que ficaram pelo caminho — o histórico da escolha não some. A
 * grade aparece uma vez, com o nome do pai, e não tamanho por tamanho; a do
 * presente escolhido sai inteira.
 */
const naoEscolhidas = computed(() => entradas.value
    .filter((e) => (e.tipo === 'grade' ? !e.options.some((o) => o.redeemed) : !e.gift.redeemed))
    .map((e) => (e.tipo === 'grade'
        ? { id: e.key, nome: e.options[0].grade.pai_nome, foto: e.options[0].grade.pai_fotos?.[0] ?? null }
        : { id: e.key, nome: e.gift.product.name, foto: e.gift.product.image_url })));

/** As opções de um grupo pertencem ao mesmo projeto, logo à mesma empresa. */
const company = computed(() => props.options[0]?.company);

// `choice_locked` vem do servidor; o fallback cobre a aba aberta com payload
// antigo: se alguém do grupo já foi escolhido, as outras estão travadas.
const locked = (option) => option.choice_locked || (!!chosen.value && !option.redeemed);

// A chamada da escolha pode vir da campanha (Itens do pedido > Tela de
// resgate). É a do Projeto DESTE grupo: a de outra campanha não serve aqui.
const title = computed(() =>
    chosen.value
        ? 'Sua escolha'
        : preencher(props.options[0]?.escolha || 'Escolha 1 destas {n} opções', {
            n: entradas.value.length,
            empresa: company.value,
        }),
);

const description = computed(() =>
    chosen.value
        ? 'Acompanhe seu pedido abaixo.'
        : 'Você pode pedir apenas uma das opções abaixo. Leva 1 minuto: só precisamos '
            // Cliente que não coleta CPF não tem o campo no resgate.
            + (props.options[0]?.ask_cpf === false ? 'do seu endereço.' : 'do seu CPF e do endereço.'),
);
</script>

<template>
    <section class="w-full">
        <div class="mx-auto max-w-xl text-center">
            <h2 class="text-lg font-semibold text-ink">{{ title }}</h2>
            <p class="mt-1 text-sm text-ink-muted">
                <template v-if="showCompany">Presente de {{ company }}. </template>{{ description }}
            </p>
        </div>

        <!-- Depois da escolha: o pedido ocupa a tela e as recusadas viram
             rodapé. Mantê-las em tamanho cheio faria três cartões disputarem
             espaço com o único que ainda tem algo a dizer. -->
        <template v-if="chosen">
            <div class="mt-6">
                <PortalOrderCard :gift="chosen" :show-company="showCompany" />
            </div>

            <div v-if="naoEscolhidas.length" class="mt-6">
                <p class="text-center text-xs tracking-wide text-ink-subtle uppercase">
                    Opções não escolhidas
                </p>
                <ul class="mt-3 flex flex-wrap justify-center gap-2">
                    <li
                        v-for="option in naoEscolhidas"
                        :key="option.id"
                        class="flex items-center gap-2 rounded-control bg-surface px-3 py-2 text-sm text-ink-muted opacity-70"
                    >
                        <img
                            v-if="option.foto"
                            :src="option.foto"
                            alt=""
                            loading="lazy"
                            class="size-8 shrink-0 rounded object-contain"
                        />
                        {{ option.nome }}
                    </li>
                </ul>
            </div>
        </template>

        <!--
            Antes da escolha: vitrine em linhas de até 3, rolando para baixo,
            como os presentes à parte. O carrossel com setas escondia as
            opções seguintes, e a pessoa escolhia sem ter visto todas.
        -->
        <div v-else class="mt-6 flex flex-wrap justify-center gap-5">
            <template v-for="(entrada, index) in entradas" :key="entrada.key">
                <PortalGradeCard
                    v-if="entrada.tipo === 'grade'"
                    :options="entrada.options"
                    :eager="firstImageEager && index === 0"
                />
                <PortalGiftTile
                    v-else
                    :gift="entrada.gift"
                    :locked="locked(entrada.gift)"
                    action-label="Quero este"
                    :eager="firstImageEager && index === 0"
                />
            </template>
        </div>
    </section>
</template>
