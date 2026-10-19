<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import UiAlert from '../../../Components/UiAlert.vue';
import UiBadge from '../../../Components/UiBadge.vue';
import UiButton from '../../../Components/UiButton.vue';
import UiCard from '../../../Components/UiCard.vue';
import UiCode from '../../../Components/UiCode.vue';
import UiEmpty from '../../../Components/UiEmpty.vue';
import UiField from '../../../Components/UiField.vue';
import UiIcon from '../../../Components/UiIcon.vue';
import UiJsonView from '../../../Components/UiJsonView.vue';
import UiPagination from '../../../Components/UiPagination.vue';
import UiRefreshButton from '../../../Components/UiRefreshButton.vue';
import UiSearchInput from '../../../Components/UiSearchInput.vue';
import UiSelect from '../../../Components/UiSelect.vue';
import UiTable from '../../../Components/UiTable.vue';
import { formatDate, webhookTopicLabel } from '../../../lib/format';
import UiTabs from '../../../Components/UiTabs.vue';

const props = defineProps({
    events: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    topics: { type: Array, default: () => [] },
    periods: { type: Object, default: () => ({}) },
    counts: { type: Object, required: true },
    diagnosis: { type: Object, required: true },
});

const status = ref(props.filters.status ?? '');
const source = ref(props.filters.source ?? '');
const topic = ref(props.filters.topic ?? '');
const period = ref(props.filters.period ?? '');
const q = ref(props.filters.q ?? '');

// A página não é remontada a cada filtro (`preserveState`): sem este watch,
// voltar no histórico deixaria os campos mostrando a busca antiga.
watch(
    () => props.filters,
    (novos) => {
        status.value = novos.status ?? '';
        source.value = novos.source ?? '';
        topic.value = novos.topic ?? '';
        period.value = novos.period ?? '';
        q.value = novos.q ?? '';
    },
);

const tabs = computed(() => [
    { key: '', label: 'Todos', count: props.counts.total },
    { key: 'failed', label: 'Com falha', count: props.counts.failed },
    { key: 'received', label: 'Na fila', count: props.counts.received },
    { key: 'processed', label: 'Processados', count: props.counts.processed },
    { key: 'ignored', label: 'Ignorados', count: props.counts.ignored },
]);

const periodOptions = computed(() =>
    Object.entries(props.periods).map(([value, label]) => ({ value, label })),
);

/** Filtros ativos além da aba de situação — é o que o botão de limpar apaga. */
const activeFilters = computed(() =>
    [source.value, topic.value, period.value, q.value].filter(Boolean).length,
);

