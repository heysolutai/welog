<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import KpiCard from '../../Components/KpiCard.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import UiBadge from '../../Components/UiBadge.vue';
import UiButton from '../../Components/UiButton.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiField from '../../Components/UiField.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiPagination from '../../Components/UiPagination.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiRowLink from '../../Components/UiRowLink.vue';
import UiSearchInput from '../../Components/UiSearchInput.vue';
import UiSelect from '../../Components/UiSelect.vue';
import UiTable from '../../Components/UiTable.vue';
import { deadlineState, formatDate, formatQuantity, pipelineLabels, pipelineStages, releaseState } from '../../lib/format';

const props = defineProps({
    projects: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, required: true },
    companies: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
    /** Contas (CNPJs) da Omie. Vazio = um CNPJ so; a tela nem mostra o filtro. */
    omieAccounts: { type: Array, default: () => [] },
});

const page = usePage();
// Visão do lado do cliente: Admin Cliente (empresa toda) ou Admin Projeto
// (um Projeto). Para os dois a lista já vem escopada do backend — some o
// filtro de empresa, o painel de KPIs da operação e o rótulo "cliente".
const ownCompanyOnly = computed(
    () => page.props.auth?.user?.is_client_admin === true
        || page.props.auth?.user?.is_project_admin === true,
);

const q = ref(props.filters.q ?? '');
const company = ref(props.filters.company ? String(props.filters.company) : '');
const period = ref(props.filters.period ?? '');
const stage = ref(props.filters.stage ?? '');
const situation = ref(props.filters.situation ?? '');

watch(() => props.filters, (next) => {
    q.value = next.q ?? '';
    company.value = next.company ? String(next.company) : '';
    period.value = next.period ?? '';
    stage.value = next.stage ?? '';
    situation.value = next.situation ?? '';
});

const companyOptions = computed(() => [
    { value: '', label: 'Todas as empresas' },
    ...props.companies.map((item) => ({ value: String(item.id), label: item.name })),
]);
const stageOptions = [
    { value: '', label: 'Todas as etapas' },
    ...pipelineStages.map((value) => ({ value, label: pipelineLabels[value] })),
];
const periodOptions = [
    { value: '', label: 'Todos os períodos' },
    { value: 'this_month', label: 'Este mês' },
    { value: 'next_30', label: 'Próximos 30 dias' },
    { value: 'overdue', label: 'Em atraso' },
];
const situationOptions = [
    { value: '', label: 'Todas as situações' },
    { value: 'producing', label: 'Em ciclo produtivo' },
    { value: 'ready', label: 'Pendente de Liberação' },
    { value: 'released', label: 'Resgates Liberados' },
];
const account = ref(props.filters.account ?? '');
const multiConta = computed(() => props.omieAccounts.length > 1);
const accountOptions = computed(() => [{ value: '', label: 'Todos os CNPJs' }, ...props.omieAccounts]);
const activeFilters = computed(() => [q.value, company.value, period.value, stage.value, situation.value, account.value].filter(Boolean).length);
const percent = (value) => props.stats.total ? Math.round((value / props.stats.total) * 100) : 0;

