<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import KpiCard from '../Components/KpiCard.vue';
import PipelineFlow from '../Components/PipelineFlow.vue';
import UiBadge from '../Components/UiBadge.vue';
import UiButton from '../Components/UiButton.vue';
import UiCard from '../Components/UiCard.vue';
import UiEmpty from '../Components/UiEmpty.vue';
import UiIcon from '../Components/UiIcon.vue';
import UiRefreshButton from '../Components/UiRefreshButton.vue';
import UiRowLink from '../Components/UiRowLink.vue';
import UiTable from '../Components/UiTable.vue';
import { formatDate, pipelineIcons, pipelineLabels } from '../lib/format';

const props = defineProps({
    pipeline: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    summary: { type: Object, required: true },
    recentProjects: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);
const canCreate = computed(() => user.value?.can_critical ?? false);

// Só o primeiro nome: "Olá, Admin WeGift!" soa como etiqueta de crachá.
const firstName = computed(() => (user.value?.name ?? '').trim().split(/\s+/)[0] ?? '');

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

const kpis = computed(() => [
    {
        label: 'Projetos ativos',
        value: props.stats.totalProjects,
        icon: 'projects',
        tone: 'brand',
        hint: props.stats.newToday > 0 ? `${plural(props.stats.newToday, 'novo hoje', 'novos hoje')}` : 'nenhum novo hoje',
        trend: props.stats.newToday > 0 ? 'up' : null,
        href: route('projects.index'),
    },
    {
        label: 'Aguardando liberação',
        value: props.stats.awaitingRelease,
        icon: 'clock',
        tone: props.stats.awaitingRelease > 0 ? 'warning' : 'brand',
        hint: props.stats.awaitingRelease > 0 ? 'precisam da sua ação' : 'nada parado',
        href: route('projects.index'),
    },
    {
        label: 'Resgates liberados',
        value: props.stats.shippedRedemptions,
        icon: 'truck',
        tone: 'brand',
        hint: props.stats.shippedRedemptions > 0 ? 'a caminho do destinatário' : 'nada em transporte',
        href: route('redemptions.index'),
    },
    {
        label: 'Resgates concluídos',
        value: props.stats.deliveredRedemptions,
        icon: 'check',
        tone: props.stats.deliveredRedemptions > 0 ? 'success' : 'brand',
        hint: props.stats.deliveredRedemptions > 0 ? 'entregues' : 'nenhuma entrega ainda',
        href: route('redemptions.index'),
    },
]);

/**
 * O banner só aparece quando há trabalho parado esperando alguém. Um aviso
 * que está sempre na tela deixa de ser aviso.
 */
const attention = computed(() => {
    const partes = [];

    if (props.stats.awaitingRelease > 0) {
        partes.push(`${plural(props.stats.awaitingRelease, 'projeto aguardando', 'projetos aguardando')} liberação`);
    }
    if (props.stats.blockedRows > 0) {
        partes.push(`${plural(props.stats.blockedRows, 'linha bloqueada', 'linhas bloqueadas')} em Importações Parceiros`);
    }

    const total = props.stats.awaitingRelease + props.stats.blockedRows;

    return total > 0
        // Sem "Existem": com um item só, a frase saía "Existem 1 projeto".
        ? { total, texto: `${partes.join(' e ')}.` }
        : null;
});

const summaryRows = computed(() => [
    { key: 'done', label: 'Concluídos', value: props.summary.done, icon: 'check', tone: 'success' },
    { key: 'progress', label: 'Em andamento', value: props.summary.inProgress, icon: 'clock', tone: 'brand' },
    { key: 'late', label: 'Em atraso', value: props.summary.late, icon: 'alert', tone: 'danger' },
]);

const TONE_CLASSES = {
    success: 'bg-success-soft text-success',
    brand: 'bg-brand-50 text-brand-700',
    danger: 'bg-danger-soft text-danger',
};

const columns = [
    { key: 'project', label: 'Projeto / cliente' },
    { key: 'stage', label: 'Etapa atual' },
    { key: 'deadline', label: 'Prazo' },
    { key: 'situation', label: 'Situação' },
];

/** Prazo é o que decide a cor da linha — não o estágio. */
function deadlineState(project) {
    if (project.released) return { variant: 'success', label: 'Liberado' };
    if (project.days_left === null) return { variant: 'neutral', label: 'Sem prazo' };
    if (project.days_left < 0) return { variant: 'danger', label: 'Em atraso' };
    if (project.days_left <= 3) return { variant: 'warning', label: 'Prazo curto' };

    return { variant: 'success', label: 'No prazo' };
}

function deadlineHint(project) {
    if (project.days_left === null) return null;
    if (project.days_left < 0) return `${plural(Math.abs(project.days_left), 'dia', 'dias')} de atraso`;
    if (project.days_left === 0) return 'vence hoje';

    return `${plural(project.days_left, 'dia restante', 'dias restantes')}`;
}

const dateBR = (iso) => (iso ? formatDate(iso) : 'Não informado');
</script>

<template>
    <Head title="Visão geral" />

    <div class="flex flex-wrap items-start justify-between gap-4 pb-5">
        <div>
            <h1 class="text-title font-bold text-ink">Olá, {{ firstName }}! 👋</h1>
            <p class="mt-1 text-sm text-ink-muted">
                Acompanhe o andamento dos seus projetos e resgates.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <UiRefreshButton />
            <UiButton v-if="canCreate" icon="plus" :href="route('projects.create')">Novo Projeto</UiButton>
        </div>
    </div>

    <!-- O primeiro cartão vai em chapa: é a entrada da faixa. Quatro cartões
         brancos iguais não têm começo, e o olho lê os quatro com o mesmo peso.
         Só o primeiro — dois cheios voltariam a empatar. -->
    <section aria-label="Indicadores" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
            v-for="(kpi, index) in kpis"
            :key="kpi.label"
            v-bind="kpi"
            :filled="index === 0"
        />
    </section>

    <!-- Aparece só quando há o que fazer; caso contrário some da tela. -->
    <section
        v-if="attention"
        class="mt-5 flex flex-wrap items-center gap-4 rounded-card bg-surface p-4 shadow-glow-warning"
    >
        <span class="flex size-10 shrink-0 items-center justify-center rounded-control bg-warning-soft text-warning">
            <UiIcon name="alert" :size="20" aria-hidden="true" />
        </span>

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-ink">
                {{ plural(attention.total, 'item precisa', 'itens precisam') }} da sua atenção
            </p>
            <p class="mt-0.5 text-sm text-ink-muted">{{ attention.texto }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <UiButton
                v-if="stats.awaitingRelease > 0"
                variant="secondary"
                size="sm"
                :href="route('projects.index')"
            >
                Revisar projetos
            </UiButton>
            <UiButton v-if="stats.blockedRows > 0" variant="warning" size="sm" :href="route('imports.index', { etapa: 'bloqueados' })">
                Ver inconsistências
            </UiButton>
        </div>
    </section>

    <div class="mt-5 grid gap-4 lg:grid-cols-3">
        <UiCard title="Fluxo dos projetos" class="lg:col-span-2">
            <!-- Cada etapa é um link: clicar abre a lista de Projetos já
                 filtrada naquele estágio. O número deixa de ser só leitura. -->
            <PipelineFlow
                :stages="pipeline"
                updated-at="atualizado agora"
                :href-for="(status) => route('projects.index', { stage: status })"
            />
        </UiCard>

        <UiCard title="Resumo da operação">
            <ul class="divide-y divide-line">
                <li
                    v-for="row in summaryRows"
                    :key="row.key"
                    class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                >
                    <span :class="['flex size-8 shrink-0 items-center justify-center rounded-control', TONE_CLASSES[row.tone]]">
                        <UiIcon :name="row.icon" :size="16" aria-hidden="true" />
                    </span>
                    <span class="flex-1 text-sm text-ink-muted">{{ row.label }}</span>
                    <span class="text-lg font-semibold tabular-nums text-ink">{{ row.value }}</span>
                </li>
            </ul>
        </UiCard>
    </div>

    <UiCard class="mt-5" padding="none">
        <div class="flex items-center justify-between gap-4 border-b border-line px-4 py-3">
            <h2 class="text-sm font-semibold text-ink">Projetos recentes</h2>
            <Link
                :href="route('projects.index')"
                class="text-sm font-medium text-brand-700 hover:text-brand-800"
            >
                Ver todos os projetos
            </Link>
        </div>

        <UiEmpty
            v-if="!recentProjects.length"
            icon="projects"
            message="Nenhum projeto ainda. Eles nascem do webhook de pedido da Omie."
        />

        <UiTable v-else :columns="columns" caption="Projetos criados recentemente">
            <tr v-for="project in recentProjects" :key="project.id">
                <th scope="row" class="font-normal">
                    <UiRowLink
                        :href="route('projects.show', project.id)"
                        :hint="project.company"
                        class="max-w-72"
                    >
                        {{ project.name }}
                    </UiRowLink>
                </th>

                <td>
                    <span class="flex items-center gap-2">
                        <span
                            :class="['flex size-7 shrink-0 items-center justify-center rounded-control', `stage-chip stage-chip--${project.pipeline_status}`]"
                        >
                            <UiIcon :name="pipelineIcons[project.pipeline_status] ?? 'projects'" :size="14" aria-hidden="true" />
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm text-ink">
                                {{ pipelineLabels[project.pipeline_status] ?? project.pipeline_status }}
                            </span>
                            <span class="block text-xs text-ink-muted">desde {{ dateBR(project.created_at) }}</span>
                        </span>
                    </span>
                </td>

                <td>
                    <span class="flex items-center gap-1.5">
                        <UiIcon name="calendar" :size="14" aria-hidden="true" class="shrink-0 text-ink-subtle" />
                        <span class="min-w-0">
                            <span class="block text-sm tabular-nums text-ink">{{ dateBR(project.delivery_deadline) }}</span>
                            <span v-if="deadlineHint(project)" class="block truncate text-xs text-ink-muted">
                                {{ deadlineHint(project) }}
                            </span>
                        </span>
                    </span>
                </td>

                <td>
                    <UiBadge :variant="deadlineState(project).variant" dot>
                        {{ deadlineState(project).label }}
                    </UiBadge>
                </td>
            </tr>
        </UiTable>
    </UiCard>
</template>

<style scoped>
/* Mesmas cores de etapa do PipelineFlow: quem reconheceu o roxo de "NF
   emitida" no fluxo reconhece na tabela sem reaprender. */
.stage-chip--design     { background-color: var(--color-stage-design-soft);     color: var(--color-stage-design); }
.stage-chip--production { background-color: var(--color-stage-production-soft); color: var(--color-stage-production); }
.stage-chip--assembly   { background-color: var(--color-stage-assembly-soft);   color: var(--color-stage-assembly); }
.stage-chip--shipping   { background-color: var(--color-stage-shipping-soft);   color: var(--color-stage-shipping); }
.stage-chip--in_stock   { background-color: var(--color-stage-stock-soft);      color: var(--color-stage-stock); }
.stage-chip--done       { background-color: var(--color-stage-done-soft);       color: var(--color-stage-done); }
</style>
