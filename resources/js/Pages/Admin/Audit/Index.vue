<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import UiBadge from '../../../Components/UiBadge.vue';
import UiButton from '../../../Components/UiButton.vue';
import UiCard from '../../../Components/UiCard.vue';
import UiEmpty from '../../../Components/UiEmpty.vue';
import UiField from '../../../Components/UiField.vue';
import UiIcon from '../../../Components/UiIcon.vue';
import UiJsonView from '../../../Components/UiJsonView.vue';
import UiPagination from '../../../Components/UiPagination.vue';
import UiRefreshButton from '../../../Components/UiRefreshButton.vue';
import UiSearchInput from '../../../Components/UiSearchInput.vue';
import UiSelect from '../../../Components/UiSelect.vue';
import UiTable from '../../../Components/UiTable.vue';
import UiTabs from '../../../Components/UiTabs.vue';
import { formatDate } from '../../../lib/format';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    groups: { type: Object, required: true },
    periods: { type: Object, default: () => ({}) },
    counts: { type: Object, required: true },
    retentionDays: { type: Number, required: true },
});

const group = ref(props.filters.group ?? '');
const period = ref(props.filters.period ?? '');
const q = ref(props.filters.q ?? '');

// `preserveState` não remonta a página: sem isto, voltar no histórico deixaria
// os campos mostrando a busca anterior.
watch(
    () => props.filters,
    (novos) => {
        group.value = novos.group ?? '';
        period.value = novos.period ?? '';
        q.value = novos.q ?? '';
    },
);

const tabs = computed(() => [
    { key: '', label: 'Tudo', count: props.counts.total },
    ...Object.entries(props.groups).map(([key, label]) => ({ key, label, count: props.counts[key] ?? 0 })),
]);

const periodOptions = computed(() => [
    { value: '', label: 'Tudo' },
    ...Object.entries(props.periods).map(([value, label]) => ({ value, label })),
]);

const activeFilters = computed(() => [period.value, q.value].filter(Boolean).length);

const parametros = computed(() => ({
    group: group.value || undefined,
    period: period.value || undefined,
    q: q.value.trim() || undefined,
}));

