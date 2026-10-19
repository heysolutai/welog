<script setup>
import { computed, ref } from 'vue';
import UiIcon from './UiIcon.vue';

/**
 * As fotos de um presente, na moldura do cartão.
 *
 * Uma foto: a imagem, como sempre foi. Mais de uma: um carrossel que mexe SÓ
 * a foto, e o resto do cartão (nome, tamanhos, botão) fica parado embaixo.
 * No celular desliza com o dedo; no computador, setas aparecem ao passar o
 * mouse. As bolinhas dizem quantas são e em qual se está.
 *
 * O deslizar é o do próprio navegador (scroll-snap), não um cálculo de
 * arrasto: funciona com o dedo, a roda do mouse e o teclado sem código para
 * cada um.
 */
const props = defineProps({
    images: { type: Array, default: () => [] },
    alt: { type: String, required: true },
    // A primeira foto da tela é o LCP e baixa com prioridade.
    eager: { type: Boolean, default: false },
    // Altura da moldura: a mesma em todos os cartões, para as linhas alinharem.
    frameClass: { type: String, default: 'h-52' },
    iconSize: { type: Number, default: 60 },
    // Cartão da vitrine: a lupa aparece na foto e clicar abre a ficha.
    ampliavel: { type: Boolean, default: false },
});

const emit = defineEmits(['ampliar']);

const ampliar = () => props.ampliavel && emit('ampliar');

/*
 * A lupa no canto da foto: aparece ao passar o mouse no computador e fica
 * sempre à vista no celular, onde não há mouse para revelá-la.
 */
const lupaClasses = 'absolute top-0 right-0 flex size-9 items-center justify-center rounded-full bg-surface text-ink '
    + 'shadow-card ring-1 ring-line transition hover:scale-105 focus-visible:opacity-100 '
    + 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 '
    + 'sm:opacity-0 sm:group-hover/foto:opacity-100';

const fotos = computed(() => props.images.filter(Boolean));
const varias = computed(() => fotos.value.length > 1);

const trilho = ref(null);
const atual = ref(0);

function aoRolar() {
    const el = trilho.value;
    if (!el || !el.clientWidth) return;

    atual.value = Math.round(el.scrollLeft / el.clientWidth);
}

function ir(indice) {
    const el = trilho.value;
    if (!el) return;

    const destino = (indice + fotos.value.length) % fotos.value.length;
    el.scrollTo({ left: destino * el.clientWidth, behavior: 'smooth' });
}
</script>

<template>
    <div v-if="!fotos.length" :class="['flex items-center justify-center', frameClass]">
        <!-- Presente sem foto: ícone da marca, não um cinza com "sem imagem":
             o buraco faria o presente parecer defeito de cadastro. -->
        <UiIcon name="gift" :size="iconSize" :stroke-width="1.1" class="text-ink-subtle" aria-hidden="true" />
    </div>

    <div v-else-if="!varias" :class="['group/foto relative flex items-center justify-center overflow-hidden', frameClass]">
        <img
            :class="ampliavel && 'cursor-zoom-in'"
            @click="ampliar"
            :src="fotos[0]"
            :alt="alt"
            :loading="eager ? 'eager' : 'lazy'"
            :fetchpriority="eager ? 'high' : null"
            decoding="async"
            class="max-h-full max-w-full object-contain"
        />
        <button
            v-if="ampliavel"
            type="button"
            :class="lupaClasses"
            :aria-label="`Ampliar ${alt}`"
            @click="ampliar"
        >
            <UiIcon name="search" :size="16" />
        </button>
    </div>

    <div
        v-else
        class="group/foto relative"
        role="group"
        aria-roledescription="carrossel"
        :aria-label="`${alt}, ${fotos.length} fotos`"
    >
        <div
            ref="trilho"
            :class="[
                'flex snap-x snap-mandatory overflow-x-auto overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
                frameClass,
            ]"
            tabindex="0"
            @scroll.passive="aoRolar"
        >
            <div
                v-for="(foto, i) in fotos"
                :key="foto + i"
                class="flex h-full w-full shrink-0 snap-center items-center justify-center"
                :aria-label="`Foto ${i + 1} de ${fotos.length}`"
            >
                <img
                    :src="foto"
                    :alt="`${alt} (${i + 1} de ${fotos.length})`"
                    :loading="eager && i === 0 ? 'eager' : 'lazy'"
                    :fetchpriority="eager && i === 0 ? 'high' : null"
                    decoding="async"
                    draggable="false"
                    :class="['max-h-full max-w-full object-contain select-none', ampliavel && 'cursor-zoom-in']"
                    @click="ampliar"
                />
            </div>
        </div>

        <!-- Setas sempre à vista, no computador e no celular: escondidas até
             o mouse passar, ninguém sabia que havia mais fotos. O centro
             desconta os pontos embaixo, para a seta cair no meio da foto. -->
        <button
            type="button"
            class="absolute top-[calc(50%-0.75rem)] left-0 flex size-9 -translate-y-1/2 items-center justify-center rounded-full bg-surface text-ink shadow-card ring-1 ring-line transition hover:scale-105 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            aria-label="Foto anterior"
            @click="ir(atual - 1)"
        >
            <UiIcon name="chevronLeft" :size="18" />
        </button>
        <button
            type="button"
            class="absolute top-[calc(50%-0.75rem)] right-0 flex size-9 -translate-y-1/2 items-center justify-center rounded-full bg-surface text-ink shadow-card ring-1 ring-line transition hover:scale-105 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            aria-label="Próxima foto"
            @click="ir(atual + 1)"
        >
            <UiIcon name="chevronRight" :size="18" />
        </button>

        <button
            v-if="ampliavel"
            type="button"
            :class="lupaClasses"
            :aria-label="`Ampliar ${alt}`"
            @click="ampliar"
        >
            <UiIcon name="search" :size="16" />
        </button>

        <!-- O ponto é pequeno, o alvo não: o botão em volta tem altura de dedo. -->
        <div class="mt-1 flex items-center justify-center">
            <button
                v-for="(foto, i) in fotos"
                :key="'b' + i"
                type="button"
                class="group/ponto flex h-6 items-center px-0.75"
                :aria-label="`Ver foto ${i + 1} de ${fotos.length}`"
                :aria-current="atual === i ? 'true' : undefined"
                @click="ir(i)"
            >
                <span
                    :class="[
                        'block h-1.5 rounded-full transition-all',
                        atual === i ? 'w-4 bg-ink' : 'w-1.5 bg-line-strong group-hover/ponto:bg-ink-subtle',
                    ]"
                />
            </button>
        </div>
    </div>
</template>
