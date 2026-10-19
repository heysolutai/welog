<script setup>
/**
 * Estúdio de mensagens: os e-mails da jornada do resgate, escritos na voz do
 * Cliente e vistos como vão chegar.
 *
 * Em cima, lado a lado, a JORNADA (as quatro mensagens na ordem em que a
 * pessoa vive o resgate, cada uma com a chave de envio) e o RASCUNHO, como o
 * "escrever" de um app de e-mail: Assunto, Título e a mensagem, com negrito e
 * itálico por botão e as informações automáticas como etiquetas.
 *
 * Embaixo, com a largura toda, a PRÉVIA: o e-mail aberto como a pessoa vai
 * recebê-lo, no computador ou no celular, com os dados do próprio Cliente.
 *
 * O mesmo componente serve o Admin WeGift (num modal, pelas Customizações) e o
 * Admin Cliente (na página Comunicações): tudo vem da API do Estúdio, não das
 * props da tela que o abriu.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import MessageEditor from './MessageEditor.vue';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';
import UiSelect from './UiSelect.vue';
import UiToggle from './UiToggle.vue';
import { useConfirm } from '../composables/useConfirm';
import { useToast } from '../composables/useToast';

const props = defineProps({
    companyId: { type: Number, required: true },
});

const confirm = useConfirm();
const toast = useToast();

const ICONES = {
    project_released: 'gift',
    redemption_confirmed: 'check',
    redemption_shipped: 'truck',
    redemption_delivered: 'stageAssembly',
};

// ── Dados ───────────────────────────────────────────────────────────────────
const carregando = ref(true);
const estudio = ref(null);
const escopo = ref('0');
const selecionada = ref('redemption_confirmed');

const mensagens = computed(() => estudio.value?.mensagens ?? []);
const atual = computed(() => mensagens.value.find((m) => m.key === selecionada.value) ?? null);

const opcoesDeEscopo = computed(() => [
    { value: '0', label: `Toda a ${estudio.value?.empresa?.nome ?? 'empresa'}` },
    ...(estudio.value?.projetos ?? []).map((p) => ({ value: String(p.id), label: `Projeto ${p.nome}` })),
]);

const query = () => (escopo.value !== '0' ? `?projeto=${escopo.value}` : '');

function xsrf() {
    const par = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return par ? decodeURIComponent(par.split('=')[1]) : '';
}

async function pedir(metodo, url, corpo = null) {
    const resposta = await fetch(url, {
        method: metodo,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        credentials: 'same-origin',
        body: corpo ? JSON.stringify(corpo) : null,
    });

    const dados = await resposta.json().catch(() => ({}));

    return { ok: resposta.ok, status: resposta.status, dados };
}

async function carregar() {
    carregando.value = true;

    const { ok, dados } = await pedir('GET', route('studio.index', props.companyId) + query());

    carregando.value = false;

    if (!ok) {
        toast.error('Não foi possível abrir as mensagens. Tente de novo.');

        return;
    }

    estudio.value = dados;
    preencher();
}

onMounted(carregar);

// ── Rascunho ────────────────────────────────────────────────────────────────
const rascunho = ref({ subject: '', title: '', body: '' });
const erros = ref({});

function preencher() {
    const m = atual.value;

    rascunho.value = m ? { subject: m.subject, title: m.title, body: m.body } : { subject: '', title: '', body: '' };
    erros.value = {};
}

const alterado = computed(() => {
    const m = atual.value;

    return !!m && (
        rascunho.value.subject !== m.subject
        || rascunho.value.title !== m.title
        || rascunho.value.body !== m.body
    );
});

/** Trocar de mensagem ou de alcance com rascunho aberto pergunta antes. */
async function podeSair() {
    if (!alterado.value) return true;

    // Sem `action`: o `confirm` com ação espera uma resposta do Inertia, e
    // aqui não há nenhuma — ele só precisa devolver o sim ou o não.
    return confirm({
        title: 'Descartar as alterações?',
        description: 'O texto que você escreveu nesta mensagem ainda não foi salvo.',
        confirmLabel: 'Descartar',
        variant: 'danger',
    });
}

async function escolher(chave) {
    if (chave === selecionada.value || !(await podeSair())) return;

    selecionada.value = chave;
    preencher();
}

async function trocarEscopo(valor) {
    if (valor === escopo.value || !(await podeSair())) return;

    escopo.value = valor;
    await carregar();
}

