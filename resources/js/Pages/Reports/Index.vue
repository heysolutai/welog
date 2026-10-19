<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import UiButton from '../../Components/UiButton.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiInput from '../../Components/UiInput.vue';
import UiSelect from '../../Components/UiSelect.vue';
import UiTabs from '../../Components/UiTabs.vue';
import { formatDate, pipelineIcons, pipelineLabels, pipelineShortLabels, pipelineStages } from '../../lib/format';

/**
 * Acompanhamento: o retrato do que está acontecendo agora e o histórico do
 * período, com a exportação no fim da página.
 *
 * Os filtros valem na hora (sem botão "Aplicar") e a tela se atualiza sozinha
 * a cada minuto enquanto está visível. A chave do topo alterna entre olhar os
 * resgates e olhar a esteira dos Projetos; os três cartões e a lista de baixo
 * acompanham a escolha.
 */
const props = defineProps({
    filtros: { type: Object, required: true },
    atualizado_em: { type: String, required: true },
    andamento: { type: Array, default: () => [] },
    // null para o cliente: a empresa dele é fixa (escopoFixo), não escolha.
    empresas: { type: Array, default: null },
    // null para o Admin Projeto: o Projeto dele já está cravado.
    projetos: { type: Array, default: null },
    escopoFixo: { type: Object, required: true },
    resumo: { type: Object, required: true },
    // A jornada das pessoas hoje (sem período): total e quantas em cada etapa.
    funil: { type: Object, required: true },
    // O Projeto do recorte, para o "Voltar" levar de volta a ele.
    projetoAtual: { type: Object, default: null },
});

/* ---------- Filtros ---------- */

const ATALHOS = [
    { key: '7', label: '7 dias' },
    { key: '30', label: '30 dias' },
    { key: '90', label: '90 dias' },
    { key: '365', label: '12 meses' },
    { key: 'tudo', label: 'Tudo' },
    { key: 'personalizado', label: 'Personalizado' },
];

const periodo = ref(props.filtros.periodo ?? '30');
const from = ref(props.filtros.from ?? '');
const to = ref(props.filtros.to ?? '');
const company = ref(props.filtros.company ?? '');
const project = ref(props.filtros.project ?? '');

/**
 * A lista de Projetos chega inteira do escopo e é filtrada aqui ao trocar a
 * empresa, sem ida ao servidor só para encolher um select. O sufixo com a
 * empresa aparece quando a lista mistura clientes (WeGift sem filtro).
 */
const opcoesDeProjetos = computed(() => {
    if (!props.projetos) return null;

    const daEmpresa = company.value
        ? props.projetos.filter((p) => p.company === Number(company.value))
        : props.projetos;

    const misturaClientes = props.empresas && !company.value;

    return daEmpresa.map((p) => ({
        value: p.value,
        label: misturaClientes ? `${p.label} (${p.companyName})` : p.label,
    }));
});

/** O recorte em tela: vai na querystring da página e dos downloads. */
const parametros = computed(() => {
    const params = { periodo: periodo.value };
    if (periodo.value === 'personalizado') {
        params.from = from.value;
        params.to = to.value;
    }
    if (company.value) params.company = company.value;
    if (project.value) params.project = project.value;
    return params;
});

const carregando = ref(false);

function aplicar() {
    router.get(route('reports.index'), parametros.value, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => (carregando.value = true),
        onFinish: () => (carregando.value = false),
    });
}

function escolherPeriodo(chave) {
    if (periodo.value === chave) return;
    periodo.value = chave;
    // "Personalizado" só busca quando as datas mudarem.
    if (chave !== 'personalizado') aplicar();
}

// Trocar de empresa invalida o Projeto escolhido: era de outra lista.
watch(company, (agora, antes) => {
    if (agora === antes) return;
    project.value = '';
    aplicar();
});
watch(project, (agora, antes) => {
    if (agora !== antes) aplicar();
});

const temFiltro = computed(() => periodo.value !== '30' || (props.empresas && company.value) || project.value);

function limpar() {
    periodo.value = '30';
    company.value = '';
    project.value = '';
    aplicar();
}

/* ---------- Ao vivo ---------- */

let atualizacao = null;

/** A cada minuto, se a aba estiver à vista, renova os números sem piscar. */
function atualizar() {
    if (document.hidden || carregando.value) return;
    router.reload({
        only: ['resumo', 'funil', 'andamento', 'atualizado_em'],
        preserveScroll: true,
        preserveState: true,
    });
}

onMounted(() => {
    atualizacao = setInterval(atualizar, 60000);
});

onBeforeUnmount(() => {
    clearInterval(atualizacao);
    clearTimeout(esperaDaBusca);
});

const horaDaFoto = computed(() => new Date(props.atualizado_em)
    .toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }));

