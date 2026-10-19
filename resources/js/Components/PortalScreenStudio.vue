<script setup>
/**
 * A tela de resgate de uma campanha, escrita e vista ao mesmo tempo.
 *
 * Mesmo arranjo dos outros estúdios: em cima o que se escreve (os textos da
 * tela e o nome e a descrição de cada item), embaixo, com a largura toda, a
 * vitrine como o colaborador vai abri-la. A prévia usa os MESMOS cartões do
 * portal (PortalGiftTile, PortalGradeCard), não um desenho deles.
 *
 * Os textos são do Projeto: cada campanha tem a sua voz, e o mesmo item pode
 * ter um nome numa campanha e outro na seguinte.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PortalGiftTile from './PortalGiftTile.vue';
import PortalGradeCard from './PortalGradeCard.vue';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';
import { preencher } from '../lib/portalTexts';

const props = defineProps({
    project: { type: Object, required: true },
});

const config = computed(() => props.project.portal_texts ?? {});
const catalogo = computed(() => config.value.catalogo ?? {});
const empresa = computed(() => props.project.company?.name ?? '');

/*
 * Os itens principais do pedido: o pai da grade (com os tamanhos dentro) ou o
 * item isolado. A chave é o código do item principal, a mesma que o portal
 * usa para achar o texto.
 */
const itens = computed(() => {
    const porCodigo = new Map();

    for (const p of props.project.products) {
        const codigo = String(p.galeria?.omie_code ?? p.omie_code ?? '');
        const chave = codigo.toLowerCase();

        if (!porCodigo.has(chave)) {
            porCodigo.set(chave, {
                chave,
                codigo,
                nomeOriginal: p.grade?.pai_nome ?? p.galeria?.name ?? p.name,
                fotos: (p.galeria?.fotos ?? []).map((f) => f.url).concat(
                    p.galeria?.fotos?.length ? [] : [p.photo_url].filter(Boolean),
                ),
                grade: !!p.grade,
                produtos: [],
            });
        }

        porCodigo.get(chave).produtos.push(p);
    }

    return [...porCodigo.values()];
});

const gravado = () => ({
    ...Object.fromEntries(Object.keys(catalogo.value).map((k) => [k, config.value.textos?.[k] ?? ''])),
    itens: Object.fromEntries(itens.value.map((i) => [i.chave, {
        nome: config.value.itens?.[i.chave]?.nome ?? '',
        descricao: config.value.itens?.[i.chave]?.descricao ?? '',
    }])),
});

const form = useForm(gravado());

watch(() => props.project.portal_texts, () => form.defaults(gravado()).reset());

function salvar() {
    form.put(route('projects.portal-texts.update', props.project.id), {
        preserveScroll: true,
        preserveState: true,
    });
}

function descartar() {
    form.reset();
    form.clearErrors();
}

const exemplo = (chave) => preencher(catalogo.value[chave]?.padrao, { empresa: empresa.value, n: itens.value.length });

const nomeDe = (item) => form.itens[item.chave]?.nome.trim() || item.nomeOriginal;
const descricaoDe = (item) => form.itens[item.chave]?.descricao.trim() || null;

// ── Prévia ──────────────────────────────────────────────────────────────────
const modo = ref(itens.value.length > 1 ? 'escolha' : 'parte');

const MODOS = [
    { value: 'escolha', label: 'Escolha 1 entre os itens' },
    { value: 'parte', label: 'Itens à parte' },
];

const saudacaoPrevia = computed(() => preencher(form.saudacao.trim() || catalogo.value.saudacao?.padrao, { empresa: empresa.value }));
const escolhaPrevia = computed(() => preencher(form.escolha.trim() || catalogo.value.escolha?.padrao, {
    empresa: empresa.value,
    n: itens.value.length,
}));

/** O item como o portal o recebe, para os cartões de verdade desenharem. */
function presenteDe(item) {
    return {
        id: item.produtos[0].id,
        product: {
            name: nomeDe(item),
            summary: descricaoDe(item),
            image_url: item.fotos[0] ?? null,
            images: item.fotos,
            components: (item.produtos[0].components ?? []).map((c) => ({ name: c.name, quantity: c.quantity })),
        },
        variants: [],
        blocked: false,
        redeemed: false,
        company: empresa.value,
    };
}

function tamanhosDe(item) {
    return item.produtos.map((p) => ({
        ...presenteDe(item),
        id: p.id,
        grade: {
            pai_code: item.codigo,
            pai_nome: nomeDe(item),
            pai_fotos: item.fotos,
            rotulo: p.grade?.rotulo ?? p.name,
            ordem: p.grade?.ordem ?? [],
        },
    }));
}

const erroDoItem = (chave) => form.errors[`itens.${chave}.nome`] ?? form.errors[`itens.${chave}.descricao`];
</script>