function applyFilters() {
    router.get(route('projects.index'), {
        q: q.value.trim() || undefined,
        company: company.value || undefined,
        period: period.value || undefined,
        stage: stage.value || undefined,
        situation: situation.value || undefined,
        account: account.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function clearFilters() {
    q.value = company.value = period.value = stage.value = situation.value = account.value = '';
    applyFilters();
}

/*
 * Sem a coluna Kit. Nomes como "Kit Segurança (Capacete + Óculos) Pintou
 * Parceria Suvinil +3" ocupavam a linha inteira e empurravam prazo, estágio e
 * situação para a beirada — a lista virava leitura, não varredura. Quem precisa
 * saber qual kit abre o Projeto; a busca continua alcançando o nome do kit.
 */
const columns = computed(() => [
    { key: 'name', label: ownCompanyOnly.value ? 'Projeto' : 'Projeto / cliente' },
    // Quantidade e prazo saem da visão do cliente (pedido dele): os dois são
    // detalhe do Projeto, e dentro dele aparecem com o contexto que a linha da
    // lista não tem — o prazo com o aviso contratual, a quantidade ao lado do
    // que já foi resgatado. Aqui só engordavam a tabela.
    ...(ownCompanyOnly.value ? [] : [
        { key: 'quantity', label: 'Qtd.', numeric: true },
        { key: 'deadline', label: 'Prazo' },
    ]),
    // Etapa é vocabulário de produção; para o cliente basta a Situação
    // (pedido do UAT: sem os cards de Design/Produção/Montagem na visão dele).
    ...(ownCompanyOnly.value ? [] : [{ key: 'stage', label: 'Etapa' }]),
    { key: 'situation', label: 'Situação' },
    { key: 'actions', label: 'Ações', align: 'end', srOnlyLabel: true },
]);

/**
 * Aviso contratual no prazo, só na visão do cliente (UAT): a data que ele vê
 * pode se mover com a produção, e a WeGift precisa dizer isso ANTES do
 * questionamento, não depois.
 */
const DEADLINE_HINT_CLIENT = 'Entrega sujeita a ajustes de prazo';

/*
 * `default` fica em `text-ink`, não em cinza: prazo dentro do combinado é dado
 * comum, e apagá-lo faria os dois casos que importam — vence hoje, atrasado —
 * perderem contraste justamente contra o que precisam se destacar.
 */
const deadlineTones = {
    default: 'text-ink',
    warning: 'font-medium text-warning',
    danger: 'font-medium text-danger',
};

const rows = computed(() => props.projects.data.map((project) => ({
    ...project,
    name: project.name.replace(/\s+[—–-]\s+/g, ' '),
    deadline: project.delivery_deadline ? formatDate(project.delivery_deadline) : null,
    deadlineHint: deadlineState(project.delivery_deadline),
    // Prazo que pede atenção — atrasado ou vencendo. Calculado aqui para o
    // template não repetir a comparação com 'default' em quatro lugares.
    alert: (deadlineState(project.delivery_deadline)?.tone ?? 'default') !== 'default',
    situation: releaseState(project),
})));
</script>

<template>
    <Head title="Projetos" />

    <PageHeader
        :title="ownCompanyOnly ? 'Projetos da sua empresa' : 'Projetos'"
        :subtitle="ownCompanyOnly ? 'Acompanhe prazos e o andamento dos seus projetos.' : 'Acompanhe prazos, etapas e o andamento da produção.'"
    >
        <template #actions>
            <UiRefreshButton />
            <!-- Sem "Admin Cliente" aqui: ninguém cadastra o próprio nível.
                 Quem cria Admin Cliente é a WeGift, na ficha da Empresa. -->
            <UiButton v-if="canCreate" icon="plus" :href="route('projects.create')">Criar Projeto</UiButton>
        </template>
    </PageHeader>

    <!-- Painel de operação: os KPIs são régua interna da WeGift (prazos
         críticos, % em produção) e saem da visão do cliente (UAT). -->
    <section v-if="!ownCompanyOnly" aria-label="Resumo dos projetos" class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard label="Total de projetos" :value="stats.total" icon="projects" filled :hint="`${projects.data.length} nesta página`" />
        <KpiCard label="Em produção" :value="stats.production" icon="clock" :hint="`${percent(stats.production)}% do total`" />
        <KpiCard label="Prontos" :value="stats.ready" icon="check" tone="success" :hint="`${percent(stats.ready)}% concluídos`" />
        <KpiCard label="Prazos críticos" :value="stats.critical" icon="calendar" tone="danger" :hint="`${stats.overdue} atrasados · ${stats.due_today} hoje`" />
    </section>

    <section class="mb-4 rounded-card bg-surface p-3 shadow-card sm:p-4" aria-label="Filtros de projetos">
        <UiSearchInput v-model="q" placeholder="Buscar por projeto, cliente ou kit..." :result-label="`${projects.total} ${projects.total === 1 ? 'resultado' : 'resultados'}`" @search="applyFilters" />
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <UiField v-if="!ownCompanyOnly" label="Empresa"><UiSelect v-model="company" :options="companyOptions" @change="applyFilters" /></UiField>
            <UiField label="Período"><UiSelect v-model="period" :options="periodOptions" @change="applyFilters" /></UiField>
            <UiField v-if="multiConta" label="CNPJ de origem">
                <UiSelect v-model="account" :options="accountOptions" @change="applyFilters" />
            </UiField>
            <UiField v-if="!ownCompanyOnly" label="Etapa"><UiSelect v-model="stage" :options="stageOptions" @change="applyFilters" /></UiField>
            <UiField label="Situação"><UiSelect v-model="situation" :options="situationOptions" @change="applyFilters" /></UiField>
            <div class="flex items-end"><UiButton variant="ghost" size="sm" :disabled="!activeFilters" @click="clearFilters">Limpar filtros<span v-if="activeFilters"> ({{ activeFilters }})</span></UiButton></div>
        </div>
    </section>

    <UiTable :columns="columns" :caption="ownCompanyOnly ? 'Projetos e situação de cada um' : 'Projetos, kit contratado, quantidade, prazo, etapa e situação'">
        <tr v-for="project in rows" :key="project.id">
            <th scope="row">
                <UiRowLink :href="route('projects.show', project.id)" :hint="ownCompanyOnly ? null : project.company">{{ project.name }}</UiRowLink>
                <!-- De qual CNPJ da WeGift veio: so aparece com mais de um. -->
                <UiBadge v-if="project.origem" variant="neutral" class="mt-1">{{ project.origem }}</UiBadge>
            </th>
            <td v-if="!ownCompanyOnly" class="whitespace-nowrap text-ink">
                {{ formatQuantity(project.quantity) }} <span class="text-ink-muted">un.</span>
            </td>
            <td v-if="!ownCompanyOnly" class="whitespace-nowrap">
                <!--
                    Calha fixa de 14px para o ícone, presente em TODA linha.
                    Sem ela o ícone empurra só as linhas em alerta e a coluna
                    de datas sai serrilhada. Com ela as datas alinham e os
                    avisos formam uma faixa própria, que o olho percorre.

                    O ícone é o que impede a cor de carregar sozinha o "está
                    atrasado": leitor de tela e quem não distingue vermelho
                    ficariam sem o aviso (§12.4). O prazo em dias saiu daqui e
                    vive na tela do Projeto.
                -->
                <span class="flex items-center gap-1.5">
                    <UiIcon
                        v-if="project.deadline && project.alert"
                        name="alert"
                        :size="14"
                        :class="deadlineTones[project.deadlineHint.tone]"
                    />
                    <span v-else class="size-3.5 shrink-0" aria-hidden="true" />

                    <!-- Visão do cliente: o prazo carrega o aviso contratual no
                         hover (UAT) e repete no sr-only para leitor de tela. -->
                    <span
                        v-if="project.deadline"
                        :class="[deadlineTones[project.deadlineHint?.tone ?? 'default'], ownCompanyOnly && 'cursor-help underline decoration-dotted decoration-ink-subtle underline-offset-4']"
                        :title="ownCompanyOnly ? DEADLINE_HINT_CLIENT : null"
                    >
                        {{ project.deadline }}
                        <span v-if="project.alert" class="sr-only">, {{ project.deadlineHint.label }}</span>
                        <span v-if="ownCompanyOnly" class="sr-only">. {{ DEADLINE_HINT_CLIENT }}.</span>
                    </span>
                    <span v-else class="text-ink-muted">Não informado</span>
                </span>
            </td>
            <td v-if="!ownCompanyOnly"><StatusBadge :status="project.pipeline_status" /></td>
            <td><StatusBadge :status="project.situation" kind="projectRelease" /></td>
            <!--
                Ação só com ícone. O botão com rótulo repetia "Ver detalhes"
                25 vezes numa coluna, custava ~110px de largura — o que
                empurrava Situação para fora da tela — e emoldurava cada linha
                com um retângulo de borda cinza, que é metade da aparência de
                planilha. O destino é o mesmo do nome da linha, e o rótulo
                continua existindo para leitor de tela e no hover.
            -->
            <!--
                Na visão do cliente o rótulo aparece escrito, igual ao da árvore
                de Clientes: são poucas linhas, sobra largura com Qtd. e Prazo
                fora, e é o gesto que ele faz em toda linha. Na visão da WeGift
                continua só o ícone — lá são 25 linhas por página, e o rótulo
                repetido 25 vezes custava ~110px que empurravam Situação para
                fora da tela.
            -->
            <td>
                <UiButton
                    variant="ghost"
                    size="sm"
                    icon="eye"
                    :icon-only="!ownCompanyOnly"
                    label="Ver detalhes"
                    :href="route('projects.show', project.id)"
                    prefetch
                >
                    Ver detalhes
                </UiButton>
            </td>
        </tr>
        <template #empty><UiEmpty icon="projects" message="Nenhum projeto encontrado com estes filtros." /></template>
    </UiTable>

    <UiPagination :links="projects.links" label="Projetos" />
</template>