const periodoLabel = computed(() => (periodo.value === 'tudo'
    ? 'Todo o histórico'
    : `${formatDate(props.filtros.from)} a ${formatDate(props.filtros.to)}`));

/* ---------- Visão: resgates ou esteira ---------- */

const visao = ref('resgates');
const VISOES = [
    { key: 'resgates', label: 'Resgates' },
    { key: 'projetos', label: 'Etapas do Projeto' },
];

/**
 * Cada cartão tem a sua cor, clara no fundo e com um brilho leve na borda.
 * As cores repetem as do resto do sistema (o selo do resgate, a etapa da
 * esteira) para o número aqui e o selo na lista contarem a mesma história.
 */
const TONS = {
    ambar: {
        fundo: 'bg-[linear-gradient(160deg,#ffffff_0%,#fffaec_100%)]',
        borda: 'border-[#f7e8bd]',
        brilho: 'shadow-[0_8px_24px_-14px_rgb(245_158_11/0.35)]',
        icone: 'bg-[#fff6dc] text-warning',
        barra: 'bg-[#f7b84b]',
        trilho: 'bg-[#fbefd0]',
        texto: 'text-warning',
    },
    azul: {
        fundo: 'bg-[linear-gradient(160deg,#ffffff_0%,#f3f7ff_100%)]',
        borda: 'border-[#d9e6fa]',
        brilho: 'shadow-[0_8px_24px_-14px_rgb(59_130_246/0.35)]',
        icone: 'bg-[#e9f1fe] text-info',
        barra: 'bg-[#6aa0f5]',
        trilho: 'bg-[#e3ecfb]',
        texto: 'text-info',
    },
    verde: {
        fundo: 'bg-[linear-gradient(160deg,#ffffff_0%,#f1fbf5_100%)]',
        borda: 'border-[#c9eedb]',
        brilho: 'shadow-[0_8px_24px_-14px_rgb(22_163_74/0.35)]',
        icone: 'bg-[#e3f7ec] text-success',
        barra: 'bg-[#4cc285]',
        trilho: 'bg-[#dcf3e6]',
        texto: 'text-success',
    },
    roxo: {
        fundo: 'bg-[linear-gradient(160deg,#ffffff_0%,#f9f5fe_100%)]',
        borda: 'border-[#ebdff9]',
        brilho: 'shadow-[0_8px_24px_-14px_rgb(123_63_191/0.30)]',
        icone: 'bg-[#f3ecfd] text-stage-design',
        barra: 'bg-[#a77bdb]',
        trilho: 'bg-[#efe6fb]',
        texto: 'text-stage-design',
    },
    indigo: {
        fundo: 'bg-[linear-gradient(160deg,#ffffff_0%,#f5f5fe_100%)]',
        borda: 'border-[#dfdffa]',
        brilho: 'shadow-[0_8px_24px_-14px_rgb(67_56_202/0.30)]',
        icone: 'bg-[#ececfd] text-stage-stock',
        barra: 'bg-[#7c74e0]',
        trilho: 'bg-[#e8e8fb]',
        texto: 'text-stage-stock',
    },
};

const porEtapa = computed(() => props.resumo.projetos.por_etapa);

/*
 * A jornada por PESSOA, que é o que o RH acompanha: quantas foram cadastradas
 * e onde cada uma está. Os percentuais são sobre o total de cadastrados, para
 * o 100% só aparecer quando todo mundo recebeu.
 */
const porSituacao = computed(() => props.funil.por_situacao);
const jaResgataram = computed(() => props.funil.total - (porSituacao.value.falta ?? 0));

const ETAPAS_DO_FUNIL = [
    { chave: 'falta', tom: TONS.ambar, icone: 'clock', titulo: 'Faltam resgatar', nota: 'Ainda não escolheram o presente.' },
    { chave: 'separacao', tom: TONS.indigo, icone: 'gift', titulo: 'Em separação', nota: 'Resgataram, kit em separação.' },
    { chave: 'caminho', tom: TONS.azul, icone: 'truck', titulo: 'A caminho', nota: 'Com a transportadora.' },
    { chave: 'entregue', tom: TONS.verde, icone: 'check', titulo: 'Entregues', nota: 'Chegou nas mãos da pessoa.' },
];

const ROTULO_SITUACAO = Object.fromEntries(ETAPAS_DO_FUNIL.map((e) => [e.chave, e.titulo]));