function applyFilters() {
    router.get(
        route('admin.webhooks.index'),
        {
            status: status.value || undefined,
            source: source.value || undefined,
            topic: topic.value || undefined,
            period: period.value || undefined,
            q: q.value.trim() || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function clearFilters() {
    source.value = '';
    topic.value = '';
    period.value = '';
    q.value = '';
    applyFilters();
}

/**
 * Trocar a origem invalida o tópico escolhido: a lista de tópicos vem filtrada
 * por origem, e um tópico da Omie com origem Trello devolveria zero linhas sem
 * explicar de onde veio o vazio.
 */
function onSourceChange() {
    topic.value = '';
    applyFilters();
}

/**
 * O evento é gravado ANTES de processar. Tabela vazia não é "sem novidade":
 * é a origem não estar chamando — que é uma causa completamente diferente e
 * o operador não tem como adivinhar isso da tela.
 */
const diagnostico = computed(() => {
    const d = props.diagnosis;

    // Lê `diagnosis`, não `counts`: as contagens das abas respeitam os filtros,
    // e com elas uma busca sem resultado viraria "a integração nunca recebeu
    // nada" — diagnóstico errado sobre um sistema saudável.
    if (d.totalEvents === 0) {
        return {
            variant: 'danger',
            title: 'Nenhum webhook recebido até agora',
            body: d.omieTokenSet
                ? `A requisição não chegou. Confirme no painel da Omie que a URL registrada é ${d.webhookUrl} e que o token bate com o OMIE_WEBHOOK_TOKEN do servidor. Token divergente responde 403 e não grava linha aqui.`
                : 'OMIE_WEBHOOK_TOKEN está VAZIO no servidor: com ele em branco toda chamada é recusada com 403. Preencha antes de registrar a URL na Omie.',
        };
    }

    if (d.queued > 0) {
        return {
            variant: 'warning',
            title: `${d.queued} evento(s) parado(s) na fila`,
            body: 'Os eventos chegaram mas o job não rodou. Quase sempre é o worker fora do ar: confira `docker compose ps worker` e `php artisan queue:failed`.',
        };
    }

    if (d.orderWebhooks > 0 && d.projectsFromOmie === 0) {
        return {
            variant: 'warning',
            title: 'Chegaram pedidos, mas nenhum Projeto foi criado',
            body: `Causas prováveis: o pedido tem marcador "Importação" (pedidos de Importações Parceiros não geram Projeto, é esperado), o ConsultarPedido devolveu vazio, ou o driver está em "${d.omieDriver}". Abra um evento e veja o payload.`,
        };
    }

    if (d.failedEvents > 0) {
        return {
            variant: 'warning',
            title: `${d.failedEvents} evento(s) com falha`,
            body: 'Abra a linha para ver o erro. Depois de corrigir a causa, use "Reprocessar": nada é reenviado à origem, só o processamento local roda de novo.',
        };
    }

    return null;
});

const columns = [
    { key: 'event', label: 'Evento' },
    { key: 'topic', label: 'Tópico' },
    { key: 'status', label: 'Situação' },
    { key: 'when', label: 'Recebido' },
    { key: 'actions', label: 'Ações', srOnlyLabel: true },
];

const STATUS = {
    received: { variant: 'warning', label: 'Na fila' },
    processed: { variant: 'success', label: 'Processado' },
    ignored: { variant: 'neutral', label: 'Ignorado' },
    failed: { variant: 'danger', label: 'Falhou' },
};

const expanded = ref(null);
// Qual evento está mostrando o JSON cru em vez da árvore.
const raw = ref(null);
const toggle = (id) => (expanded.value = expanded.value === id ? null : id);

const reprocessForm = useForm({});
function reprocess(id) {
    reprocessForm.post(route('admin.webhooks.reprocess', id), { preserveScroll: true });
}

const dateTime = (iso) => formatDate(iso, true);
</script>

<template>
    <Head title="Log de Webhooks" />

    <PageHeader
        title="Log de Webhooks"
        subtitle="Tudo que Omie e Trello enviaram, com o que aconteceu depois. O evento é gravado antes de qualquer processamento."
    >
        <template #actions>
            <UiRefreshButton />
        </template>
    </PageHeader>

    <UiAlert v-if="diagnostico" :variant="diagnostico.variant" :title="diagnostico.title" class="mb-4">
        {{ diagnostico.body }}
    </UiAlert>

    <div class="mb-3 space-y-3">
        <UiTabs
            v-model="status"
            :tabs="tabs"
            variant="segmented"
            panel-prefix="webhooks"
            @update:model-value="applyFilters"
        />

        <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
            <!-- Busca cobre nº do evento, id da origem e o conteúdo do payload:
                 a pergunta real é "cadê o webhook do pedido 66661?", e esse
                 número só existe dentro do payload. -->
            <div class="min-w-56 flex-1" role="search">
                <UiSearchInput
                    v-model="q"
                    placeholder="Nº do evento, id da origem ou conteúdo"
                    @search="applyFilters"
                />
            </div>

            <UiField label="Origem">
                <UiSelect
                    v-model="source"
                    size="sm"
                    width="sm"
                    :options="[
                        { value: '', label: 'Todas' },
                        { value: 'omie', label: 'Omie' },
                        { value: 'trello', label: 'Trello' },
                        { value: 'suvinil', label: 'Suvinil' },
                    ]"
                    @change="onSourceChange"
                />
            </UiField>

            <UiField label="Tópico">
                <UiSelect
                    v-model="topic"
                    size="sm"
                    width="md"
                    :options="[{ value: '', label: 'Todos' }, ...topics.map((t) => ({ value: t, label: webhookTopicLabel(t) }))]"
                    @change="applyFilters"
                />
            </UiField>

            <UiField label="Período">
                <UiSelect
                    v-model="period"
                    size="sm"
                    width="sm"
                    :options="[{ value: '', label: 'Tudo' }, ...periodOptions]"
                    @change="applyFilters"
                />
            </UiField>

            <UiButton v-if="activeFilters" variant="ghost" size="sm" icon="close" @click="clearFilters">
                Limpar
            </UiButton>
        </div>

        <p class="text-xs tabular-nums text-ink-muted" role="status">
            {{ events.total }} {{ events.total === 1 ? 'evento' : 'eventos' }}
            <template v-if="activeFilters || status">com os filtros atuais</template>
        </p>
    </div>

    <UiCard padding="none">
        <UiEmpty
            v-if="!events.data.length"
            :icon="activeFilters || status ? 'search' : 'alert'"
            :message="
                activeFilters || status
                    ? 'Nenhum webhook com esses filtros.'
                    : 'Nenhum webhook recebido ainda.'
            "
        >
            <!-- Sem esta saída, filtrar demais vira beco: a tela fica vazia e
                 não há nada para clicar além de refazer a URL na mão. -->
            <template v-if="activeFilters" #action>
                <UiButton variant="secondary" @click="clearFilters">Limpar filtros</UiButton>
            </template>
        </UiEmpty>

        <UiTable v-else :columns="columns" caption="Webhooks recebidos">
            <template v-for="event in events.data" :key="event.id">
                <tr>
                    <th scope="row" class="font-normal">
                        <span class="block font-medium text-ink">#{{ event.id }}</span>
                        <span class="block text-xs text-ink-muted uppercase">{{ event.source }}</span>
                    </th>

                    <td>
                        <span class="text-sm text-ink" :title="event.topic">{{ webhookTopicLabel(event.topic) }}</span>
                        <span v-if="event.external_event_id" class="block truncate text-xs text-ink-muted">
                            {{ event.external_event_id }}
                        </span>
                    </td>

                    <td>
                        <UiBadge :variant="STATUS[event.status]?.variant ?? 'neutral'" dot>
                            {{ STATUS[event.status]?.label ?? event.status }}
                        </UiBadge>
                    </td>

                    <td>
                        <span class="text-sm tabular-nums text-ink">{{ dateTime(event.created_at) }}</span>
                        <span v-if="event.processed_at" class="block text-xs text-ink-muted">
                            processado {{ dateTime(event.processed_at) }}
                        </span>
                    </td>

                    <td class="text-right whitespace-nowrap">
                        <UiButton size="sm" variant="ghost" @click="toggle(event.id)">
                            {{ expanded === event.id ? 'Fechar' : 'Detalhes' }}
                        </UiButton>
                        <UiButton
                            v-if="event.status !== 'processed'"
                            size="sm"
                            variant="secondary"
                            :loading="reprocessForm.processing"
                            @click="reprocess(event.id)"
                        >
                            Reprocessar
                        </UiButton>
                    </td>
                </tr>

                <tr v-if="expanded === event.id">
                    <td colspan="5" class="bg-surface-muted">
                        <!-- O mesmo campo guarda duas coisas diferentes: a
                             falha de quem quebrou e o MOTIVO de quem foi
                             ignorado de propósito. Chamar os dois de "Erro"
                             mandaria investigar um funcionamento correto. -->
                        <div v-if="event.error" class="mb-3">
                            <p
                                :class="[
                                    'mb-1 flex items-center gap-1.5 text-xs font-semibold',
                                    event.status === 'ignored' ? 'text-ink-muted' : 'text-danger',
                                ]"
                            >
                                <UiIcon :name="event.status === 'ignored' ? 'info' : 'alert'" :size="14" />
                                {{ event.status === 'ignored' ? 'Por que não virou Projeto' : 'Erro' }}
                            </p>
                            <UiCode :value="event.error" />
                        </div>

                        <!-- Estruturado por padrão: achar um campo no JSON cru
                             era caçar texto. O bruto continua a um clique,
                             para copiar e colar em chamado. -->
                        <div class="mb-1 flex items-center justify-between gap-3">
                            <p class="text-xs font-semibold text-ink-muted uppercase">Detalhes recebidos</p>
                            <button
                                type="button"
                                class="text-xs font-medium text-brand-700 underline underline-offset-2"
                                @click="raw = raw === event.id ? null : event.id"
                            >
                                {{ raw === event.id ? 'Ver estruturado' : 'Ver JSON puro' }}
                            </button>
                        </div>

                        <UiCode v-if="raw === event.id" :value="JSON.stringify(event.payload, null, 2)" copyable />
                        <div v-else class="rounded-control border border-line bg-surface p-3">
                            <UiJsonView :value="event.payload" :open-depth="2" />
                        </div>
                    </td>
                </tr>
            </template>
        </UiTable>

        <UiPagination v-if="events.links?.length > 3" :links="events.links" class="border-t border-line p-3" />
    </UiCard>
</template>
