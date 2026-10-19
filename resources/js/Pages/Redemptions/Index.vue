<script setup>
import { computed, ref, useId, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import RedemptionAddressDrawer from '../../Components/RedemptionAddressDrawer.vue';
import ShipControl from '../../Components/ShipControl.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiButton from '../../Components/UiButton.vue';
import UiDrawer from '../../Components/UiDrawer.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiField from '../../Components/UiField.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiInput from '../../Components/UiInput.vue';
import UiMenu from '../../Components/UiMenu.vue';
import UiPagination from '../../Components/UiPagination.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiSearchInput from '../../Components/UiSearchInput.vue';
import UiSelect from '../../Components/UiSelect.vue';
import UiTabs from '../../Components/UiTabs.vue';
import { formatDate, maskCpf } from '../../lib/format';
import { useCarriers } from '../../composables/useCarriers';
import { useConfirm } from '../../composables/useConfirm';

const props = defineProps({
    redemptions: { type: Object, required: true },
    filtros: { type: Object, required: true },
    contagens: { type: Object, default: () => ({}) },
    // Só os Projetos e Empresas que têm resgate: os seletores não listam
    // quem ainda está em Design.
    projetos: { type: Array, default: () => [] },
    empresas: { type: Array, default: () => [] },
    canShip: { type: Boolean, default: false },
});

const { carriers, activeCarriers, bySlug, carrierLabel, trackingLink } = useCarriers();
const confirm = useConfirm();

// ── Filtros ─────────────────────────────────────────────────────────────────
/**
 * Vocabulário do status: tradução do status técnico do resgate. O selo de
 * cada linha continua mostrando o status real.
 */
const SITUACOES = [
    { key: 'todas', label: 'Todos' },
    { key: 'aguardando', label: 'Aguardando' },
    { key: 'em_transporte', label: 'Em transporte' },
    { key: 'entregue', label: 'Entregues' },
    { key: 'cancelado', label: 'Cancelados' },
];

const busca = ref(props.filtros.busca ?? '');
const situacao = ref(props.filtros.situacao ?? 'todas');
const projeto = ref(String(props.filtros.projeto ?? 0));
const empresa = ref(String(props.filtros.empresa ?? 0));
// 'todas' e não '': o UiSelect pinta valor vazio de cinza, como texto de exemplo.
const transportadora = ref(props.filtros.transportadora || 'todas');
const periodo = ref(String(props.filtros.periodo ?? 0));

const opcoesDeEmpresa = computed(() => [
    { value: '0', label: 'Todas as empresas' },
    ...props.empresas.map((e) => ({ value: String(e.id), label: e.nome })),
]);

const opcoesDeProjeto = computed(() => [
    { value: '0', label: 'Todos os Projetos' },
    ...props.projetos.map((p) => ({ value: String(p.id), label: p.nome })),
]);

const opcoesDeTransportadora = computed(() => [
    { value: 'todas', label: 'Todas as transportadoras' },
    { value: 'sem', label: 'Sem transportadora' },
    ...carriers.value.map((c) => ({ value: c.slug, label: c.name })),
]);

const OPCOES_DE_PERIODO = [
    { value: '0', label: 'Qualquer data' },
    { value: '7', label: 'Últimos 7 dias' },
    { value: '30', label: 'Últimos 30 dias' },
    { value: '90', label: 'Últimos 90 dias' },
];

const abas = computed(() =>
    SITUACOES.map((s) => ({
        ...s,
        count: s.key === 'todas'
            ? Object.values(props.contagens).reduce((total, n) => total + n, 0)
            : (props.contagens[s.key] ?? 0),
    })),
);

const parametros = () => ({
    busca: busca.value,
    situacao: situacao.value,
    projeto: projeto.value,
    empresa: empresa.value,
    transportadora: transportadora.value === 'todas' ? '' : transportadora.value,
    periodo: periodo.value,
});

// A busca vai ao servidor: a lista é paginada, e filtrar só a página visível
// responderia "nada encontrado" para quem está na página 2.
let debounce = null;

function aplicar({ imediato = false } = {}) {
    clearTimeout(debounce);

    const enviar = () => router.get(route('redemptions.index'), parametros(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });

    imediato ? enviar() : (debounce = setTimeout(enviar, 350));
}

watch([situacao, projeto, empresa, transportadora, periodo], () => aplicar({ imediato: true }));

// Trocou o recorte, a seleção é de outro conjunto: sai inteira. Mandar para o
// servidor ids que já saíram da tela é o tipo de ação em massa que ninguém
// consegue conferir antes de confirmar.
watch([busca, situacao, projeto, empresa, transportadora, periodo], () => limparSelecao());

const filtrando = computed(
    () => busca.value.trim() !== ''
        || situacao.value !== 'todas'
        || projeto.value !== '0'
        || empresa.value !== '0'
        || transportadora.value !== 'todas'
        || periodo.value !== '0',
);

function limparFiltros() {
    busca.value = '';
    situacao.value = 'todas';
    projeto.value = '0';
    empresa.value = '0';
    transportadora.value = 'todas';
    periodo.value = '0';
}

// ── Seleção ─────────────────────────────────────────────────────────────────
/*
 * Mapa id → { id, pessoa, destino, status }: a barra de ações conta quantos
 * cabem em cada status, e o painel de expedição lista quem vai sair — mesmo
 * quando a seleção veio de "todos do filtro", com linhas de outras páginas.
 */
const selecionados = ref(new Map());
const todosDoFiltro = ref(false);
const carregandoTodos = ref(false);

const resumo = (r) => ({ id: r.id, pessoa: r.pessoa, destino: r.destino, status: r.status });

const marcado = (id) => selecionados.value.has(id);

function alternar(r) {
    // Map novo a cada mudança: mutar o mesmo não dispara a reatividade.
    const proximo = new Map(selecionados.value);
    proximo.has(r.id) ? proximo.delete(r.id) : proximo.set(r.id, resumo(r));
    selecionados.value = proximo;
    todosDoFiltro.value = false;
}

const linhas = computed(() => props.redemptions.data);

const paginaToda = computed(() => linhas.value.length > 0 && linhas.value.every((r) => marcado(r.id)));
const paginaParcial = computed(() => !paginaToda.value && linhas.value.some((r) => marcado(r.id)));

function alternarPagina() {
    const proximo = new Map(selecionados.value);

    if (paginaToda.value) {
        linhas.value.forEach((r) => proximo.delete(r.id));
    } else {
        linhas.value.forEach((r) => proximo.set(r.id, resumo(r)));
    }

    selecionados.value = proximo;
    todosDoFiltro.value = false;
}

/** "Selecionar todos os 340": pede ao servidor o filtro inteiro, não só a página. */
async function selecionarTodosDoFiltro() {
    carregandoTodos.value = true;

    try {
        const query = new URLSearchParams(parametros()).toString();
        const resposta = await fetch(`${route('redemptions.selection')}?${query}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const { itens } = await resposta.json();

        selecionados.value = new Map(itens.map((r) => [r.id, r]));
        todosDoFiltro.value = true;
    } finally {
        carregandoTodos.value = false;
    }
}

function limparSelecao() {
    selecionados.value = new Map();
    todosDoFiltro.value = false;
}

const totalMarcado = computed(() => selecionados.value.size);

/** Quantos da seleção cabem em cada mudança de status. */
const doStatus = (status) => [...selecionados.value.values()].filter((r) => r.status === status);

const elegiveis = computed(() => ({
    expedir: doStatus('confirmed'),
    entregar: doStatus('shipped'),
    cancelar: doStatus('confirmed'),
}));

// ── Alteração de status em massa ────────────────────────────────────────────
const processando = ref(false);

async function entregarEmMassa() {
    const ids = elegiveis.value.entregar.map((r) => r.id);

    await confirm({
        title: ids.length === 1 ? 'Marcar 1 resgate como entregue?' : `Marcar ${ids.length} resgates como entregues?`,
        description: 'Cada pessoa recebe o e-mail "Pedido entregue!". Não dá para desfazer.',
        warning: ids.length < totalMarcado.value
            ? `Só os em transporte mudam: ${totalMarcado.value - ids.length} da seleção ficam como estão.`
            : null,
        confirmLabel: 'Marcar entregues',
        errorBag: 'deliver',
        action: () => enviarEmMassa(route('redemptions.delivered.batch'), { ids }),
    });
}

async function cancelarEmMassa() {
    const ids = elegiveis.value.cancelar.map((r) => r.id);

    await confirm({
        title: ids.length === 1 ? 'Cancelar 1 resgate?' : `Cancelar ${ids.length} resgates?`,
        description: 'O estoque baixado volta para a plataforma e para a Omie, e cada pessoa pode resgatar de novo. '
            + 'Não dá para desfazer.',
        warning: ids.length < totalMarcado.value
            ? `Só os aguardando expedição podem ser cancelados: ${totalMarcado.value - ids.length} da seleção ficam como estão.`
            : null,
        confirmLabel: 'Cancelar resgates',
        variant: 'danger',
        errorBag: 'cancel',
        action: () => enviarEmMassa(route('redemptions.cancel.batch'), { ids }),
    });
}

function enviarEmMassa(url, dados, extra = {}) {
    processando.value = true;

    return router.post(url, dados, {
        preserveScroll: true,
        onSuccess: () => limparSelecao(),
        onFinish: () => (processando.value = false),
        ...extra,
    });
}

// Expedir em massa: uma transportadora para todos, o rastreio de cada um.
const expedirAberto = ref(false);
const radioName = useId();
const expedicao = useForm({ carrier: '', rastreios: {} });

const transportadoraEscolhida = computed(() => bySlug.value[expedicao.carrier] ?? null);
const usaLink = computed(() => transportadoraEscolhida.value?.tracking_field === 'link');
// Correios fora da prepostagem: o robô descobre o código depois.
const rastreioOpcional = computed(() => transportadoraEscolhida.value?.integrated === true && !usaLink.value);

const faltaRastreio = computed(() =>
    !rastreioOpcional.value
    && elegiveis.value.expedir.some((r) => !(expedicao.rastreios[r.id] ?? '').trim()),
);

function abrirExpedicao() {
    expedicao.reset();
    expedicao.clearErrors();
    expedicao.carrier = activeCarriers.value[0]?.slug ?? '';
    expedicao.rastreios = {};
    expedirAberto.value = true;
}

function expedirEmMassa() {
    if (!transportadoraEscolhida.value || faltaRastreio.value) return;

    expedicao
        .transform((data) => ({
            carrier: data.carrier,
            itens: elegiveis.value.expedir.map((r) => {
                const valor = (data.rastreios[r.id] ?? '').trim() || null;

                return { id: r.id, tracking_code: usaLink.value ? null : valor, tracking_url: usaLink.value ? valor : null };
            }),
        }))
        .post(route('redemptions.ship.batch'), {
            preserveScroll: true,
            onSuccess: () => {
                expedirAberto.value = false;
                limparSelecao();
            },
            // Recusa parcial (`ship_batch`): quem passou já foi expedido, então o
            // painel fecha e as recusas ficam no aviso do topo, com o nome de
            // cada pessoa. Erro de campo (`carrier`) mantém o painel aberto.
            onError: (errors) => {
                if (errors.ship_batch && !errors.carrier) {
                    expedirAberto.value = false;
                    limparSelecao();
                }
            },
        });
}

const page = usePage();

/** Recusas da última ação em massa, ditas por pessoa. */
const recusas = computed(() => {
    const erro = page.props.errors?.ship_batch;

    return erro ? erro.split(' | ') : [];
});

// ── Por linha ───────────────────────────────────────────────────────────────
// CPF que ficou para depois: a WeGift abre a solicitação com o que chegou por
// telefone, e o número vem junto da nota.
const cpfDrawer = ref(false);
const cpfAlvo = ref(null);
const cpfForm = useForm({ cpf: '' });

function abrirCpf(r) {
    cpfAlvo.value = r;
    cpfForm.defaults({ cpf: '' }).reset().clearErrors();
    cpfDrawer.value = true;
}

function salvarCpf() {
    cpfForm
        .transform((data) => ({ cpf: data.cpf.trim() }))
        .patch(route('redemptions.cpf', cpfAlvo.value.id), {
            preserveScroll: true,
            onSuccess: () => (cpfDrawer.value = false),
        });
}

/**
 * Cancelar devolve o saldo baixado (plataforma e Omie) e reabre a escolha da
 * pessoa. Só antes da expedição: kit na rua é logística reversa, não botão.
 */
async function cancelar(r) {
    await confirm({
        title: `Cancelar o resgate de ${r.pessoa}?`,
        description: 'O estoque baixado volta para a plataforma e para a Omie, e a pessoa '
            + 'pode escolher/resgatar de novo. Não dá para desfazer o cancelamento.',
        confirmLabel: 'Cancelar resgate',
        variant: 'danger',
        errorBag: 'cancel',
        action: () => router.post(route('redemptions.cancel', r.id), {}, { preserveScroll: true }),
    });
}

// Endereço de entrega corrigido pela WeGift, a qualquer momento (o painel
// pede confirmação quando o resgate já foi expedido).
const enderecoAberto = ref(false);
const enderecoAlvo = ref(null);

function abrirEndereco(r) {
    enderecoAlvo.value = r;
    enderecoAberto.value = true;
}

const menuItems = (r) => [
    {
        label: 'Abrir Projeto',
        icon: 'projects',
        href: `${route('projects.show', r.project_id)}?aba=resgates`,
    },
    ...(props.canShip && !r.cpf && r.status !== 'canceled'
        ? [{ label: 'Informar CPF', icon: 'users', onSelect: () => abrirCpf(r) }]
        : []),
    ...(props.canShip && r.status !== 'canceled'
        ? [{ label: 'Alterar endereço', icon: 'truck', onSelect: () => abrirEndereco(r) }]
        : []),
    ...(props.canShip && r.status === 'confirmed'
        ? [{ label: 'Cancelar resgate', icon: 'close', danger: true, separatorBefore: true, onSelect: () => cancelar(r) }]
        : []),
];

const shipPayload = (r) => ({ id: r.id, status: r.status, carrier: r.carrier, city: r.destino, user: r.pessoa, product: r.kit });
</script>

<template>
    <Head title="Resgates" />

    <PageHeader title="Resgates" subtitle="Acompanhe e atualize o status de cada pedido.">
        <template #actions>
            <UiRefreshButton />
        </template>
    </PageHeader>

    <UiAlert v-if="recusas.length" variant="warning" class="mb-4" title="Alguns resgates não foram expedidos">
        <ul class="mt-1 space-y-0.5 text-sm">
            <li v-for="(recusa, i) in recusas" :key="i">{{ recusa }}</li>
        </ul>
    </UiAlert>

    <!-- ── Filtros ──────────────────────────────────────────────────────────
         Uma linha só: a busca ocupa o que sobra, os recortes têm largura fixa.
         O status fica embaixo, como controle segmentado, com a contagem de
         cada um já respeitando os outros filtros. -->
    <div class="mb-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <UiSearchInput
                v-model="busca"
                class="min-w-64 flex-1"
                placeholder="Buscar pessoa, empresa ou rastreio"
                @update:model-value="aplicar()"
            />

            <!-- Cada seletor numa caixa de largura fixa: o UiSelect ocupa a
                 largura do pai, e solto ele esticava uma faixa por filtro. -->
            <div class="w-44">
                <UiSelect v-model="empresa" :options="opcoesDeEmpresa" aria-label="Empresa" />
            </div>
            <div class="w-44">
                <UiSelect v-model="projeto" :options="opcoesDeProjeto" aria-label="Projeto" />
            </div>
            <div class="w-56">
                <UiSelect v-model="transportadora" :options="opcoesDeTransportadora" aria-label="Transportadora" />
            </div>
            <div class="w-40">
                <UiSelect v-model="periodo" :options="OPCOES_DE_PERIODO" aria-label="Período" />
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <UiTabs v-model="situacao" :tabs="abas" variant="segmented" panel-prefix="resgates" />

            <button
                v-if="filtrando"
                type="button"
                class="text-sm font-medium text-ink-muted transition-colors hover:text-ink"
                @click="limparFiltros"
            >
                Limpar filtros
            </button>
        </div>
    </div>

    <!-- Seleção além da página, no padrão das caixas de e-mail: marcou a
         página inteira, a faixa oferece o filtro inteiro. -->
    <div
        v-if="canShip && (paginaToda || todosDoFiltro) && redemptions.total > linhas.length"
        class="mb-2 rounded-control bg-brand-50 px-4 py-2 text-center text-sm text-ink"
        role="status"
    >
        <template v-if="todosDoFiltro">
            Todos os {{ redemptions.total }} resgates deste filtro estão selecionados.
            <button type="button" class="ml-1 font-medium text-brand-700 hover:underline" @click="limparSelecao">
                Limpar seleção
            </button>
        </template>
        <template v-else>
            Os {{ linhas.length }} resgates desta página estão selecionados.
            <button
                type="button"
                class="ml-1 font-medium text-brand-700 hover:underline disabled:opacity-60"
                :disabled="carregandoTodos"
                @click="selecionarTodosDoFiltro"
            >
                {{ carregandoTodos ? 'Selecionando…' : `Selecionar todos os ${redemptions.total}` }}
            </button>
        </template>
    </div>

    <!-- ── Tabela ───────────────────────────────────────────────────────────
         Própria, e não UiTable: o cabeçalho precisa da caixa que marca a
         página, e a linha selecionada ganha fundo. -->
    <div class="scrollbar-slim overflow-x-auto rounded-card border border-line bg-surface">
        <table class="w-full text-sm text-ink">
            <caption class="sr-only">Resgates de Projetos, com status e rastreio</caption>
            <thead>
                <tr class="border-b border-line text-left text-xs font-medium text-ink-muted">
                    <th v-if="canShip" scope="col" class="w-12 py-3 pl-4">
                        <input
                            type="checkbox"
                            class="size-4 accent-brand-600 align-middle"
                            :checked="paginaToda"
                            :indeterminate="paginaParcial"
                            :disabled="!linhas.length"
                            aria-label="Selecionar todos desta página"
                            @change="alternarPagina"
                        />
                    </th>
                    <th scope="col" class="px-4 py-3 font-medium">Colaborador</th>
                    <th scope="col" class="px-4 py-3 font-medium">Endereço</th>
                    <th scope="col" class="px-4 py-3 font-medium">Kit</th>
                    <th scope="col" class="px-4 py-3 font-medium">Empresa</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Ação</th>
                </tr>
            </thead>

            <tbody>
                <tr
                    v-for="r in linhas"
                    :key="r.id"
                    :class="[
                        'border-b border-line align-top transition-colors last:border-b-0',
                        marcado(r.id) ? 'bg-brand-50/60' : 'hover:bg-surface-muted/60',
                    ]"
                >
                    <td v-if="canShip" class="py-3.5 pl-4">
                        <input
                            type="checkbox"
                            class="size-4 accent-brand-600"
                            :checked="marcado(r.id)"
                            :aria-label="`Selecionar o resgate de ${r.pessoa}`"
                            @change="alternar(r)"
                        />
                    </td>

                    <th scope="row" class="px-4 py-3 text-left font-normal">
                        <span class="block font-medium text-ink">{{ r.pessoa }}</span>
                        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-muted">
                            <span v-if="r.solicitacao">
                                Solicitação{{ r.quantidade > 1 ? ` · ${r.quantidade} un.` : '' }}
                            </span>
                            <span v-if="r.cpf" class="tabular-nums">CPF {{ r.cpf }}</span>
                            <button
                                v-else-if="canShip && r.status !== 'canceled'"
                                type="button"
                                class="font-medium text-warning hover:underline"
                                @click="abrirCpf(r)"
                            >
                                CPF pendente
                            </button>
                        </span>
                    </th>

                    <td class="max-w-64 px-4 py-3">
                        <span class="block truncate" :title="r.endereco.linha1">{{ r.endereco.linha1 || '-' }}</span>
                        <span class="mt-0.5 block truncate text-xs text-ink-muted">
                            {{ r.endereco.linha2 }}<template v-if="r.endereco.cep"> · {{ r.endereco.cep }}</template>
                        </span>
                    </td>

                    <!-- Uma linha só: nome de kit longo quebrando dobrava a
                         altura da linha. O nome inteiro fica no balão. -->
                    <td class="px-4 py-3">
                        <span class="block max-w-48 truncate" :title="r.kit">
                            {{ r.kit }}
                            <span v-if="r.quantidade > 1 && !r.solicitacao" class="text-ink-muted">× {{ r.quantidade }}</span>
                        </span>
                    </td>

                    <td class="px-4 py-3">
                        <span class="block max-w-40 truncate text-ink-muted" :title="r.referencia">{{ r.referencia }}</span>
                    </td>

                    <td class="px-4 py-3">
                        <StatusBadge :status="r.status" kind="redemption" />

                        <span
                            v-if="r.carrier || r.ultimo_evento"
                            class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-muted"
                        >
                            <span v-if="r.carrier">{{ carrierLabel(r.carrier) }}</span>
                            <template v-if="r.tracking_code">
                                <span aria-hidden="true">·</span>
                                <span class="font-mono">{{ r.tracking_code }}</span>
                            </template>
                            <a
                                v-if="trackingLink(r)"
                                :href="trackingLink(r)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center text-brand-700 hover:underline"
                                :aria-label="`Rastrear o pedido de ${r.pessoa}`"
                            >
                                <UiIcon name="external" :size="12" />
                            </a>
                        </span>
                        <span v-if="r.ultimo_evento" class="mt-0.5 block text-xs text-ink-subtle">
                            {{ r.ultimo_evento.titulo }} · {{ formatDate(r.ultimo_evento.ocorrido_em, true) }}
                        </span>
                    </td>

                    <td class="px-4 py-2.5">
                        <div class="flex items-center justify-end gap-1">
                            <ShipControl v-if="canShip" :redemption="shipPayload(r)" :can-ship="canShip" />
                            <UiMenu :items="menuItems(r)" :label="`Ações do resgate de ${r.pessoa}`" />
                        </div>
                    </td>
                </tr>

                <tr v-if="!linhas.length">
                    <td :colspan="canShip ? 7 : 6" class="p-3">
                        <UiEmpty
                            icon="redemptions"
                            :message="filtrando
                                ? 'Nenhum resgate com esses filtros.'
                                : 'Nenhum resgate ainda. Eles aparecem quando o Colaborador confirma o endereço.'"
                        />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <UiPagination :links="redemptions.links" label="Resgates" />

    <!-- Espaço para a barra flutuante não cobrir a última linha. -->
    <div v-if="totalMarcado" class="h-24" aria-hidden="true" />

    <!-- ── Alteração em massa ───────────────────────────────────────────────
         Aparece sozinha com a primeira linha marcada, flutuando no rodapé
         (fixa, porque a seleção atravessa a rolagem e as páginas). Cada status
         diz quantos da seleção ele alcança: o que não se aplica fica de fora
         em vez de falhar o lote. -->
    <Transition
        enter-from-class="translate-y-4 opacity-0"
        enter-active-class="transition duration-200 ease-out"
        leave-to-class="translate-y-4 opacity-0"
        leave-active-class="transition duration-150 ease-in"
    >
        <div
            v-if="canShip && totalMarcado"
            class="fixed inset-x-0 bottom-5 z-30 flex justify-center px-4 lg:left-sidebar"
            role="region"
            aria-label="Alterar status dos resgates selecionados"
        >
            <div
                class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-2xl bg-ink px-5 py-3 text-ink-inverse shadow-overlay"
            >
                <div class="flex items-center gap-3">
                    <span class="text-sm font-semibold tabular-nums">
                        {{ totalMarcado }} {{ totalMarcado === 1 ? 'selecionado' : 'selecionados' }}
                    </span>
                    <button
                        type="button"
                        class="text-sm text-ink-inverse/70 transition-colors hover:text-ink-inverse"
                        @click="limparSelecao"
                    >
                        Limpar
                    </button>
                </div>

                <span aria-hidden="true" class="hidden h-6 w-px bg-ink-inverse/20 sm:block" />

                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-ink-inverse/70">Alterar status para</span>

                    <button
                        v-for="acao in [
                            { chave: 'expedir', rotulo: 'Em transporte', icone: 'truck', fazer: abrirExpedicao },
                            { chave: 'entregar', rotulo: 'Entregue', icone: 'check', fazer: entregarEmMassa },
                            { chave: 'cancelar', rotulo: 'Cancelado', icone: 'close', fazer: cancelarEmMassa },
                        ]"
                        :key="acao.chave"
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full bg-ink-inverse/10 px-3 text-sm font-medium transition-colors hover:bg-ink-inverse/20 disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="!elegiveis[acao.chave].length || processando"
                        :title="!elegiveis[acao.chave].length ? 'Nenhum resgate da seleção pode ir para este status' : null"
                        @click="acao.fazer"
                    >
                        <UiIcon :name="acao.icone" :size="14" />
                        {{ acao.rotulo }}
                        <span class="tabular-nums text-ink-inverse/60">{{ elegiveis[acao.chave].length }}</span>
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    <!-- ── Expedir em massa ─────────────────────────────────────────────── -->
    <UiDrawer
        v-model:open="expedirAberto"
        :title="elegiveis.expedir.length === 1 ? 'Expedir 1 resgate' : `Expedir ${elegiveis.expedir.length} resgates`"
        description="Uma transportadora para todos, o rastreio de cada um."
    >
        <form id="form-expedir-lote" class="space-y-5" @submit.prevent="expedirEmMassa">
            <UiField as="group" label="Transportadora" required :error="expedicao.errors.carrier">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <label v-for="opcao in activeCarriers" :key="opcao.slug" class="relative flex cursor-pointer">
                        <input
                            v-model="expedicao.carrier"
                            type="radio"
                            :name="radioName"
                            :value="opcao.slug"
                            class="peer sr-only"
                        />
                        <span
                            :class="[
                                'flex min-h-11 w-full items-center justify-center rounded-control border px-2 text-center text-sm font-medium transition-colors',
                                'peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus',
                                expedicao.carrier === opcao.slug
                                    ? 'border-brand-600 bg-brand-50 text-brand-700'
                                    : 'border-line bg-surface text-ink hover:bg-surface-muted',
                            ]"
                        >
                            {{ opcao.name }}
                        </span>
                    </label>
                </div>
            </UiField>

            <div>
                <p class="text-sm font-medium text-ink">
                    {{ usaLink ? 'Link de rastreio' : 'Código de rastreio' }}
                    <span v-if="rastreioOpcional" class="font-normal text-ink-muted">(opcional)</span>
                </p>
                <p class="mt-0.5 text-xs text-ink-muted">
                    <template v-if="rastreioOpcional">Em branco, o sistema obtém o código depois.</template>
                    <template v-else>Um por resgate, como a transportadora informou.</template>
                </p>

                <ul class="mt-3 divide-y divide-line rounded-control border border-line">
                    <li v-for="r in elegiveis.expedir" :key="r.id" class="flex items-center gap-3 px-3 py-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-ink">{{ r.pessoa }}</p>
                            <p class="truncate text-xs text-ink-muted">{{ r.destino }}</p>
                        </div>
                        <!-- Caixa de largura fixa: o UiInput ocupa a do pai, e
                             solto ele espremia o nome da pessoa até sumir. -->
                        <div class="w-44 shrink-0">
                            <UiInput
                                v-model="expedicao.rastreios[r.id]"
                                size="sm"
                                :type="usaLink ? 'url' : 'text'"
                                :maxlength="usaLink ? 500 : 50"
                                autocomplete="off"
                                :placeholder="usaLink ? 'https://…' : 'BR123456789BR'"
                                :aria-label="`Rastreio de ${r.pessoa}`"
                            />
                        </div>
                    </li>
                </ul>
            </div>

        </form>

        <template #footer>
            <UiButton
                type="submit"
                form="form-expedir-lote"
                :disabled="!transportadoraEscolhida || faltaRastreio"
                :loading="expedicao.processing"
            >
                Expedir e avisar
            </UiButton>
            <UiButton variant="ghost" :disabled="expedicao.processing" @click="expedirAberto = false">Cancelar</UiButton>
            <p v-if="faltaRastreio" class="text-sm text-ink-muted">Preencha o rastreio de todos.</p>
        </template>
    </UiDrawer>

    <RedemptionAddressDrawer v-if="canShip" v-model:open="enderecoAberto" :redemption="enderecoAlvo" />

    <!-- ── CPF pendente ─────────────────────────────────────────────────── -->
    <UiDrawer
        v-model:open="cpfDrawer"
        title="Informar CPF"
        description="O CPF vai para a nota fiscal da entrega."
    >
        <form id="form-cpf" class="space-y-4" @submit.prevent="salvarCpf">
            <p v-if="cpfAlvo" class="text-sm text-ink-muted">
                Resgate de <span class="font-medium text-ink">{{ cpfAlvo.pessoa }}</span>
                <template v-if="cpfAlvo.kit"> · {{ cpfAlvo.kit }}</template>
            </p>

            <UiField label="CPF" required :error="cpfForm.errors.cpf">
                <UiInput
                    :model-value="cpfForm.cpf"
                    data-autofocus
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="000.000.000-00"
                    maxlength="14"
                    @update:model-value="cpfForm.cpf = maskCpf($event)"
                />
            </UiField>
        </form>

        <template #footer>
            <UiButton type="submit" form="form-cpf" :loading="cpfForm.processing">Salvar CPF</UiButton>
            <UiButton variant="ghost" :disabled="cpfForm.processing" @click="cpfDrawer = false">
                Cancelar
            </UiButton>
        </template>
    </UiDrawer>
</template>