const cartoes = computed(() => {
    if (visao.value === 'resgates') {
        return ETAPAS_DO_FUNIL.map((etapa) => ({
            ...etapa,
            valor: porSituacao.value[etapa.chave] ?? 0,
            base: props.funil.total,
        }));
    }

    const base = props.resumo.projetos.total;
    const soma = (etapas) => etapas.reduce((t, e) => t + (porEtapa.value[e] ?? 0), 0);
    const detalhe = (etapas) => etapas.map((e) => ({ rotulo: pipelineShortLabels[e], valor: porEtapa.value[e] ?? 0 }));

    return [
        {
            chave: 'producao', tom: TONS.roxo, icone: 'stageProduction',
            titulo: 'Em produção', valor: soma(['design', 'production', 'assembly']), base,
            detalhe: detalhe(['design', 'production', 'assembly']),
        },
        {
            chave: 'estoque', tom: TONS.indigo, icone: 'stageStock',
            titulo: 'Estoque disponível', valor: soma(['in_stock']), base,
            nota: 'Kits prontos, resgate aberto ou por liberar.',
        },
        {
            chave: 'envio', tom: TONS.verde, icone: 'stageShipping',
            titulo: 'Envio e concluídos', valor: soma(['shipping', 'done']), base,
            detalhe: detalhe(['shipping', 'done']),
        },
    ];
});

const fatia = (valor, base) => (base > 0 ? Math.round((valor / base) * 100) : 0);

const totalDaVisao = computed(() => ({
    valor: props.resumo.projetos.total,
    rotulo: props.resumo.projetos.total === 1 ? 'Projeto no período' : 'Projetos no período',
}));

/* ---------- Quem está em cada cartão ---------- */

/*
 * Clicar num cartão abre, logo abaixo, a lista das pessoas daquela etapa
 * (o cartão do total abre todas), de 20 em 20. O arquivo traz todas: o caso
 * de uso é baixar "faltam resgatar" para o RH cobrar.
 *
 * O clique é instantâneo porque a primeira página de cada cartão já foi
 * buscada em segundo plano quando a tela abriu, e fica guardada em memória.
 * O que está guardado aparece na hora; se tiver mais de meio minuto, é
 * conferido por trás e trocado sem piscar. A próxima página também é buscada
 * antes do clique. Os números da tela mudaram (atualização ao vivo, outro
 * Projeto): a memória é descartada, porque as listas também mudaram.
 */
const situacaoAberta = ref(undefined); // undefined: fechada · null: todas · chave: uma etapa
const listaAberta = computed(() => situacaoAberta.value !== undefined);
const busca = ref('');
const pagina = ref(1);
const lista = ref({ pessoas: [], total: 0, paginas: 1, por_pagina: 20 });
const carregandoLista = ref(false);
const falhaNaLista = ref(false);

const tituloDaLista = computed(() => (situacaoAberta.value ? ROTULO_SITUACAO[situacaoAberta.value] : 'Todos os colaboradores'));

const VALIDADE_DA_MEMORIA = 30_000;
const LIMITE_DA_MEMORIA = 120;
const memoria = new Map(); // chave -> { dados, em }
const emAndamento = new Map(); // chave -> Promise, para não buscar a mesma lista duas vezes

/** O recorte de uma lista: Cliente e Projeto aplicados, etapa, busca e página. */
function recorte(situacao, termo, numero) {
    const params = {};
    if (props.filtros.company) params.company = props.filtros.company;
    if (props.filtros.project) params.project = props.filtros.project;
    if (situacao) params.situacao = situacao;
    if (termo) params.busca = termo;
    params.pagina = numero;
    return params;
}

const chaveDe = (params) => JSON.stringify(params);

