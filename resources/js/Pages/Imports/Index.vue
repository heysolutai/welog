<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import UiBadge from '../../Components/UiBadge.vue';
import UiCode from '../../Components/UiCode.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiPagination from '../../Components/UiPagination.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiRowLink from '../../Components/UiRowLink.vue';
import UiSearchInput from '../../Components/UiSearchInput.vue';
import UiTable from '../../Components/UiTable.vue';
import UiTabs from '../../Components/UiTabs.vue';
import { formatCpf, formatDate, importRowStatusLabels } from '../../lib/format';

const props = defineProps({
    rows: { type: Object, required: true },
    filtros: { type: Object, required: true },
    contagens: { type: Object, default: () => ({}) },
});

/** Percurso do resgate, na ordem em que avança. */
const ETAPAS = ['pending', 'order_created', 'invoiced', 'dispatched', 'delivered'];
const BLOQUEIOS = ['blocked_structural', 'blocked_product'];

const busca = ref(props.filtros.busca ?? '');
const etapa = ref(props.filtros.etapa ?? 'todos');

const conta = (status) => props.contagens[status] ?? 0;

// Bloqueados logo depois de Todos: é a fila que pede ação de alguém.
const abas = computed(() => [
    { key: 'todos', label: 'Todos', count: Object.values(props.contagens).reduce((total, n) => total + n, 0) },
    { key: 'bloqueados', label: 'Bloqueados', count: BLOQUEIOS.reduce((total, s) => total + conta(s), 0) },
    ...ETAPAS.map((s) => ({ key: s, label: importRowStatusLabels[s], count: conta(s) })),
]);

// A busca vai ao servidor: a lista é paginada, e filtrar só a página visível
// responderia "nada encontrado" para quem está na página 2.
let debounce = null;

function aplicar({ imediato = false } = {}) {
    clearTimeout(debounce);

    const enviar = () => router.get(
        route('imports.index'),
        { busca: busca.value, etapa: etapa.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    imediato ? enviar() : (debounce = setTimeout(enviar, 350));
}

watch(etapa, () => aplicar({ imediato: true }));

const filtrando = computed(() => busca.value.trim() !== '' || etapa.value !== 'todos');

/**
 * O webhook responde na hora e processa na fila: o resgate aparece como
 * Recebido e vira pedido segundos depois. Enquanto houver algum na fila, a
 * lista se atualiza sozinha em vez de pedir F5.
 */
const POLL_MS = 10000;
const naFila = computed(() => props.rows.data.some((r) => r.status === 'pending'));
let timer = null;

watch(
    naFila,
    (aguardando) => {
        clearInterval(timer);
        if (!aguardando) return;

        timer = setInterval(() => router.reload({ only: ['rows', 'contagens'] }), POLL_MS);
    },
    { immediate: true },
);

onUnmounted(() => {
    clearInterval(timer);
    clearTimeout(debounce);
});

const columns = [
    { key: 'resgate', label: 'Destinatário' },
    { key: 'item', label: 'Item' },
    { key: 'pedido', label: 'Pedido Omie' },
    { key: 'situacao', label: 'Situação' },
];

const bloqueado = (r) => BLOQUEIOS.includes(r.status);
</script>

<template>
    <Head title="Importações Parceiros" />

    <PageHeader
        title="Importações Parceiros"
        subtitle="Resgates recebidos da Reconhece, do pedido na Omie à entrega pela Flash."
    >
        <template #actions>
            <UiRefreshButton />
        </template>
    </PageHeader>

    <div class="mb-4 space-y-3">
        <UiSearchInput
            v-model="busca"
            placeholder="Buscar por nome, CPF, item, pedido ou nº do resgate"
            :result-label="filtrando ? `${rows.total} encontrados` : `${rows.total} resgates`"
            @update:model-value="aplicar()"
        />

        <UiTabs v-model="etapa" :tabs="abas" variant="segmented" panel-prefix="resgates" />
    </div>

    <UiTable :columns="columns" caption="Resgates recebidos da Reconhece, com a etapa de cada um">
        <tr v-for="r in rows.data" :key="r.id">
            <th scope="row" class="align-top">
                <UiRowLink :href="route('imports.rows.show', r.id)">{{ r.customer_name }}</UiRowLink>
                <p class="mt-0.5 text-xs text-ink-muted">
                    <span v-if="r.cpf" class="font-mono">{{ formatCpf(r.cpf) || r.cpf }}</span>
                    <span v-if="r.cpf" class="text-ink-subtle" aria-hidden="true"> · </span>
                    <template v-if="r.external_id">resgate {{ r.external_id }} · </template>
                    {{ formatDate(r.created_at, true) }}
                </p>
            </th>

            <td class="align-top">
                <span class="block text-ink">{{ r.product_name || r.product_code }}</span>
                <span class="block text-xs text-ink-muted">
                    <template v-if="r.variacao">{{ r.variacao }} · </template>
                    <span class="font-mono">{{ r.product_code }}</span>
                    <template v-if="r.quantity > 1"> · {{ r.quantity }} un.</template>
                </span>
            </td>

            <td class="align-top">
                <UiCode v-if="r.omie_order_number" :value="r.omie_order_number" copyable />
                <span v-else class="text-sm text-ink-muted">Sem pedido ainda</span>
            </td>

            <td class="align-top">
                <StatusBadge kind="importRow" :status="r.status" />

                <!-- É o texto que decide a ação: legível, não em cinza claro. -->
                <p
                    v-if="bloqueado(r) && r.block_reason"
                    class="mt-1.5 max-w-sm rounded-control bg-danger-soft px-2 py-1 text-xs text-danger"
                >
                    {{ r.block_reason }}
                </p>

                <div v-else-if="r.tracking_code" class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-muted">
                    <span>Flash</span>
                    <UiCode :value="r.tracking_code" truncate="end" copyable />
                </div>

                <UiBadge v-if="r.divergence_pending" variant="warning" class="mt-1.5">
                    cadastro diferente na Omie
                </UiBadge>
            </td>
        </tr>

        <template #empty>
            <UiEmpty
                icon="imports"
                :message="filtrando
                    ? 'Nenhum resgate com esses filtros.'
                    : 'Nenhum resgate recebido ainda. Eles aparecem aqui assim que a Reconhece envia pelo webhook.'"
            />
        </template>
    </UiTable>

    <UiPagination :links="rows.links" label="Resgates" />
</template>