// ── Envio por etapa ─────────────────────────────────────────────────────────
/*
 * A chave vira NO CLIQUE e o servidor confirma; se ele recusar, volta. Mesmo
 * padrão das chaves das Customizações.
 */
const alternando = ref(new Set());

async function alternarEnvio(mensagem, ligada) {
    const anterior = mensagem.ligada;
    mensagem.ligada = ligada;
    alternando.value = new Set([...alternando.value, mensagem.key]);

    const { ok } = await pedir('PUT', route('studio.toggle', [props.companyId, mensagem.key]), { ligada });

    const restantes = new Set(alternando.value);
    restantes.delete(mensagem.key);
    alternando.value = restantes;

    if (!ok) {
        mensagem.ligada = anterior;
        toast.error('Não foi possível alterar o envio agora.');

        return;
    }

    toast.success(ligada ? `"${mensagem.etapa}" volta a ser enviada.` : `"${mensagem.etapa}" deixa de ser enviada.`);
}

// ── Variáveis e formatação ──────────────────────────────────────────────────
const editores = { subject: ref(null), title: ref(null), body: ref(null) };
const ultimoCampo = ref('body');
const formato = ref({ negrito: false, italico: false });

const variaveisDaMensagem = computed(() =>
    (atual.value?.variaveis ?? []).map((token) => ({ token, ...estudio.value.variaveis[token] })),
);

/** A informação entra no último campo em que a pessoa estava (o corpo, de início). */
function inserir(token) {
    editores[ultimoCampo.value].value?.inserir(token);
}

function formatar(comando) {
    ultimoCampo.value = 'body';
    editores.body.value?.formatar(comando);
}

/**
 * A linha de ajuda das informações: explica o gesto e, ao passar o mouse
 * numa delas, mostra no que ela vira no e-mail de cada pessoa.
 */
const emFoco = ref(null);

const ajudaDaVariavel = computed(() => {
    const v = emFoco.value;

    if (!v) return 'Clique para colocar onde está o cursor. No envio, cada pessoa vê os próprios dados.';

    const exemplo = String(v.exemplo ?? '').replace(/\*\*/g, '').replace(/\s+/g, ' ').trim();

    return exemplo ? `${v.label} vira "${exemplo.slice(0, 48)}" no e-mail.` : `${v.label} é preenchido no envio.`;
});

/**
 * O corpo conhece também o botão do e-mail, com o rótulo desta mensagem. Só
 * o corpo: no assunto e no título não existe botão.
 */
const variaveisDoCorpo = computed(() => ({
    ...(estudio.value?.variaveis ?? {}),
    botao: { label: atual.value?.botao ?? 'Botão' },
}));

const temBotao = computed(() => rascunho.value.body.includes('{botao}'));

function posicionarBotao() {
    ultimoCampo.value = 'body';
    editores.body.value?.inserir('botao');
}

const nomeDeExemplo = computed(() => estudio.value?.variaveis?.nome?.exemplo ?? 'Maria Souza');

// ── Prévia ──────────────────────────────────────────────────────────────────
const dispositivo = ref('computador');

const escapar = (s) => String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

/** Texto puro com as variáveis trocadas pelos exemplos (assunto, caixa de entrada). */
function comExemplos(texto) {
    return String(texto ?? '').replace(/\{([a-z_]+)\}/g, (inteiro, token) => {
        const v = estudio.value?.variaveis?.[token];

        return v ? String(v.exemplo).replace(/\*\*/g, '') : inteiro;
    });
}

/**
 * O corpo como o e-mail mostra: parágrafos, quebras de linha, negrito e itálico.
 *
 * Tudo é escapado ANTES de virar HTML, e as variáveis entram já escapadas —
 * o `v-html` só recebe marcação que este código escreveu. O valor de cada
 * variável vem sublinhado de leve, para a pessoa ver onde ela caiu.
 */