function buscar(params) {
    const chave = chaveDe(params);
    if (emAndamento.has(chave)) return emAndamento.get(chave);

    const pedido = fetch(route('reports.people', params), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then((resposta) => {
            if (!resposta.ok) throw new Error(String(resposta.status));
            return resposta.json();
        })
        .then((dados) => {
            memoria.delete(chave);
            memoria.set(chave, { dados, em: Date.now() });
            // A mais antiga sai primeiro: o Map guarda a ordem de entrada.
            if (memoria.size > LIMITE_DA_MEMORIA) memoria.delete(memoria.keys().next().value);
            return dados;
        })
        .finally(() => emAndamento.delete(chave));

    emAndamento.set(chave, pedido);
    return pedido;
}

/** Cartão vazio não precisa de servidor: o número dele já diz que não há ninguém. */
function vaziaSemBuscar(situacao, termo) {
    if (termo) return false;
    return (situacao ? porSituacao.value[situacao] ?? 0 : props.funil.total) === 0;
}

let exibindo = '';

/** Mostra a lista do recorte atual: da memória na hora, do servidor se preciso. */
async function mostrar() {
    if (!listaAberta.value) return;

    const termo = busca.value.trim();
    const params = recorte(situacaoAberta.value, termo, pagina.value);
    const chave = chaveDe(params);
    exibindo = chave;
    falhaNaLista.value = false;

    if (vaziaSemBuscar(situacaoAberta.value, termo)) {
        lista.value = { pessoas: [], total: 0, paginas: 1, por_pagina: 20 };
        carregandoLista.value = false;
        return;
    }

    const guardada = memoria.get(chave);

    if (guardada) {
        lista.value = guardada.dados;
        carregandoLista.value = false;
        if (Date.now() - guardada.em > VALIDADE_DA_MEMORIA) buscar(params).then((d) => { if (exibindo === chave) lista.value = d; }).catch(() => {});
    } else {
        // A lista anterior fica na tela, apagada, até a nova chegar: nada pula.
        carregandoLista.value = true;

        try {
            const dados = await buscar(params);
            if (exibindo !== chave) return;
            lista.value = dados;
        } catch {
            if (exibindo === chave) falhaNaLista.value = true;
        } finally {
            if (exibindo === chave) carregandoLista.value = false;
        }
    }

    // A lista encolheu enquanto a pessoa estava numa página do fim.
    if (exibindo === chave && pagina.value > lista.value.paginas) {
        pagina.value = lista.value.paginas;
        mostrar();
        return;
    }

    // A próxima página já fica pronta para o clique.
    if (exibindo === chave && pagina.value < lista.value.paginas) {
        buscar(recorte(situacaoAberta.value, termo, pagina.value + 1)).catch(() => {});
    }
}

/** A primeira página de cada cartão, buscada em segundo plano. */
function preparar() {
    [null, ...ETAPAS_DO_FUNIL.map((e) => e.chave)]
        .filter((situacao) => !vaziaSemBuscar(situacao, ''))
        .forEach((situacao) => buscar(recorte(situacao, '', 1)).catch(() => {}));
}

function abrirLista(situacao) {
    // Clicar de novo no cartão aberto fecha a lista.
    if (situacaoAberta.value === situacao) {
        situacaoAberta.value = undefined;
        return;
    }

    situacaoAberta.value = situacao;
    busca.value = '';
    pagina.value = 1;
    mostrar();
}

function irParaPagina(numero) {
    if (numero < 1 || numero > lista.value.paginas || numero === pagina.value) return;
    pagina.value = numero;
    mostrar();
}

let esperaDaBusca = null;

watch(busca, () => {
    if (!listaAberta.value) return;
    clearTimeout(esperaDaBusca);
    esperaDaBusca = setTimeout(() => {
        pagina.value = 1;
        mostrar();
    }, 250);
});

// Os números mudaram (ao vivo ou outro recorte): a memória ficou velha.
watch(
    () => JSON.stringify([props.funil, props.filtros.company, props.filtros.project]),
    () => {
        memoria.clear();
        preparar();
        if (listaAberta.value) mostrar();
    },
);

onMounted(() => setTimeout(preparar, 150));

const primeiraDaPagina = computed(() => (lista.value.total ? (pagina.value - 1) * lista.value.por_pagina + 1 : 0));
const ultimaDaPagina = computed(() => Math.min(pagina.value * lista.value.por_pagina, lista.value.total));

const linkDaLista = (formato) => {
    const { pagina: _pagina, ...params } = recorte(situacaoAberta.value, busca.value.trim(), 1);
    return route('reports.people', { ...params, formato });
};

const formatarData = (iso) => (iso ? formatDate(iso) : '');

/** Posição da etapa na esteira, para o trilho de pontos do andamento. */
const posicao = (etapa) => pipelineStages.indexOf(etapa);

/* ---------- Exportação ---------- */

const EXPORTACOES = [
    {
        // Todas as pessoas cadastradas, tenham resgatado ou não: é a lista que
        // o RH leva para o Excel. A dos cartões é a mesma, só que de uma etapa.
        rota: 'reports.people',
        titulo: 'Colaboradores',
        texto: 'Uma linha por pessoa cadastrada, com o endereço e o rastreio de quem já resgatou.',
        icone: 'users',
    },
    {
        rota: 'reports.projects',
        titulo: 'Projetos',
        texto: 'Uma linha por Projeto: etapa, prazos, pessoas cadastradas, resgates e entregas.',
        icone: 'projects',
    },
];

const link = (rota, formato) => route(rota, { ...parametros.value, formato });

const escopoTexto = computed(() => {
    if (props.escopoFixo.projeto) return `${props.escopoFixo.empresa} · ${props.escopoFixo.projeto}`;
    return props.escopoFixo.empresa;
});
</script>

<template>
    <Head title="Acompanhamento" />

    <PageHeader
        title="Acompanhamento"
        subtitle="Resgates e entregas dos colaboradores, atualizados em tempo real."
    >
        <template #actions>
            <!-- O caminho de volta é um botão de verdade, ao lado do título:
                 o link pequeno acima dele passava despercebido. -->
            <UiButton
                v-if="projetoAtual"
                variant="secondary"
                icon="chevronLeft"
                :href="route('projects.show', projetoAtual.id)"
                :prefetch="['mount', 'hover']"
            >
                Voltar para o Projeto {{ projetoAtual.name }}
            </UiButton>
            <span
                class="inline-flex items-center gap-2 rounded-full border border-success-line bg-success-soft py-1 pr-3 pl-2.5 text-xs font-medium text-success"
                :title="`Os números se atualizam sozinhos a cada minuto. Última leitura às ${horaDaFoto}.`"
            >
                <span class="relative flex size-2">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-success-solid opacity-60" />
                    <span class="relative inline-flex size-2 rounded-full bg-success-solid" />
                </span>
                Ao vivo · {{ horaDaFoto }}
            </span>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <!-- Filtros: valem na hora. -->
        <section class="relative rounded-card bg-surface p-4 shadow-card sm:p-5" aria-label="Filtros">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-4">
                <div class="min-w-0">
                    <p class="mb-1.5 text-xs font-medium text-ink-muted">Período</p>
                    <div class="flex flex-wrap gap-1 rounded-control bg-surface-muted p-1">
                        <button
                            v-for="atalho in ATALHOS"
                            :key="atalho.key"
                            type="button"
                            class="h-8 rounded-[8px] px-3.5 text-[13px] font-medium whitespace-nowrap transition-all"
                            :class="periodo === atalho.key
                                ? 'bg-surface text-ink shadow-[0_1px_3px_rgb(15_23_42/0.12)]'
                                : 'text-ink-muted hover:text-ink'"
                            :aria-pressed="periodo === atalho.key"
                            @click="escolherPeriodo(atalho.key)"
                        >
                            {{ atalho.label }}
                        </button>
                    </div>
                </div>

                <div v-if="periodo === 'personalizado'" class="flex items-end gap-2">
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-ink-muted">De</span>
                        <div class="w-40"><UiInput v-model="from" type="date" size="sm" @change="aplicar" /></div>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-ink-muted">Até</span>
                        <div class="w-40"><UiInput v-model="to" type="date" size="sm" @change="aplicar" /></div>
                    </label>
                </div>

                <label v-if="empresas" class="block">
                    <span class="mb-1.5 block text-xs font-medium text-ink-muted">Cliente</span>
                    <div class="w-56">
                        <UiSelect v-model="company" :options="empresas" placeholder="Todos os Clientes" />
                    </div>
                </label>

                <label v-if="opcoesDeProjetos" class="block">
                    <span class="mb-1.5 block text-xs font-medium text-ink-muted">Projeto</span>
                    <div class="w-64">
                        <UiSelect v-model="project" :options="opcoesDeProjetos" placeholder="Todos os Projetos" />
                    </div>
                </label>

                <span
                    v-if="escopoTexto"
                    class="ml-auto inline-flex h-8 items-center gap-1.5 self-end rounded-full bg-surface-muted px-3 text-xs text-ink-muted"
                >
                    <UiIcon name="companies" :size="14" />
                    {{ escopoTexto }}
                </span>
            </div>

            <!-- No canto, na linha dos rótulos: não empurra os campos. -->
            <button
                v-if="temFiltro"
                type="button"
                class="absolute top-4 right-4 inline-flex items-center gap-1 text-xs font-medium text-ink-muted underline-offset-4 hover:text-ink hover:underline sm:top-5 sm:right-5"
                @click="limpar"
            >
                <UiIcon name="close" :size="12" />
                Limpar filtros
            </button>
        </section>

        <!-- O que acompanhar: resgates ou a esteira dos Projetos. -->
        <section :aria-busy="carregando" class="transition-opacity" :class="carregando ? 'opacity-60' : ''">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div v-if="visao === 'resgates'">
                    <p class="text-base font-semibold text-ink">Situação de hoje</p>
                    <p class="mt-0.5 text-[13px] text-ink-muted">
                        Não depende do período, que vale para as etapas e os arquivos exportados.
                    </p>
                </div>
                <div v-else>
                    <p class="text-[13px] text-ink-muted">{{ periodoLabel }}</p>
                    <p class="mt-0.5 text-2xl font-semibold tracking-tight text-ink tabular-nums">
                        {{ totalDaVisao.valor.toLocaleString('pt-BR') }}
                        <span class="text-base font-normal text-ink-muted">{{ totalDaVisao.rotulo }}</span>
                    </p>
                </div>
                <UiTabs v-model="visao" :tabs="VISOES" variant="segmented" panel-prefix="relatorio" />
            </div>

            <!-- O total de cadastrados primeiro: é a base de tudo abaixo. -->
            <button
                v-if="visao === 'resgates'"
                type="button"
                class="mb-4 block w-full rounded-card bg-surface px-5 py-4 text-left shadow-card transition-shadow"
                :class="situacaoAberta === null ? 'ring-2 ring-brand-400' : 'ring-1 ring-transparent hover:ring-line'"
                :aria-pressed="situacaoAberta === null"
                @click="abrirLista(null)"
            >
                <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
                    <div>
                        <p class="text-sm font-medium text-ink">Colaboradores cadastrados</p>
                        <p class="mt-1 text-[32px] leading-none font-semibold tracking-tight text-ink tabular-nums">
                            {{ funil.total.toLocaleString('pt-BR') }}
                        </p>
                    </div>
                    <div class="w-full min-w-0 sm:w-auto sm:flex-1 sm:max-w-md">
                        <div class="flex items-baseline justify-between gap-3 text-xs">
                            <span class="text-ink-muted">
                                <strong class="font-semibold text-ink tabular-nums">{{ jaResgataram.toLocaleString('pt-BR') }}</strong>
                                {{ jaResgataram === 1 ? 'já resgatou' : 'já resgataram' }}
                            </span>
                            <span class="font-semibold text-brand-600 tabular-nums">{{ fatia(jaResgataram, funil.total) }}%</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-muted">
                            <div
                                class="h-full rounded-full bg-brand-500 transition-[width] duration-700"
                                :style="{ width: `${fatia(jaResgataram, funil.total)}%` }"
                            />
                        </div>
                    </div>
                </div>
                <p class="mt-3 text-xs text-ink-muted">Clique em qualquer cartão para ver quem são.</p>
            </button>

            <div class="grid gap-4" :class="visao === 'resgates' ? 'sm:grid-cols-2 lg:grid-cols-4' : 'md:grid-cols-3'">
                <component
                    :is="visao === 'resgates' ? 'button' : 'article'"
                    v-for="cartao in cartoes"
                    :key="cartao.chave"
                    v-bind="visao === 'resgates' ? { type: 'button', 'aria-pressed': situacaoAberta === cartao.chave } : {}"
                    class="relative flex flex-col overflow-hidden rounded-card border px-5 py-4 text-left"
                    :class="[
                        cartao.tom.fundo, cartao.tom.borda, cartao.tom.brilho,
                        visao === 'resgates' && 'cursor-pointer transition-shadow hover:shadow-card',
                        visao === 'resgates' && situacaoAberta === cartao.chave && 'ring-2 ring-brand-400',
                    ]"
                    @click="visao === 'resgates' && abrirLista(cartao.chave)"
                >
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium text-ink">{{ cartao.titulo }}</p>
                        <span class="grid size-8 shrink-0 place-items-center rounded-full" :class="cartao.tom.icone">
                            <UiIcon :name="cartao.icone" :size="16" />
                        </span>
                    </div>

                    <p class="mt-1 text-[32px] leading-none font-semibold tracking-tight text-ink tabular-nums">
                        {{ cartao.valor.toLocaleString('pt-BR') }}
                    </p>

                    <div class="mt-4 flex items-center gap-3">
                        <div class="h-1 flex-1 overflow-hidden rounded-full" :class="cartao.tom.trilho">
                            <div
                                class="h-full rounded-full transition-[width] duration-700"
                                :class="cartao.tom.barra"
                                :style="{ width: `${fatia(cartao.valor, cartao.base)}%` }"
                            />
                        </div>
                        <span class="text-xs font-semibold tabular-nums" :class="cartao.tom.texto">
                            {{ fatia(cartao.valor, cartao.base) }}%
                        </span>
                    </div>

                    <p v-if="cartao.nota" class="mt-2.5 text-xs text-ink-muted">{{ cartao.nota }}</p>
                    <p v-else-if="cartao.detalhe" class="mt-2.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-muted">
                        <span v-for="item in cartao.detalhe" :key="item.rotulo">
                            {{ item.rotulo }} <strong class="font-semibold text-ink tabular-nums">{{ item.valor }}</strong>
                        </span>
                    </p>
                </component>
            </div>

            <!-- Quem está no cartão clicado. -->
            <div v-if="visao === 'resgates' && listaAberta" class="mt-6 rounded-card bg-surface shadow-card" aria-live="polite">
                <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-semibold text-ink">{{ tituloDaLista }}</h2>
                        <p class="text-xs text-ink-muted">
                            {{ lista.total.toLocaleString('pt-BR') }} {{ lista.total === 1 ? 'pessoa' : 'pessoas' }}
                            <template v-if="busca.trim()"> encontradas na busca</template>
                        </p>
                    </div>
                    <div class="w-full sm:w-64">
                        <UiInput v-model="busca" type="search" size="sm" placeholder="Buscar por nome ou e-mail" :loading="carregandoLista" />
                    </div>
                    <a
                        :href="linkDaLista('xlsx')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-success-line bg-success-soft px-4 text-sm font-medium text-success transition-colors hover:brightness-95"
                    >
                        <UiIcon name="download" :size="15" />
                        Baixar esta lista
                    </a>
                    <button
                        type="button"
                        class="grid size-9 place-items-center rounded-full text-ink-muted transition-colors hover:bg-surface-muted hover:text-ink"
                        aria-label="Fechar a lista"
                        @click="situacaoAberta = undefined"
                    >
                        <UiIcon name="close" :size="16" />
                    </button>
                </div>

                <p v-if="falhaNaLista" role="alert" class="px-5 py-6 text-sm text-danger">
                    Não foi possível carregar a lista. Tente de novo em instantes.
                </p>

                <div
                    v-else-if="lista.pessoas.length"
                    class="transition-opacity duration-150"
                    :class="carregandoLista ? 'opacity-50' : ''"
                    :aria-busy="carregandoLista"
                >
                    <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <caption class="sr-only">{{ tituloDaLista }}</caption>
                        <thead>
                            <tr class="text-left text-xs text-ink-muted">
                                <th scope="col" class="px-5 py-2.5 font-medium">Pessoa</th>
                                <th v-if="!projetoAtual" scope="col" class="px-3 py-2.5 font-medium">Projeto</th>
                                <th v-if="!situacaoAberta" scope="col" class="px-3 py-2.5 font-medium">Situação</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Presente</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Resgatado em</th>
                                <th scope="col" class="px-5 py-2.5 font-medium">Rastreio</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="p in lista.pessoas" :key="p.chave" class="align-top">
                                <th scope="row" class="px-5 py-3 text-left font-normal">
                                    <span class="block font-medium text-ink">{{ p.nome }}</span>
                                    <span class="block text-xs text-ink-muted">{{ p.email }}</span>
                                    <span v-if="!p.acessou" class="mt-1 block text-xs whitespace-nowrap text-warning">Ainda não acessou a plataforma</span>
                                </th>
                                <td v-if="!projetoAtual" class="px-3 py-3 text-ink-muted">{{ p.projeto }}</td>
                                <td v-if="!situacaoAberta" class="px-3 py-3 whitespace-nowrap text-ink">{{ ROTULO_SITUACAO[p.situacao] }}</td>
                                <td class="px-3 py-3 text-ink">
                                    <template v-if="p.itens.length">{{ p.itens.join(' + ') }}</template>
                                    <span v-else class="text-ink-muted">Ainda não escolheu</span>
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap text-ink-muted tabular-nums">{{ formatarData(p.resgatado_em) }}</td>
                                <td class="px-5 py-3 text-ink-muted">
                                    <span v-for="(r, i) in p.rastreios" :key="i" class="block">
                                        <a v-if="r.link" :href="r.link" target="_blank" rel="noopener" class="font-medium text-brand-700 underline-offset-4 hover:underline">
                                            {{ r.codigo || 'Acompanhar' }}
                                        </a>
                                        <span v-else class="text-ink">{{ r.codigo }}</span>
                                        <span v-if="r.transportadora" class="text-xs"> · {{ r.transportadora }}</span>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>

                    <!-- Paginação: 20 por página, com a próxima já buscada. -->
                    <nav
                        v-if="lista.paginas > 1"
                        aria-label="Páginas da lista"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3"
                    >
                        <p class="text-xs text-ink-muted tabular-nums">
                            {{ primeiraDaPagina.toLocaleString('pt-BR') }}–{{ ultimaDaPagina.toLocaleString('pt-BR') }}
                            de {{ lista.total.toLocaleString('pt-BR') }}
                        </p>
                        <div class="flex items-center gap-2">
                            <UiButton size="sm" variant="secondary" icon="chevronLeft" :disabled="pagina === 1" @click="irParaPagina(pagina - 1)">
                                Anterior
                            </UiButton>
                            <span class="px-1 text-xs text-ink-muted tabular-nums">
                                Página {{ pagina }} de {{ lista.paginas.toLocaleString('pt-BR') }}
                            </span>
                            <UiButton size="sm" variant="secondary" :disabled="pagina >= lista.paginas" @click="irParaPagina(pagina + 1)">
                                Próxima
                                <UiIcon name="chevronRight" :size="14" />
                            </UiButton>
                        </div>
                    </nav>
                </div>

                <p v-else-if="carregandoLista" role="status" class="px-5 py-6 text-sm text-ink-muted">Carregando…</p>
                <p v-else class="px-5 py-6 text-sm text-ink-muted">
                    {{ busca.trim() ? 'Ninguém encontrado com essa busca.' : 'Ninguém nesta etapa agora.' }}
                </p>
            </div>

            <!-- Na visão de etapas, onde cada Projeto está. -->
            <div v-if="visao === 'projetos'" class="mt-6 rounded-card bg-surface shadow-card">
                    <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-4">
                        <div>
                            <h2 class="text-sm font-semibold text-ink">Andamento dos Projetos</h2>
                            <p class="text-xs text-ink-muted">Onde cada Projeto está na esteira e quanto já foi resgatado.</p>
                        </div>
                    </header>

                    <ul v-if="andamento.length" class="divide-y divide-line">
                        <li
                            v-for="p in andamento"
                            :key="p.id"
                            class="grid items-center gap-x-6 gap-y-3 px-5 py-4 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.4fr)_minmax(0,1fr)]"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ p.nome }}</p>
                                <p class="truncate text-xs text-ink-muted">
                                    <template v-if="empresas">{{ p.empresa }} · </template>{{ pipelineLabels[p.etapa] ?? p.etapa }}
                                </p>
                            </div>

                            <!-- A esteira em pontos: cheio até a etapa atual. -->
                            <div class="flex items-center gap-1" :aria-label="`Etapa: ${pipelineLabels[p.etapa]}`">
                                <template v-for="(etapa, i) in pipelineStages" :key="etapa">
                                    <span
                                        class="grid size-6 shrink-0 place-items-center rounded-full transition-colors"
                                        :class="i < posicao(p.etapa)
                                            ? 'bg-success-soft text-success'
                                            : i === posicao(p.etapa)
                                                ? 'bg-brand-600 text-white shadow-brand-sm'
                                                : 'bg-surface-muted text-ink-subtle'"
                                        :title="pipelineLabels[etapa]"
                                    >
                                        <UiIcon :name="i < posicao(p.etapa) ? 'check' : pipelineIcons[etapa]" :size="12" />
                                    </span>
                                    <span
                                        v-if="i < pipelineStages.length - 1"
                                        class="h-0.5 min-w-2 flex-1 rounded-full"
                                        :class="i < posicao(p.etapa) ? 'bg-[#a7e8c8]' : 'bg-line'"
                                    />
                                </template>
                            </div>

                            <div class="min-w-0">
                                <div class="flex items-baseline justify-between text-xs">
                                    <span class="text-ink-muted">
                                        <strong class="font-semibold text-ink tabular-nums">{{ p.resgates }}</strong> de {{ p.pessoas }} resgataram
                                    </span>
                                    <span class="text-ink-muted tabular-nums">{{ p.entregues }} entregues</span>
                                </div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-muted">
                                    <div
                                        class="h-full rounded-full bg-success-solid"
                                        :style="{ width: `${fatia(p.resgates, p.pessoas)}%` }"
                                    />
                                </div>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-10 text-center text-sm text-ink-muted">
                        Nenhum Projeto entrou na plataforma neste recorte.
                    </p>
            </div>
        </section>

        <!-- Exportação: o mesmo recorte, em planilha. -->
        <section aria-labelledby="exportar-titulo">
            <div class="mb-3">
                <h2 id="exportar-titulo" class="text-base font-semibold text-ink">Exportar</h2>
                <p class="text-[13px] text-ink-muted">Saem com o Cliente e o Projeto escolhidos acima. O período vale só para Projetos.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="exp in EXPORTACOES"
                    :key="exp.rota"
                    class="flex flex-col gap-4 rounded-card bg-surface p-5 shadow-card sm:flex-row sm:items-center"
                >
                    <!-- Ícone de planilha no verde do Excel. -->
                    <svg class="size-11 shrink-0" viewBox="0 0 48 48" aria-hidden="true">
                        <rect x="10" y="4" width="34" height="40" rx="4" fill="#21A366" />
                        <rect x="27" y="4" width="17" height="10" rx="0" fill="#33C481" />
                        <rect x="27" y="14" width="17" height="10" fill="#21A366" />
                        <rect x="27" y="24" width="17" height="10" fill="#107C41" />
                        <rect x="27" y="34" width="17" height="10" fill="#185C37" />
                        <rect x="4" y="12" width="24" height="24" rx="3" fill="#107C41" />
                        <path d="M10.5 18h3.4l2.1 3.9 2.2-3.9h3.3l-3.7 6 3.8 6h-3.4L16 26l-2.3 4h-3.3l3.8-6z" fill="#fff" />
                    </svg>

                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-semibold text-ink">{{ exp.titulo }}</h3>
                        <p class="mt-0.5 text-xs text-ink-muted">{{ exp.texto }}</p>
                    </div>

                    <!-- Download de arquivo: <a> de verdade, que o Inertia não intercepta. -->
                    <div class="flex shrink-0 gap-2">
                        <a
                            :href="link(exp.rota, 'xlsx')"
                            class="inline-flex h-9 items-center gap-1.5 rounded-control border border-[#bfe5cf] bg-[#ebf7f0] px-3.5 text-[13px] font-semibold whitespace-nowrap text-[#0b5e31] transition-colors hover:border-[#9ed6b6] hover:bg-[#dcf1e5]"
                        >
                            <UiIcon name="download" :size="15" />
                            Excel
                        </a>
                        <a
                            :href="link(exp.rota, 'csv')"
                            class="inline-flex h-9 items-center gap-1.5 rounded-control border border-line bg-surface px-3.5 text-[13px] font-medium whitespace-nowrap text-ink transition-colors hover:bg-surface-muted"
                        >
                            CSV
                        </a>
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>
