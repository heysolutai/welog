<script setup>
/**
 * Personalizar a página de primeiro acesso de um Cliente, vendo a página.
 *
 * Mesmo arranjo do Estúdio de mensagens: em cima o que se escreve (o
 * endereço e o aviso de "Não achou?"), embaixo, com a largura toda, a página
 * como o colaborador vai abri-la, no computador ou no celular, antes e
 * depois de pedir o link. Os campos soltos na lista de Customizações não
 * diziam ONDE cada texto ia parar.
 *
 * A prévia é a tela de verdade (FirstAccessScreen), reduzida: a mesma que o
 * colaborador abre, e não um desenho dela.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FirstAccessScreen, { DICA_PADRAO } from './FirstAccessScreen.vue';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';
import { useToast } from '../composables/useToast';

const props = defineProps({
    company: { type: Object, required: true },
});

const toast = useToast();

const gravado = () => ({
    slug: props.company.first_access?.slug ?? '',
    dica: props.company.first_access?.dica ?? '',
});

const form = useForm(gravado());

watch(
    () => props.company.first_access,
    () => form.defaults(gravado()).reset(),
);

function salvar() {
    form.put(route('admin.companies.first-access.update', props.company.id), {
        preserveScroll: true,
        preserveState: true,
    });
}

function descartar() {
    form.reset();
    form.clearErrors();
}

// ── Endereço ────────────────────────────────────────────────────────────────
const base = computed(() => props.company.first_access?.base_url ?? '');
const baseSemProtocolo = computed(() => base.value.replace(/^https?:\/\//, ''));

/** O endereço como o servidor vai gravar ("Sotreq Brindes" vira "sotreq-brindes"). */
const slugLimpo = (texto) =>
    String(texto ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

const slugPrevia = computed(() => slugLimpo(form.slug));

/** O link que vale AGORA: o gravado, não o que está sendo digitado. */
const linkPublicado = computed(() => base.value + (props.company.first_access?.slug ?? ''));

async function copiarLink() {
    try {
        await navigator.clipboard.writeText(linkPublicado.value);
        toast.success('Link copiado.');
    } catch {
        toast.error('Não foi possível copiar. Selecione o link e copie.');
    }
}

// ── Prévia ──────────────────────────────────────────────────────────────────
const estado = ref('pedido');
const dispositivo = ref('computador');

const ESTADOS = [
    { value: 'pedido', label: 'Pedir o link' },
    { value: 'enviado', label: 'Link enviado' },
];

/*
 * A página é desenhada no tamanho real de cada aparelho e reduzida para
 * caber no quadro. Desenhar direto no tamanho do quadro mostraria outra
 * página: num quadro de 900px a tela do computador já viraria a de tablet.
 */
const TELAS = {
    computador: { largura: 1280, altura: 800 },
    celular: { largura: 390, altura: 760 },
};

const janela = ref(null);
const larguraDaJanela = ref(0);
let observador = null;

onMounted(() => {
    observador = new ResizeObserver(([entrada]) => (larguraDaJanela.value = entrada.contentRect.width));
    observador.observe(janela.value);
});

onBeforeUnmount(() => observador?.disconnect());

const tela = computed(() => TELAS[dispositivo.value]);
const escala = computed(() => (larguraDaJanela.value ? larguraDaJanela.value / tela.value.largura : 0));

// O formulário da prévia: vazio, como o colaborador o encontra.
const formDeExemplo = reactive({ email: '', errors: {}, processing: false });
const EMAIL_DE_EXEMPLO = 'maria.souza@empresa.com.br';

const nomeDaEmpresa = computed(() => props.company.display_name || props.company.name);
const dicaPrevia = computed(() => form.dica.trim() || null);
</script>

<template>
    <div class="flex min-h-0 flex-col">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <!-- ── O que se escreve ─────────────────────────────────────── -->
            <form id="form-primeiro-acesso" aria-label="Personalizar a página" class="min-w-0" @submit.prevent="salvar">
                <div
                    class="overflow-hidden rounded-card border border-line bg-surface transition-colors focus-within:border-brand-300"
                >
                    <label class="flex items-baseline gap-3 border-b border-line px-4 py-3">
                        <span class="w-16 shrink-0 text-sm text-ink-muted">Endereço</span>
                        <span class="flex min-w-0 flex-1 items-baseline">
                            <span class="hidden shrink-0 text-sm text-ink-subtle sm:inline">{{ baseSemProtocolo }}</span>
                            <input
                                v-model="form.slug"
                                type="text"
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="sotreq"
                                maxlength="60"
                                class="min-w-0 flex-1 bg-transparent text-sm font-medium text-ink outline-none placeholder:font-normal placeholder:text-ink-subtle"
                                :aria-invalid="!!form.errors.slug"
                                @focus="estado = 'pedido'"
                            >
                        </span>
                    </label>

                    <!-- O aviso só existe depois do envio: focar nele mostra a
                         prévia nesse momento. -->
                    <label class="flex items-baseline gap-3 px-4 py-3">
                        <span class="w-16 shrink-0 text-sm text-ink-muted">Aviso</span>
                        <input
                            v-model="form.dica"
                            type="text"
                            autocomplete="off"
                            maxlength="300"
                            :placeholder="DICA_PADRAO"
                            class="min-w-0 flex-1 bg-transparent text-sm text-ink outline-none placeholder:text-ink-subtle"
                            :aria-invalid="!!form.errors.dica"
                            @focus="estado = 'enviado'"
                        >
                    </label>
                </div>

                <p
                    v-for="(erro, campo) in form.errors"
                    :key="campo"
                    role="alert"
                    class="mt-2 text-xs font-medium text-danger"
                >
                    {{ erro }}
                </p>

                <ul class="mt-3 space-y-1 text-xs text-ink-muted">
                    <li>O aviso aparece depois que o link é enviado. Vazio, vale o padrão.</li>
                    <li>Trocar o endereço mantém o antigo funcionando: quem abrir o link antigo é levado ao novo.</li>
                </ul>
            </form>

            <!-- ── O link que o Cliente publica ─────────────────────────── -->
            <section aria-label="Link da página" class="min-w-0">
                <p class="text-xs font-medium text-ink-muted">Link para a intranet</p>
                <p class="mt-1.5 text-sm break-all text-ink">{{ linkPublicado }}</p>
                <p v-if="form.isDirty && slugPrevia !== company.first_access?.slug" class="mt-1 text-xs text-ink-muted">
                    O endereço novo vale depois de salvar.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <UiButton variant="secondary" size="sm" icon="copy" @click="copiarLink">Copiar link</UiButton>
                    <!-- Âncora comum: o link do UiButton é do Inertia e abriria
                         a página do colaborador dentro desta sessão. -->
                    <a
                        :href="linkPublicado"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex h-control-sm items-center gap-1.5 rounded-control px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-muted hover:text-ink"
                    >
                        <UiIcon name="external" :size="15" />
                        Abrir
                    </a>
                </div>
            </section>
        </div>

        <!-- ── Prévia, com a largura toda ───────────────────────────────── -->
        <section aria-label="Prévia da página" class="mt-8">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Como vai aparecer</h3>
                    <p class="text-xs text-ink-muted">Do jeito que o colaborador da {{ nomeDaEmpresa }} vai abrir.</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <div class="inline-flex rounded-full bg-surface-muted p-0.5 text-xs font-medium">
                        <button
                            v-for="e in ESTADOS"
                            :key="e.value"
                            type="button"
                            :aria-pressed="estado === e.value"
                            :class="[
                                'rounded-full px-3.5 py-1.5 transition-colors',
                                estado === e.value ? 'bg-surface text-ink shadow-xs' : 'text-ink-muted hover:text-ink',
                            ]"
                            @click="estado = e.value"
                        >
                            {{ e.label }}
                        </button>
                    </div>

                    <div class="inline-flex rounded-full bg-surface-muted p-0.5 text-xs font-medium">
                        <button
                            v-for="d in ['computador', 'celular']"
                            :key="d"
                            type="button"
                            :aria-pressed="dispositivo === d"
                            :class="[
                                'rounded-full px-3.5 py-1.5 capitalize transition-colors',
                                dispositivo === d ? 'bg-surface text-ink shadow-xs' : 'text-ink-muted hover:text-ink',
                            ]"
                            @click="dispositivo = d"
                        >
                            {{ d }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="rounded-card bg-canvas px-4 py-8 sm:px-8">
                <div
                    :class="[
                        'mx-auto overflow-hidden',
                        dispositivo === 'celular'
                            ? 'max-w-[20rem] rounded-[2.25rem] border-[7px] border-ink/85 bg-surface'
                            : 'max-w-4xl rounded-xl bg-surface shadow-card ring-1 ring-line',
                    ]"
                >
                    <!-- A barra do navegador, com o endereço sendo digitado:
                         é o único lugar onde o campo Endereço "aparece". -->
                    <div
                        :class="[
                            'flex items-center gap-3 border-b border-line bg-surface-muted',
                            dispositivo === 'computador' ? 'px-4 py-2.5' : 'px-3 pt-3 pb-2',
                        ]"
                        aria-hidden="true"
                    >
                        <span v-if="dispositivo === 'computador'" class="flex shrink-0 gap-1.5">
                            <span class="size-2.5 rounded-full bg-line" />
                            <span class="size-2.5 rounded-full bg-line" />
                            <span class="size-2.5 rounded-full bg-line" />
                        </span>
                        <span
                            :class="[
                                'flex min-w-0 flex-1 items-center truncate rounded-full bg-surface px-3 py-1 text-xs text-ink-muted',
                                dispositivo === 'computador' ? 'mx-auto max-w-md' : 'justify-center',
                            ]"
                        >
                            <span class="truncate">
                                <template v-if="dispositivo === 'computador'">{{ baseSemProtocolo }}</template><span class="font-medium text-ink">{{ dispositivo === 'computador' ? slugPrevia : `…/${slugPrevia}` }}</span>
                            </span>
                        </span>
                    </div>

                    <!-- A página no tamanho do aparelho, reduzida para caber.
                         `inert`: é para ver, não para clicar. -->
                    <div
                        ref="janela"
                        class="relative overflow-hidden"
                        :style="{ height: `${tela.altura * escala}px` }"
                    >
                        <div
                            v-if="escala"
                            inert
                            class="absolute top-0 left-0 origin-top-left"
                            :style="{
                                width: `${tela.largura}px`,
                                height: `${tela.altura}px`,
                                transform: `scale(${escala})`,
                            }"
                        >
                            <FirstAccessScreen
                                embedded
                                :autofocus="false"
                                :company-name="nomeDaEmpresa"
                                :dica="dicaPrevia"
                                :form="formDeExemplo"
                                :enviado-para="estado === 'enviado' ? EMAIL_DE_EXEMPLO : null"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Ações ────────────────────────────────────────────────────── -->
        <!-- Fixa embaixo: no modal o conteúdo rola, e salvar não pode ficar
             escondido depois da prévia. -->
        <div
            class="sticky bottom-0 z-10 -mb-1 mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-line bg-surface pt-4 pb-1"
        >
            <div>
                <UiButton
                    v-if="form.dica.trim()"
                    variant="ghost"
                    size="sm"
                    icon="refresh"
                    @click="form.dica = ''"
                >
                    Voltar ao aviso padrão
                </UiButton>
            </div>

            <div class="flex items-center gap-2">
                <span v-if="!form.isDirty" class="flex items-center gap-1 text-xs text-ink-muted">
                    <UiIcon name="check" :size="13" />
                    Tudo salvo
                </span>
                <UiButton v-if="form.isDirty" variant="ghost" size="sm" @click="descartar">Descartar</UiButton>
                <UiButton
                    type="submit"
                    form="form-primeiro-acesso"
                    size="sm"
                    :disabled="!form.isDirty"
                    :loading="form.processing"
                >
                    Salvar página
                </UiButton>
            </div>
        </div>
    </div>
</template>
