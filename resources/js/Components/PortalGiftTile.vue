<script setup>
import { computed, ref } from 'vue';
import PortalItemDescription from './PortalItemDescription.vue';
import PortalProductSheet from './PortalProductSheet.vue';
import ProductPhotoCarousel from './ProductPhotoCarousel.vue';
import UiBadge from './UiBadge.vue';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';

/**
 * Um presente na vitrine do Colaborador — a MESMA peça para os dois casos.
 *
 * Antes havia dois desenhos: o presente único virava um painel largo com a
 * foto gigante ao lado do texto, e as opções de um grupo viravam células de
 * uma grade. A pessoa que tinha um presente via uma tela; a que tinha três,
 * outra. Com um ladrilho só, a vitrine é a mesma vitrine — muda a quantidade,
 * não a linguagem.
 *
 * Largura FIXA, não fração da tela: é o que mantém o ladrilho do mesmo tamanho
 * com 1 ou com 6 presentes. Quem se ajusta é o espaço em volta (o container
 * centraliza), não o presente.
 *
 * SEM borda, com sombra: o ladrilho é branco sobre o creme do portal
 * (`bg-canvas`), e essa diferença já o destaca. A borda vinha por cima de um
 * cartão que também tinha borda — caixa dentro de caixa. Tirando-a, o único
 * contorno forte da tela passa a ser o produto, que é o que se quer olhar.
 */
const props = defineProps({
    /** Item de `gifts[]` do catálogo. */
    gift: { type: Object, required: true },
    /** Opção de grupo já indisponível porque a pessoa escolheu outra. */
    locked: { type: Boolean, default: false },
    /** "Quero este" num grupo de escolha; "Quero receber" num presente só dele. */
    actionLabel: { type: String, default: 'Quero receber' },
    /** Primeira foto da tela: é o LCP e baixa com prioridade. */
    eager: { type: Boolean, default: false },
});

/*
 * Vitrine de loja para presente com variação: as bolinhas das cores e a faixa
 * de tamanhos já no ladrilho. A escolha em si acontece na página do resgate.
 */
const variants = computed(() => props.gift.variants ?? []);

// A ficha do presente (lupa da foto ou clique na descrição).
const ficha = ref(false);

// A galeria do presente; sem ela, a capa (resgate antigo, tela em cache).
const fotos = computed(() => props.gift.product.images ?? [props.gift.product.image_url].filter(Boolean));

const cores = computed(() => {
    const vistas = new Map();
    for (const v of variants.value) {
        if (v.color && !vistas.has(v.color)) vistas.set(v.color, v.color_hex);
    }

    return [...vistas.entries()].map(([nome, hex]) => ({ nome, hex }));
});

const tamanhos = computed(() => [...new Set(variants.value.map((v) => v.size).filter(Boolean))]);

const faixaDeTamanhos = computed(() => {
    const t = tamanhos.value;
    if (t.length === 0) return null;
    if (t.length <= 4) return t.join(' · ');

    return `${t[0]} ao ${t[t.length - 1]}`;
});

const esgotado = computed(() => variants.value.length > 0 && variants.value.every((v) => !v.available));

const rotuloDaAcao = computed(() => {
    if (variants.value.length === 0) return props.actionLabel;

    return cores.value.length > 1 && tamanhos.value.length > 1
        ? 'Escolher cor e tamanho'
        : tamanhos.value.length > 1
            ? 'Escolher tamanho'
            : 'Escolher cor';
});
</script>