function corpoEmHtml(texto) {
    let html = escapar(texto).replace(/\{([a-z_]+)\}/g, (inteiro, token) => {
        const v = estudio.value?.variaveis?.[token];

        return v
            ? `<span class="decoration-brand-300 underline decoration-dotted underline-offset-4">${escapar(v.exemplo)}</span>`
            : inteiro;
    });

    html = html
        .replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>')
        .replace(/\*(\S(?:.*?\S)?)\*/gs, '<em>$1</em>');

    // O botão sai onde está o marcador, como no envio.
    const botao = `<div class="flex justify-center py-2"><span class="rounded-md bg-brand-600 px-5 py-2.5 text-sm font-medium whitespace-nowrap text-ink-inverse">${escapar(atual.value?.botao ?? '')}</span></div>`;

    return html
        .split(/\n{2,}/)
        .map((paragrafo) => paragrafo.trim())
        .filter(Boolean)
        .map((paragrafo) => (paragrafo === '{botao}' ? botao : `<p>${paragrafo.replace(/ {2}\n|\n/g, '<br>')}</p>`))
        .join('');
}

const assuntoPrevia = computed(() => comExemplos(rascunho.value.subject));
const tituloPrevia = computed(() => comExemplos(rascunho.value.title));
const corpoPrevia = computed(() => corpoEmHtml(rascunho.value.body));
const resumoPrevia = computed(() =>
    comExemplos(rascunho.value.body.replace(/\{botao\}/g, '')).replace(/\*/g, '').replace(/\s+/g, ' ').trim().slice(0, 90),
);

// ── Salvar, restaurar, testar ───────────────────────────────────────────────
const salvando = ref(false);
const testando = ref(false);

async function salvar() {
    salvando.value = true;
    erros.value = {};

    const { ok, status, dados } = await pedir(
        'PUT',
        route('studio.update', [props.companyId, selecionada.value]) + query(),
        rascunho.value,
    );

    salvando.value = false;

    if (status === 422) {
        erros.value = Object.fromEntries(Object.entries(dados.errors ?? {}).map(([k, v]) => [k, v[0]]));

        return;
    }

    if (!ok) {
        toast.error('Não foi possível salvar. Tente de novo.');

        return;
    }

    toast.success(`"${atual.value.etapa}" salva. Vale a partir do próximo envio.`);
    await carregar();
}

async function restaurar() {
    const sim = await confirm({
        title: 'Voltar ao texto padrão?',
        description: `"${atual.value.etapa}" volta a usar o ${atual.value.herdado_de}. O texto próprio é apagado.`,
        confirmLabel: 'Voltar ao padrão',
        variant: 'danger',
    });

    if (!sim) return;

    const { ok } = await pedir('DELETE', route('studio.restore', [props.companyId, selecionada.value]) + query());

    ok ? toast.success(`"${atual.value.etapa}" voltou ao texto padrão.`) : toast.error('Não foi possível restaurar agora.');
    await carregar();
}

async function enviarTeste() {
    testando.value = true;
    erros.value = {};

    const { ok, status, dados } = await pedir(
        'POST',
        route('studio.test', [props.companyId, selecionada.value]) + query(),
        rascunho.value,
    );

    testando.value = false;

    if (status === 422) {
        erros.value = Object.fromEntries(Object.entries(dados.errors ?? {}).map(([k, v]) => [k, v[0]]));

        return;
    }

    ok
        ? toast.success(`Teste enviado para ${dados.email}.`)
        : toast.error('Não foi possível enviar o teste agora.');
}
</script>