<template>
    <div class="flex min-h-0 flex-col">
        <form id="form-tela-de-resgate" class="grid gap-8 lg:grid-cols-[22rem_minmax(0,1fr)]" @submit.prevent="salvar">
            <!-- ── Textos da tela ───────────────────────────────────────── -->
            <section aria-label="Textos da tela" class="min-w-0">
                <p class="mb-2 text-xs font-medium text-ink-muted">Textos da tela</p>
                <div class="divide-y divide-line overflow-hidden rounded-card border border-line bg-surface transition-colors focus-within:border-brand-300">
                    <label v-for="(texto, chave) in catalogo" :key="chave" class="block px-4 py-3">
                        <span class="block text-xs text-ink-muted">{{ texto.label }}</span>
                        <input
                            v-model="form[chave]"
                            type="text"
                            autocomplete="off"
                            :maxlength="texto.max"
                            :placeholder="exemplo(chave)"
                            class="mt-1 w-full bg-transparent text-sm text-ink outline-none placeholder:text-ink-subtle"
                        >
                    </label>
                </div>
                <p v-for="chave in Object.keys(catalogo)" v-show="form.errors[chave]" :key="`e-${chave}`" role="alert" class="mt-2 text-xs text-danger">
                    {{ form.errors[chave] }}
                </p>
                <p class="mt-3 text-xs text-ink-muted">
                    Vazio, vale o texto de exemplo. Use {empresa} e {n} para o nome da empresa e o número de opções.
                </p>
            </section>

            <!-- ── Itens ────────────────────────────────────────────────── -->
            <section aria-label="Itens do pedido" class="min-w-0">
                <p class="mb-2 text-xs font-medium text-ink-muted">Itens como o colaborador lê</p>
                <ul class="divide-y divide-line overflow-hidden rounded-card border border-line bg-surface">
                    <li v-for="item in itens" :key="item.chave" class="flex gap-3 px-4 py-3">
                        <span class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-control border border-line bg-surface">
                            <img v-if="item.fotos[0]" :src="item.fotos[0]" alt="" class="max-h-full max-w-full object-contain">
                            <UiIcon v-else name="image" :size="18" class="text-ink-subtle" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="form.itens[item.chave].nome"
                                    type="text"
                                    autocomplete="off"
                                    :maxlength="config.item_nome_max"
                                    :placeholder="item.nomeOriginal"
                                    :aria-label="`Nome no portal de ${item.nomeOriginal}`"
                                    class="min-w-0 flex-1 bg-transparent text-sm font-medium text-ink outline-none placeholder:font-normal placeholder:text-ink-muted"
                                >
                                <span class="shrink-0 font-mono text-[11px] text-ink-subtle">{{ item.codigo }}</span>
                            </div>
                            <textarea
                                v-model="form.itens[item.chave].descricao"
                                rows="2"
                                :maxlength="config.item_descricao_max"
                                placeholder="Descrição para quem resgata (opcional)"
                                :aria-label="`Descrição no portal de ${item.nomeOriginal}`"
                                class="mt-1 block w-full resize-none bg-transparent text-sm leading-relaxed text-ink-muted outline-none placeholder:text-ink-subtle"
                            />
                            <p v-if="item.grade" class="text-xs text-ink-subtle">
                                {{ item.produtos.length }} {{ item.produtos.length === 1 ? 'tamanho' : 'tamanhos' }}: a pessoa escolhe no cartão.
                            </p>
                            <p v-if="erroDoItem(item.chave)" role="alert" class="mt-1 text-xs text-danger">{{ erroDoItem(item.chave) }}</p>
                        </div>
                    </li>
                </ul>
                <p class="mt-3 text-xs text-ink-muted">
                    Vazio, vale o nome do catálogo. A descrição aparece no cartão e na página de resgate.
                </p>
            </section>
        </form>

        <!-- ── Prévia, com a largura toda ───────────────────────────────── -->
        <section aria-label="Prévia da tela de resgate" class="mt-8">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Como vai aparecer</h3>
                    <p class="text-xs text-ink-muted">A vitrine do colaborador com os textos acima.</p>
                </div>
                <div class="inline-flex rounded-full bg-surface-muted p-0.5 text-xs font-medium">
                    <button
                        v-for="m in MODOS"
                        :key="m.value"
                        type="button"
                        :aria-pressed="modo === m.value"
                        :class="[
                            'rounded-full px-3.5 py-1.5 transition-colors',
                            modo === m.value ? 'bg-surface text-ink shadow-xs' : 'text-ink-muted hover:text-ink',
                        ]"
                        @click="modo = m.value"
                    >
                        {{ m.label }}
                    </button>
                </div>
            </div>

            <!-- O fundo e as sombras do portal (data-surface), para os cartões
                 saírem como o colaborador os vê. `inert`: é para ver. -->
            <div data-surface="portal" inert class="overflow-hidden rounded-card bg-canvas px-4 py-8 sm:px-8">
                <div class="mx-auto max-w-5xl">
                    <div class="mb-8">
                        <h1 class="text-title font-bold text-ink">Olá, Maria</h1>
                        <p class="mt-1.5 text-base text-ink-muted">{{ saudacaoPrevia }}</p>
                    </div>

                    <div v-if="modo === 'escolha'" class="mx-auto mb-6 max-w-xl text-center">
                        <h2 class="text-lg font-semibold text-ink">{{ escolhaPrevia }}</h2>
                        <p class="mt-1 text-sm text-ink-muted">Você pode pedir apenas uma das opções abaixo.</p>
                    </div>

                    <div class="flex flex-wrap justify-center gap-5">
                        <template v-for="item in itens" :key="item.chave">
                            <PortalGradeCard v-if="item.grade" :options="tamanhosDe(item)" />
                            <PortalGiftTile
                                v-else
                                :gift="presenteDe(item)"
                                :action-label="modo === 'escolha' ? 'Quero este' : 'Quero receber'"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Ações ────────────────────────────────────────────────────── -->
        <!-- Fixa embaixo: no modal o conteúdo rola, e salvar não pode ficar
             escondido depois da prévia. -->
        <div
            class="sticky bottom-0 z-10 -mb-1 mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface pt-4 pb-1"
        >
            <span v-if="!form.isDirty" class="flex items-center gap-1 text-xs text-ink-muted">
                <UiIcon name="check" :size="13" />
                Tudo salvo
            </span>
            <UiButton v-if="form.isDirty" variant="ghost" size="sm" @click="descartar">Descartar</UiButton>
            <UiButton
                type="submit"
                form="form-tela-de-resgate"
                size="sm"
                :disabled="!form.isDirty"
                :loading="form.processing"
            >
                Salvar textos
            </UiButton>
        </div>
    </div>
</template>