<template>
    <div
        class="flex w-full flex-col rounded-card bg-surface p-6 text-center shadow-card transition-shadow sm:w-72"
        :class="!locked && !gift.redeemed && 'hover:shadow-card-hover'"
    >
        <!-- Só o conteúdo apaga na opção travada: o selo do rodapé fica em
             contraste pleno para explicar o estado. -->
        <div :class="locked && 'opacity-60'">
            <!--
                `object-contain` dentro de uma moldura de altura fixa, NÃO
                `object-cover`.
                Com `cover` a imagem é ampliada até preencher e o que passa da
                moldura é cortado: uma foto quadrada perdia 44% da altura, e o
                produto saía sem topo e sem base. Aqui a foto inteira aparece,
                na proporção original, centrada nos dois eixos.

                A moldura tem altura fixa em TODOS os ladrilhos para que fotos
                de proporções diferentes (a Omie manda de tudo) não deixem as
                linhas serrilhadas. E não tem fundo próprio: foto de catálogo
                quase sempre vem com fundo branco recortado, e sobre uma chapa
                cinza ela virava um retângulo dentro de outro.
            -->
            <!-- Mais de uma foto vira carrossel: só a foto desliza, o resto
                 do ladrilho fica parado embaixo. -->
            <ProductPhotoCarousel
                :images="fotos"
                :alt="`Foto de ${gift.product.name}`"
                :eager="eager"
                ampliavel
                @ampliar="ficha = true"
            />

            <h3 class="mt-6 text-base font-semibold text-ink">{{ gift.product.name }}</h3>

            <!-- A descrição que a campanha escreveu para quem resgata (a do
                 catálogo vem da Omie e fica só na página de resgate). -->
            <PortalItemDescription
                v-if="gift.product.summary"
                :text="gift.product.summary"
                :title="gift.product.name"
                @abrir="ficha = true"
            />

            <!-- Variações: bolinhas das cores e a faixa de tamanhos, como na
                 vitrine de uma loja. Só leitura aqui; a escolha é no resgate. -->
            <div v-if="variants.length" class="mt-3 flex flex-col items-center gap-2">
                <ul v-if="cores.length > 1" class="flex flex-wrap justify-center gap-1.5" :aria-label="`${cores.length} cores`">
                    <li
                        v-for="cor in cores.slice(0, 7)"
                        :key="cor.nome"
                        class="size-5 rounded-full border border-line-strong"
                        :style="cor.hex ? { backgroundColor: cor.hex } : null"
                        :title="cor.nome"
                    >
                        <span class="sr-only">{{ cor.nome }}</span>
                    </li>
                    <li v-if="cores.length > 7" class="text-xs leading-5 text-ink-muted">+{{ cores.length - 7 }}</li>
                </ul>
                <p v-else-if="cores.length === 1" class="text-sm text-ink-muted">{{ cores[0].nome }}</p>

                <p v-if="faixaDeTamanhos" class="text-sm text-ink-muted">Tamanhos {{ faixaDeTamanhos }}</p>
            </div>

            <!-- A lista fica centrada como bloco, mas cada linha alinha o texto
                 à esquerda do próprio ícone: centralizar o texto DENTRO do item
                 deixa "2× Caneca" e "1× Bloco de notas" com começos
                 desalinhados, e a lista perde a coluna do olho. -->
            <ul
                v-if="gift.product.components.length"
                class="mt-3 inline-flex flex-col gap-1.5 text-left text-sm text-ink-muted"
            >
                <li v-for="(component, i) in gift.product.components" :key="i" class="flex items-start gap-2">
                    <UiIcon name="check" :size="14" class="mt-0.5 shrink-0 text-brand-600" />
                    <span class="min-w-0">{{ component.quantity }}× {{ component.name }}</span>
                </li>
            </ul>
        </div>

        <!--
            `mt-auto` encosta a ação no pé: com ladrilhos de alturas diferentes
            (um tem lista de componentes, outro não), os botões continuam
            alinhados entre si.

            O botão NÃO ocupa a largura toda. Três barras laranja chapadas lado
            a lado brigavam entre si e roubavam a atenção do produto — que é o
            que deveria decidir a escolha. Sólido ele continua, porque a ação
            precisa ser óbvia para quem abre isto pela primeira vez no celular;
            o que muda é parar de gritar três vezes.
        -->
        <div class="mt-auto flex justify-center pt-6">
            <!-- Presente já resgatado NÃO aparece como ladrilho: quem mostra é
                 o `PortalOrderCard`, largo, porque acompanhamento não cabe numa
                 coluna de 288px. Aqui sobra só o estado de quem não foi
                 escolhido e o de resgate pausado. -->
            <UiBadge v-if="locked" variant="neutral">Você já escolheu seu brinde</UiBadge>

            <div v-else-if="gift.blocked" class="w-full">
                <UiButton disabled>Resgate pausado</UiButton>
                <p class="mt-2 text-xs text-ink-muted">
                    Pausado temporariamente. Assim que liberarem, você recebe o aviso.
                </p>
            </div>

            <!-- Todas as variações esgotadas: o presente segue visível, com o
                 motivo, em vez de um botão que levaria a uma escolha vazia. -->
            <div v-else-if="esgotado" class="w-full">
                <UiButton disabled>Esgotado</UiButton>
                <p class="mt-2 text-xs text-ink-muted">Todas as opções esgotaram. Fale com o suporte.</p>
            </div>

            <UiButton v-else size="lg" :href="route('portal.redeem.form', gift.id)">
                {{ rotuloDaAcao }}
            </UiButton>
        </div>

        <!-- A ficha: foto grande com lupa e a descrição inteira. -->
        <PortalProductSheet
            v-model:open="ficha"
            :nome="gift.product.name"
            :fotos="fotos"
            :descricao="gift.product.summary"
            :pausado="!!gift.blocked"
            :travado="locked"
            :acao="esgotado ? null : { href: route('portal.redeem.form', gift.id), label: rotuloDaAcao }"
        />
    </div>
</template>