<template>
    <div v-if="carregando && !estudio" class="flex min-h-96 items-center justify-center text-sm text-ink-muted">
        <UiIcon name="refresh" :size="18" class="mr-2 animate-spin" />
        Abrindo as mensagens…
    </div>

    <div v-else-if="estudio" class="flex min-h-0 flex-col">
        <!-- ── Alcance ──────────────────────────────────────────────────── -->
        <div class="flex flex-wrap items-center justify-between gap-3 pb-5">
            <div class="flex items-center gap-2 text-sm text-ink-muted">
                <span>Vale para</span>
                <div class="w-64">
                    <UiSelect
                        :model-value="escopo"
                        size="sm"
                        :options="opcoesDeEscopo"
                        aria-label="Vale para"
                        @update:model-value="trocarEscopo"
                    />
                </div>
            </div>

            <p v-if="!estudio.emails_ligados" class="flex items-center gap-1.5 text-sm text-warning">
                <UiIcon name="alert" :size="15" />
                Os e-mails de resgate estão desligados para este Cliente.
            </p>
        </div>

        <div class="grid min-h-0 gap-8 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <!-- ── Jornada ──────────────────────────────────────────────── -->
            <nav aria-label="Mensagens da jornada do resgate">
                <p class="mb-2 text-xs font-medium text-ink-muted">Jornada do resgate</p>
                <ol class="relative space-y-1">
                    <!-- O fio da jornada, atrás dos marcadores. -->
                    <span aria-hidden="true" class="absolute top-5 bottom-5 left-[1.4rem] w-px bg-line" />

                    <!-- Cada etapa: escolher para editar, e a chave que diz se
                         ela é enviada. A chave fica FORA do botão da etapa: um
                         controle dentro do outro não funciona no teclado. -->
                    <li
                        v-for="m in mensagens"
                        :key="m.key"
                        :class="[
                            'relative flex items-center gap-2 rounded-xl pr-2 transition-colors',
                            m.key === selecionada ? 'bg-brand-50' : 'hover:bg-surface-muted',
                        ]"
                    >
                        <button
                            type="button"
                            :aria-current="m.key === selecionada ? 'step' : undefined"
                            class="flex min-w-0 flex-1 items-center gap-3 px-2 py-2.5 text-left"
                            @click="escolher(m.key)"
                        >
                            <span
                                :class="[
                                    'relative flex size-7 shrink-0 items-center justify-center rounded-full border transition-colors',
                                    m.key === selecionada && m.ligada
                                        ? 'border-brand-600 bg-brand-600 text-ink-inverse'
                                        : 'border-line bg-surface text-ink-muted',
                                    !m.ligada && 'opacity-60',
                                ]"
                            >
                                <UiIcon :name="ICONES[m.key]" :size="14" />
                            </span>
                            <span class="min-w-0">
                                <span :class="['block text-sm font-medium', m.ligada ? 'text-ink' : 'text-ink-muted']">
                                    {{ m.etapa }}
                                </span>
                                <span class="flex items-center gap-1.5 text-xs text-ink-muted">
                                    <span
                                        aria-hidden="true"
                                        :class="[
                                            'size-1.5 rounded-full',
                                            !m.ligada ? 'bg-ink-subtle' : m.customized ? 'bg-brand-600' : 'bg-line-strong',
                                        ]"
                                    />
                                    {{ !m.ligada ? 'Não é enviada' : m.customized ? 'Personalizada' : 'Texto padrão' }}
                                </span>
                            </span>
                        </button>

                        <UiToggle
                            :model-value="m.ligada"
                            :label="`Enviar o e-mail de ${m.etapa}`"
                            :loading="alternando.has(m.key)"
                            :state-label="false"
                            @update:model-value="alternarEnvio(m, $event)"
                        />
                    </li>
                </ol>

                <p class="mt-3 px-2 text-xs leading-relaxed text-ink-subtle">
                    A chave liga ou desliga o envio da etapa para todo o Cliente.
                </p>
            </nav>

            <!-- ── Rascunho ─────────────────────────────────────────────── -->
            <section v-if="atual" aria-label="Escrever a mensagem" class="min-w-0">
                <p class="mb-2 text-xs text-ink-muted">{{ atual.quando }}</p>

                <p
                    v-if="!atual.ligada"
                    class="mb-3 flex items-center gap-2 rounded-lg bg-surface-muted px-3 py-2 text-xs text-ink-muted"
                >
                    <UiIcon name="info" :size="14" class="shrink-0" />
                    Esta etapa está desligada. O texto fica guardado, mas o e-mail não é enviado.
                </p>

                <div
                    class="overflow-hidden rounded-card border border-line bg-surface transition-colors focus-within:border-brand-300"
                >
                    <div class="flex items-baseline gap-3 border-b border-line px-4 py-3">
                        <span class="w-14 shrink-0 text-sm text-ink-muted">Assunto</span>
                        <MessageEditor
                            :ref="(c) => (editores.subject.value = c)"
                            v-model="rascunho.subject"
                            :variaveis="estudio.variaveis"
                            label="Assunto"
                            placeholder="O que aparece na caixa de entrada"
                            class="flex-1 text-sm font-medium text-ink"
                            @focus="ultimoCampo = 'subject'"
                        />
                    </div>
                    <div class="flex items-baseline gap-3 border-b border-line px-4 py-3">
                        <span class="w-14 shrink-0 text-sm text-ink-muted">Título</span>
                        <MessageEditor
                            :ref="(c) => (editores.title.value = c)"
                            v-model="rascunho.title"
                            :variaveis="estudio.variaveis"
                            label="Título"
                            placeholder="O destaque no topo do e-mail"
                            class="flex-1 text-base font-semibold text-ink"
                            @focus="ultimoCampo = 'title'"
                        />
                    </div>

                    <!-- Negrito e itálico, como em qualquer app de e-mail. -->
                    <div class="flex items-center gap-1 border-b border-line px-3 py-1.5">
                        <button
                            type="button"
                            title="Negrito (Ctrl+B)"
                            aria-label="Negrito"
                            :aria-pressed="formato.negrito"
                            :class="[
                                'grid size-8 place-items-center rounded-md font-serif text-[15px] font-bold transition-colors',
                                formato.negrito ? 'bg-brand-50 text-brand-700' : 'text-ink-muted hover:bg-surface-muted hover:text-ink',
                            ]"
                            @mousedown.prevent
                            @click="formatar('bold')"
                        >
                            B
                        </button>
                        <button
                            type="button"
                            title="Itálico (Ctrl+I)"
                            aria-label="Itálico"
                            :aria-pressed="formato.italico"
                            :class="[
                                'grid size-8 place-items-center rounded-md font-serif text-[15px] italic transition-colors',
                                formato.italico ? 'bg-brand-50 text-brand-700' : 'text-ink-muted hover:bg-surface-muted hover:text-ink',
                            ]"
                            @mousedown.prevent
                            @click="formatar('italic')"
                        >
                            I
                        </button>
                        <span aria-hidden="true" class="mx-1.5 h-4 w-px bg-line" />
                        <button
                            type="button"
                            :title="temBotao ? 'Mover o botão para onde está o cursor' : 'Colocar o botão onde está o cursor'"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-ink-muted transition-colors hover:bg-surface-muted hover:text-ink"
                            @mousedown.prevent
                            @click="posicionarBotao"
                        >
                            <span aria-hidden="true" class="h-2.5 w-4 rounded-[3px] bg-brand-600" />
                            {{ temBotao ? 'Mover botão' : 'Botão' }}
                        </button>
                        <span aria-hidden="true" class="mx-1.5 h-4 w-px bg-line" />
                        <span class="truncate text-xs text-ink-subtle">
                            Selecione um trecho para destacar. Clique onde quer o botão e use Mover botão.
                        </span>
                    </div>

                    <MessageEditor
                        :ref="(c) => (editores.body.value = c)"
                        v-model="rascunho.body"
                        :variaveis="variaveisDoCorpo"
                        multiline
                        rich
                        label="Mensagem"
                        placeholder="Escreva a mensagem…"
                        class="block min-h-48 px-4 py-3 text-sm leading-relaxed text-ink"
                        @focus="ultimoCampo = 'body'"
                        @formato="formato = $event"
                    />

                    <!-- As informações automáticas, com nome de gente. -->
                    <div class="border-t border-line bg-surface-muted/50 px-4 py-3">
                        <p class="mb-2 text-xs font-medium text-ink">Informações automáticas</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="v in variaveisDaMensagem"
                                :key="v.token"
                                type="button"
                                class="inline-flex h-7 items-center gap-1 rounded-full border border-line bg-surface px-2.5 text-xs font-medium text-ink transition-colors hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                                @mousedown.prevent
                                @mouseenter="emFoco = v"
                                @mouseleave="emFoco = null"
                                @focus="emFoco = v"
                                @blur="emFoco = null"
                                @click="inserir(v.token)"
                            >
                                <UiIcon name="plus" :size="11" class="text-ink-subtle" />
                                {{ v.label }}
                            </button>
                        </div>
                        <p class="mt-2 flex items-center gap-1.5 text-xs text-ink-muted" aria-live="polite">
                            <UiIcon name="info" :size="13" class="shrink-0 text-ink-subtle" />
                            <span class="truncate">{{ ajudaDaVariavel }}</span>
                        </p>
                    </div>
                </div>

                <p
                    v-for="(erro, campo) in erros"
                    :key="campo"
                    role="alert"
                    class="mt-2 text-xs font-medium text-danger"
                >
                    {{ erro }}
                </p>
            </section>
        </div>

        <!-- ── Prévia, com a largura toda ───────────────────────────────── -->
        <section aria-label="Prévia do e-mail" class="mt-8">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Como vai chegar</h3>
                    <p class="text-xs text-ink-muted">Com dados de exemplo, do jeito que {{ nomeDeExemplo }} vai abrir.</p>
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

            <div class="rounded-card bg-canvas px-4 py-8 sm:px-8">
                <div
                    :class="[
                        'mx-auto overflow-hidden transition-all duration-300',
                        dispositivo === 'celular'
                            ? 'max-w-[20rem] rounded-[2.25rem] border-[7px] border-ink/85 bg-canvas'
                            : 'max-w-4xl rounded-xl bg-surface shadow-card ring-1 ring-line',
                    ]"
                >
                    <!-- Computador: o e-mail aberto, com assunto e remetente. -->
                    <template v-if="dispositivo === 'computador'">
                        <div class="flex items-center gap-1.5 border-b border-line bg-surface-muted px-4 py-2.5" aria-hidden="true">
                            <span class="size-2.5 rounded-full bg-line" />
                            <span class="size-2.5 rounded-full bg-line" />
                            <span class="size-2.5 rounded-full bg-line" />
                        </div>
                        <div class="border-b border-line px-6 pt-5 pb-4">
                            <p class="text-lg font-semibold text-ink">{{ assuntoPrevia || 'Sem assunto' }}</p>
                            <div class="mt-3 flex items-center gap-3">
                                <span
                                    aria-hidden="true"
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-ink-inverse"
                                >
                                    W
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-ink">WeLog</p>
                                    <p class="text-xs text-ink-muted">para {{ nomeDeExemplo }}</p>
                                </div>
                                <span class="text-xs text-ink-subtle">agora</span>
                            </div>
                        </div>
                    </template>

                    <!-- Celular: a linha na caixa de entrada, onde se decide abrir. -->
                    <div v-else class="m-2 flex items-start gap-3 rounded-2xl bg-surface p-3 shadow-xs">
                        <span
                            aria-hidden="true"
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-ink-inverse"
                        >
                            W
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-sm font-semibold text-ink">WeLog</span>
                                <span class="text-xs text-ink-subtle">agora</span>
                            </div>
                            <p class="truncate text-sm font-medium text-ink">{{ assuntoPrevia || 'Sem assunto' }}</p>
                            <p class="truncate text-xs text-ink-muted">{{ resumoPrevia }}</p>
                        </div>
                    </div>

                    <!-- O e-mail, na largura dos clientes de e-mail (600px). -->
                    <div :class="dispositivo === 'computador' ? 'bg-canvas px-6 py-8' : 'px-2 pb-3'">
                        <div class="mx-auto max-w-[600px]">
                            <p class="pt-1 pb-3 text-center text-lg font-bold tracking-tight text-ink">
                                We<span class="text-brand-600">Log</span>
                                <span class="block text-[10px] font-medium tracking-wide text-ink-muted">por WeGift</span>
                            </p>

                            <article
                                :class="[
                                    'rounded-xl border-t-2 border-brand-600 bg-surface leading-relaxed text-ink',
                                    dispositivo === 'computador' ? 'px-10 py-8 text-[15px]' : 'px-5 py-5 text-sm',
                                ]"
                            >
                                <h3 v-if="tituloPrevia" class="mb-4 text-xl font-bold text-ink">{{ tituloPrevia }}</h3>
                                <!-- eslint-disable-next-line vue/no-v-html -- escapado em corpoEmHtml -->
                                <div class="space-y-3 text-ink/90" v-html="corpoPrevia" />
                            </article>

                            <p class="px-2 pt-3 text-center text-[11px] text-ink-subtle">
                                Esta mensagem foi enviada pela plataforma WeLog.
                            </p>
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
            <div class="flex flex-wrap items-center gap-2">
                <UiButton variant="ghost" size="sm" icon="emails" :loading="testando" @click="enviarTeste">
                    Enviar teste para mim
                </UiButton>
                <UiButton v-if="atual?.customized" variant="ghost" size="sm" icon="refresh" @click="restaurar">
                    Voltar ao padrão
                </UiButton>
            </div>

            <div class="flex items-center gap-2">
                <span v-if="!alterado && atual" class="flex items-center gap-1 text-xs text-ink-muted">
                    <UiIcon name="check" :size="13" />
                    {{ atual.customized ? 'Texto próprio salvo' : 'Usando o texto padrão' }}
                </span>
                <UiButton v-if="alterado" variant="ghost" size="sm" @click="preencher">Descartar</UiButton>
                <UiButton size="sm" :disabled="!alterado" :loading="salvando" @click="salvar">
                    Salvar mensagem
                </UiButton>
            </div>
        </div>
    </div>
</template>
