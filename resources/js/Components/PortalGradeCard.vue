<script setup>
import { computed, ref } from 'vue';
import PortalItemDescription from './PortalItemDescription.vue';
import PortalOrderCard from './PortalOrderCard.vue';
import PortalProductSheet from './PortalProductSheet.vue';
import ProductPhotoCarousel from './ProductPhotoCarousel.vue';
import UiButton from './UiButton.vue';
import { compararOrdem } from '../lib/grade';

/**
 * Um presente com escolha de tamanho, montado a partir da grade da Omie.
 *
 * O pedido trouxe cada tamanho como item (Colete P, M, G…), e cada um é uma
 * opção do mesmo grupo de escolha. Para quem resgata, é UM presente — o
 * Colete — e a escolha é só o tamanho. Os sete cartões iguais que o grupo de
 * escolha mostraria fariam a pessoa achar que ganhou sete coletes.
 *
 * O cartão é do mesmo desenho dos outros: foto, nome, a faixa de tamanhos e
 * "Quero este". A escolha do tamanho é na ficha que o botão abre. Com os
 * botões de tamanho dentro do cartão, cada cartão ficava de uma altura e o
 * botão de resgate desalinhava do vizinho.
 *
 * O resgate segue o do tamanho escolhido: é o estoque DELE que baixa.
 */
const props = defineProps({
    // Os presentes do grupo, todos tamanhos do mesmo pai (gift.grade).
    options: { type: Array, required: true },
    showCompany: { type: Boolean, default: false },
    eager: { type: Boolean, default: false },
});

const escolhido = computed(() => props.options.find((o) => o.redeemed) ?? null);

const tamanhos = computed(() => [...props.options]
    .sort((a, b) => compararOrdem(a.grade.ordem, b.grade.ordem))
    .map((o) => ({ id: o.id, rotulo: o.grade.rotulo })));

/** "PP a G4", ou a lista inteira quando são poucos. */
const faixa = computed(() => {
    const r = tamanhos.value.map((t) => t.rotulo);
    if (r.length <= 4) return r.join(' · ');

    return `${r[0]} a ${r[r.length - 1]}`;
});

const pai = computed(() => props.options[0].grade);

// As fotos são as do pai, e só elas: os tamanhos não têm foto própria, e a
// que algum trouxe da Omie mostraria um tamanho como se fosse o produto.
const fotos = computed(() => pai.value.pai_fotos ?? []);

const pausado = computed(() => props.options.some((o) => o.blocked));

const ficha = ref(false);
</script>

<template>
    <!-- Depois do resgate: acompanhamento do tamanho escolhido, como em
         qualquer presente resgatado. -->
    <PortalOrderCard v-if="escolhido" :gift="escolhido" :show-company="showCompany" class="w-full" />

    <div v-else class="flex w-full flex-col rounded-card bg-surface p-6 text-center shadow-card transition-shadow hover:shadow-card-hover sm:w-72">
        <ProductPhotoCarousel
            :images="fotos"
            :alt="`Foto de ${pai.pai_nome}`"
            :eager="eager"
            ampliavel
            @ampliar="ficha = true"
        />

        <h3 class="mt-6 text-base font-semibold text-ink">{{ pai.pai_nome }}</h3>
        <PortalItemDescription
            v-if="options[0].product.summary"
            :text="options[0].product.summary"
            :title="pai.pai_nome"
            @abrir="ficha = true"
        />
        <p v-if="showCompany" class="mt-1 text-sm text-ink-muted">Presente de {{ options[0].company }}</p>

        <p class="mt-3 text-sm text-ink-muted">Tamanhos {{ faixa }}</p>

        <div class="mt-auto flex justify-center pt-6">
            <div v-if="pausado" class="w-full">
                <UiButton disabled>Resgate pausado</UiButton>
                <p class="mt-2 text-xs text-ink-muted">
                    Pausado temporariamente. Assim que liberarem, você recebe o aviso.
                </p>
            </div>

            <UiButton v-else size="lg" @click="ficha = true">Quero este</UiButton>
        </div>

        <!-- A ficha: foto grande com lupa, a descrição inteira e o tamanho. -->
        <PortalProductSheet
            v-model:open="ficha"
            :nome="pai.pai_nome"
            :fotos="fotos"
            :descricao="options[0].product.summary"
            :tamanhos="tamanhos"
            :pausado="pausado"
        />
    </div>
</template>