function applyFilters() {
    router.get(route('admin.audit.index'), parametros.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    period.value = '';
    q.value = '';
    applyFilters();
}

/*
 * Cor por gravidade, não por grupo: o olho precisa achar a tentativa de login
 * falha e a exclusão no meio de centenas de entradas comuns.
 */
function variantOf(action) {
    if (action === 'auth.login_failed' || action.endsWith('.deleted')) return 'danger';
    if (action.startsWith('export.') || action === 'user.updated' || action === 'user.deactivated') return 'warning';
    if (action.startsWith('auth.')) return 'neutral';
    return 'info';
}

const ROLE_LABELS = {
    admin_wegift: 'Admin WeGift',
    wegift_n1: 'Operador N1',
    wegift_n2: 'Operador N2',
    admin_client: 'Admin Cliente',
    project_admin: 'Admin Projeto',
    end_customer: 'Usuário',
};

const columns = [
    { key: 'when', label: 'Quando' },
    { key: 'who', label: 'Quem' },
    { key: 'what', label: 'O quê' },
    { key: 'actions', label: 'Ações', srOnlyLabel: true },
];

const expanded = ref(null);
const toggle = (id) => (expanded.value = expanded.value === id ? null : id);

const dateTime = (iso) => formatDate(iso, true);

// O mesmo desenho do botão secundário (UiButton): pílula com fio claro.
const downloadClasses = 'h-control-sm inline-flex items-center justify-center gap-1.5 rounded-full '
    + 'border border-line bg-surface px-3 text-sm font-medium whitespace-nowrap text-ink '
    + 'transition-colors select-none hover:bg-surface-muted';
</script>

<template>
    <Head title="Auditoria" />

    <PageHeader
        title="Auditoria"
        :subtitle="`Quem fez o quê na plataforma: acessos, permissões, dados pessoais e exportações. Guardado por ${Math.round(retentionDays / 30)} meses; nenhum registro pode ser alterado ou apagado.`"
    >
        <template #actions>
            <!-- Download de verdade, não visita Inertia: <a> simples. -->
            <a :href="route('admin.audit.export', parametros)" :class="downloadClasses">
                <UiIcon name="download" />
                Exportar (.csv)
            </a>
            <UiRefreshButton />
        </template>
    </PageHeader>

    <div class="mb-3 space-y-3">
        <UiTabs v-model="group" :tabs="tabs" variant="segmented" panel-prefix="audit" @update:model-value="applyFilters" />

        <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
            <div class="min-w-56 flex-1" role="search">
                <UiSearchInput v-model="q" placeholder="Nome, e-mail, IP ou descrição" @search="applyFilters" />
            </div>

            <UiField label="Período">
                <UiSelect v-model="period" size="sm" width="sm" :options="periodOptions" @change="applyFilters" />
            </UiField>

            <UiButton v-if="activeFilters" variant="ghost" size="sm" icon="close" @click="clearFilters">
                Limpar
            </UiButton>
        </div>

        <p class="text-xs tabular-nums text-ink-muted" role="status">
            {{ logs.total }} {{ logs.total === 1 ? 'registro' : 'registros' }}
            <template v-if="activeFilters || group">com os filtros atuais</template>
        </p>
    </div>

    <UiCard padding="none">
        <UiEmpty
            v-if="!logs.data.length"
            :icon="activeFilters || group ? 'search' : 'audit'"
            :message="activeFilters || group ? 'Nenhum registro com esses filtros.' : 'Nenhum registro ainda.'"
        >
            <template v-if="activeFilters" #action>
                <UiButton variant="secondary" @click="clearFilters">Limpar filtros</UiButton>
            </template>
        </UiEmpty>

        <UiTable v-else :columns="columns" caption="Trilha de auditoria">
            <template v-for="log in logs.data" :key="log.id">
                <tr>
                    <td class="whitespace-nowrap">
                        <span class="text-sm tabular-nums text-ink">{{ dateTime(log.created_at) }}</span>
                        <span v-if="log.ip" class="block font-mono text-xs text-ink-muted">{{ log.ip }}</span>
                    </td>

                    <th scope="row" class="font-normal">
                        <span class="block text-sm font-medium text-ink">{{ log.user_name ?? 'Sistema' }}</span>
                        <span v-if="log.user_email" class="block text-xs text-ink-muted">{{ log.user_email }}</span>
                        <span v-if="log.user_role" class="block text-xs text-ink-muted">
                            {{ ROLE_LABELS[log.user_role] ?? log.user_role }}
                        </span>
                    </th>

                    <td>
                        <UiBadge :variant="variantOf(log.action)" class="mb-1">
                            {{ groups[log.group] ?? log.group }}
                        </UiBadge>
                        <span class="block text-sm text-ink">{{ log.description }}</span>
                    </td>

                    <td class="text-right">
                        <UiButton
                            v-if="log.properties || log.subject || log.user_agent"
                            size="sm"
                            variant="ghost"
                            @click="toggle(log.id)"
                        >
                            {{ expanded === log.id ? 'Fechar' : 'Detalhes' }}
                        </UiButton>
                    </td>
                </tr>

                <tr v-if="expanded === log.id">
                    <td colspan="4" class="bg-surface-muted">
                        <dl class="mb-2 grid gap-x-6 gap-y-1 text-xs sm:grid-cols-[auto_1fr]">
                            <dt class="font-semibold text-ink-muted">Ação</dt>
                            <dd class="font-mono text-ink">{{ log.action }}</dd>
                            <template v-if="log.subject">
                                <dt class="font-semibold text-ink-muted">Alvo</dt>
                                <dd class="font-mono text-ink">{{ log.subject }}</dd>
                            </template>
                            <template v-if="log.user_agent">
                                <dt class="font-semibold text-ink-muted">Navegador</dt>
                                <dd class="break-all text-ink">{{ log.user_agent }}</dd>
                            </template>
                        </dl>

                        <div v-if="log.properties" class="rounded-control border border-line bg-surface p-3">
                            <UiJsonView :value="log.properties" :open-depth="2" />
                        </div>
                    </td>
                </tr>
            </template>
        </UiTable>

        <UiPagination v-if="logs.links?.length > 3" :links="logs.links" class="border-t border-line p-3" />
    </UiCard>
</template>
