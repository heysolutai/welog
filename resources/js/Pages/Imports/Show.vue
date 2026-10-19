<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import ResgateTimeline from '../../Components/ResgateTimeline.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiBadge from '../../Components/UiBadge.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCard from '../../Components/UiCard.vue';
import UiCode from '../../Components/UiCode.vue';
import UiDescriptionList from '../../Components/UiDescriptionList.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiTimeline from '../../Components/UiTimeline.vue';
import { useRowAction } from '../../composables/useRowAction';
import { formatCep, formatCpf, formatDate, formatPhone } from '../../lib/format';

const props = defineProps({
    resgate: { type: Object, required: true },
    permissions: { type: Object, required: true },
});

const r = computed(() => props.resgate);

const bloqueado = computed(() => r.value.status.startsWith('blocked_'));
const divergenciaPendente = computed(() => r.value.divergences.length > 0 && !r.value.divergence_resolved);

/**
 * Datas de cada etapa da esteira. O transporte começa no PRIMEIRO evento da
 * Flash — os eventos chegam do mais recente para o mais antigo.
 */
const datas = computed(() => ({
    received: r.value.created_at,
    invoice: r.value.nf_issued_at,
    transport: r.value.events.at(-1)?.occurred_at ?? null,
    delivered: r.value.delivered_at,
}));

const titulo = computed(() => r.value.customer_name || `Resgate ${r.value.external_id ?? r.value.id}`);

const endereco = computed(() => {
    const { street, number, complement, district, city, state, zip } = r.value;
    const linha1 = [street, number].filter(Boolean).join(', ');
    const linha2 = [complement, district].filter(Boolean).join(' · ');
    const linha3 = [[city, state].filter(Boolean).join('/'), formatCep(zip) || zip].filter(Boolean).join(' · ');

    return [linha1, linha2, linha3].filter(Boolean);
});

const destinatario = computed(() => [
    { key: 'cpf', label: 'CPF', value: formatCpf(r.value.cpf) || r.value.cpf, mono: true },
    { key: 'email', label: 'E-mail', value: r.value.email },
    { key: 'phone', label: 'Telefone', value: formatPhone(r.value.phone) || r.value.phone },
    { key: 'omie', label: 'Cliente na Omie', value: r.value.omie_customer_code, mono: true },
]);

const item = computed(() => [
    { key: 'code', label: 'Código', value: r.value.product_code, mono: true },
    { key: 'variacao', label: 'Variação', value: r.value.variacao },
    { key: 'qty', label: 'Quantidade', value: r.value.quantity },
    { key: 'benefit', label: 'Benefício fiscal', value: r.value.benefit_code, mono: true },
]);

/** O que a esteira não diz: por que a etapa seguinte ainda não chegou. */
const aguardando = computed(() => ({
    pending: 'Na fila para virar pedido na Omie. Leva alguns segundos.',
    order_created: 'Pedido criado. Aguardando o faturamento da NF na Omie (conferido de hora em hora).',
    invoiced: r.value.flash_sent_at
        ? 'NF emitida e encomenda enviada à Flash. Aguardando a coleta: o rastreio é consultado a cada 2 horas.'
        : 'NF emitida. A encomenda vai para a Flash em instantes.',
    dispatched: 'Com a Flash, a caminho do destinatário.',
}[r.value.status] ?? null));

const { run, pending, error, clearError } = useRowAction();

function reprocessar() {
    clearError('reprocess');
    run('reprocess', route('imports.rows.reprocess', r.value.id), { errorKey: 'row' });
}

function buscarNota() {
    clearError('nf');
    run('nf', route('imports.rows.invoice', r.value.id), { errorKey: 'nf' });
}

function enviarFlash() {
    clearError('flash');
    run('flash', route('imports.rows.flash', r.value.id), { errorKey: 'flash' });
}

// Copiar o JSON da Flash para mandar ao suporte da Bridge Link.
const jsonCopiado = ref(false);

async function copiarJson() {
    try {
        await navigator.clipboard.writeText(r.value.flash_json);
        jsonCopiado.value = true;
        setTimeout(() => (jsonCopiado.value = false), 2000);
    } catch {
        // Área de transferência bloqueada: o texto está na tela para selecionar à mão.
    }
}

function resolverDivergencia(resolucao) {
    clearError('divergence');
    run('divergence', route('imports.rows.divergence', r.value.id), {
        data: { resolucao },
        errorKey: 'divergence',
    });
}
</script>

<template>
    <Head :title="titulo" />

    <PageHeader
        :title="titulo"
        :subtitle="r.external_id ? `Resgate ${r.external_id} na Reconhece` : null"
        :back="{ label: 'Importações Parceiros', href: route('imports.index') }"
    >
        <template #actions>
            <StatusBadge kind="importRow" :status="r.status" />
            <UiRefreshButton />
        </template>
    </PageHeader>

    <!-- A esteira do resgate: a mesma figura da página de Projeto. -->
    <div class="overflow-hidden rounded-card bg-surface shadow-card px-4 pt-6 pb-4">
        <ResgateTimeline :status="r.status" :dates="datas" />
        <p v-if="aguardando" class="mt-3 text-center text-sm text-ink-muted">{{ aguardando }}</p>
    </div>

    <!-- Bloqueio: o motivo decide a ação, então ele vem antes de tudo. -->
    <UiAlert
        v-if="bloqueado"
        class="mt-4"
        variant="danger"
        :title="r.status === 'blocked_product' ? 'Pedido não criado: produto sem correspondência na Omie' : 'Resgate com dados incompletos'"
    >
        <p v-if="r.block_reason">{{ r.block_reason }}</p>
        <p v-if="r.status === 'blocked_structural'" class="mt-1">
            Corrija o cadastro na Reconhece e peça o reenvio do resgate.
        </p>
        <p v-else-if="!permissions.reprocess" class="mt-1">Só o perfil N1 reprocessa o resgate.</p>

        <template v-if="r.status === 'blocked_product'" #actions>
            <UiButton
                v-if="permissions.reprocess"
                size="sm"
                icon="refresh"
                :loading="pending('reprocess')"
                @click="reprocessar"
            >
                Reprocessar
            </UiButton>
            <UiButton variant="secondary" size="sm" :href="route('partner-skus.index')">
                Catálogo do Parceiro
            </UiButton>
        </template>
    </UiAlert>

    <UiAlert
        v-else-if="r.status === 'pending' && (r.erro || permissions.reprocess)"
        class="mt-4"
        variant="warning"
        title="Ainda sem pedido na Omie"
    >
        <!-- Parado sem pedido: a Omie recusou no meio e a fila tenta de novo
             sozinha por alguns minutos. O botão é para não depender dela. -->
        <p v-if="r.erro">
            Última recusa da Omie<template v-if="r.erro_em"> ({{ formatDate(r.erro_em, true) }})</template>:
            {{ r.erro }}
        </p>
        <p class="mt-1">
            Clique em Reprocessar uma vez e aguarde. Clicar de novo em menos de 1 minuto faz a Omie recusar
            por repetição.
        </p>

        <template v-if="permissions.reprocess" #actions>
            <UiButton size="sm" icon="refresh" :loading="pending('reprocess')" @click="reprocessar">
                Reprocessar
            </UiButton>
        </template>
    </UiAlert>

    <UiAlert v-if="error('reprocess')" class="mt-3" variant="danger">{{ error('reprocess') }}</UiAlert>

    <!-- Divergência não bloqueia: o pedido seguiu. Âmbar, não vermelho. -->
    <UiAlert
        v-if="divergenciaPendente"
        class="mt-4"
        variant="warning"
        :title="`${r.divergences.length} campo(s) diferentes do cadastro na Omie`"
    >
        <ul class="space-y-0.5 text-sm">
            <li v-for="d in r.divergences" :key="d.campo">
                <span class="text-ink-muted">{{ d.rotulo }}:</span>
                resgate <span class="font-medium">{{ d.na_planilha }}</span>
                <span class="text-ink-subtle">·</span>
                Omie <span class="font-medium">{{ d.na_omie }}</span>
            </li>
        </ul>
        <p v-if="error('divergence')" class="mt-2 font-medium text-danger">{{ error('divergence') }}</p>

        <template v-if="permissions.resolve_divergence" #actions>
            <UiButton size="sm" variant="secondary" :loading="pending('divergence')" @click="resolverDivergencia('usar_planilha')">
                Atualizar Omie
            </UiButton>
            <UiButton size="sm" variant="ghost" :loading="pending('divergence')" @click="resolverDivergencia('manter_omie')">
                Manter cadastro
            </UiButton>
        </template>
    </UiAlert>

    <!-- Faixa de identidade: os números que se procuram em outro sistema. -->
    <div class="mt-4 flex flex-wrap rounded-card bg-surface shadow-card">
        <div class="flex min-w-[13rem] flex-1 items-center gap-3 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-surface-muted text-ink-muted">
                <UiIcon name="fields" :size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">Pedido Omie</p>
                <UiCode v-if="r.omie_order_number" :value="r.omie_order_number" copyable />
                <p v-else class="text-sm text-ink-muted">Sem pedido ainda</p>
                <p v-if="r.omie_order_id" class="mt-0.5 text-xs text-ink-muted">
                    ID interno <span class="font-mono">{{ r.omie_order_id }}</span>
                </p>
            </div>
        </div>

        <div class="flex min-w-[13rem] flex-1 items-center gap-3 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-surface-muted text-ink-muted">
                <UiIcon name="info" :size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">
                    Nota fiscal<template v-if="r.nf_number && !r.nf_simulated"> nº {{ r.nf_number }}</template>
                </p>
                <template v-if="r.nf_key">
                    <UiCode :value="r.nf_key" truncate="start" copyable />
                    <!-- Nota de teste (welog:nf:simular): não existe na Omie. -->
                    <UiBadge v-if="r.nf_simulated" variant="warning" class="ml-1.5">NF simulada</UiBadge>
                </template>
                <p v-else class="text-sm text-ink-muted">Não emitida</p>

                <!-- Sem esperar o webhook nem a varredura: pergunta à Omie
                     pela nota do pedido deste resgate. -->
                <UiButton
                    v-if="permissions.fetch_invoice && (!r.nf_key || r.nf_simulated)"
                    class="mt-2"
                    size="sm"
                    variant="secondary"
                    icon="search"
                    :loading="pending('nf')"
                    @click="buscarNota"
                >
                    Buscar NF na Omie
                </UiButton>
                <p v-if="error('nf')" class="mt-1 text-xs text-danger">{{ error('nf') }}</p>
            </div>
        </div>

        <div class="flex min-w-[13rem] flex-1 items-center gap-3 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-surface-muted text-ink-muted">
                <UiIcon name="truck" :size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">Rastreio Flash</p>
                <UiCode v-if="r.tracking_code" :value="r.tracking_code" copyable />
                <p v-else class="text-sm text-ink-muted">Sem rastreio ainda</p>
                <!-- O número pelo qual a WeLog consulta a Flash (numEncCli). -->
                <p v-if="r.flash_reference" class="mt-0.5 text-xs text-ink-muted">
                    encomenda <span class="font-mono">{{ r.flash_reference }}</span>
                </p>
            </div>
        </div>

        <div class="flex min-w-[13rem] flex-1 items-center gap-3 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-surface-muted text-ink-muted">
                <UiIcon name="calendar" :size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">Recebido em</p>
                <p class="text-sm font-medium text-ink tabular-nums">{{ formatDate(r.created_at, true) }}</p>
            </div>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <UiCard title="Destinatário">
            <p class="text-base font-semibold text-ink">{{ r.customer_name }}</p>
            <UiBadge v-if="r.customer_auto_created" variant="info" class="mt-1">
                cadastrado automaticamente na Omie
            </UiBadge>

            <address class="mt-3 text-sm not-italic text-ink">
                <span v-for="(linha, i) in endereco" :key="i" class="block">{{ linha }}</span>
            </address>

            <UiDescriptionList class="mt-4" layout="grid" :columns="2" :items="destinatario" />
        </UiCard>

        <UiCard title="Item escolhido na Reconhece">
            <div class="flex items-start gap-3">
                <img
                    v-if="r.product_image"
                    :src="r.product_image"
                    :alt="r.product_name || r.product_code"
                    class="size-16 shrink-0 rounded-control border border-line object-cover"
                >
                <div class="min-w-0">
                    <p class="text-base font-semibold text-ink">{{ r.product_name || r.product_code }}</p>
                    <p v-if="r.variacao" class="text-sm text-ink-muted">{{ r.variacao }}</p>
                </div>
            </div>

            <UiDescriptionList class="mt-4" layout="grid" :columns="2" :items="item" />
        </UiCard>
    </div>

    <UiCard class="mt-4" title="Rastreio na Flash">
        <!-- Envio da encomenda: automático quando a nota chega; o botão é
             para o que a Flash recusou ou para reenviar (é seguro). -->
        <div v-if="r.nf_key" class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 text-sm">
                <p v-if="r.flash_sent_at" class="text-ink">
                    Encomenda <span class="font-mono">{{ r.flash_reference }}</span> enviada à Flash em
                    {{ formatDate(r.flash_sent_at, true) }}.
                </p>
                <p v-else class="text-ink-muted">Encomenda ainda não enviada à Flash.</p>
                <p v-if="r.flash_error" class="mt-1 rounded-control bg-danger-soft px-2 py-1 text-danger">
                    Última recusa: {{ r.flash_error }}
                </p>
                <p v-if="error('flash')" class="mt-1 text-danger">{{ error('flash') }}</p>
            </div>

            <UiButton
                v-if="permissions.send_to_flash"
                size="sm"
                :variant="r.flash_sent_at ? 'ghost' : 'secondary'"
                icon="truck"
                :loading="pending('flash')"
                @click="enviarFlash"
            >
                {{ r.flash_sent_at ? 'Reenviar à Flash' : 'Enviar à Flash' }}
            </UiButton>
        </div>

        <!-- O JSON que vai para a Flash, exatamente com estes dados: é o que o
             suporte da Bridge Link pede quando a importação recusa. -->
        <details v-if="r.flash_json" class="mb-4 rounded-control border border-line">
            <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-ink select-none">
                Ver o JSON enviado à Flash
            </summary>
            <div class="border-t border-line p-3">
                <div class="mb-2 flex justify-end">
                    <UiButton size="sm" variant="ghost" icon="copy" @click="copiarJson">
                        {{ jsonCopiado ? 'Copiado' : 'Copiar' }}
                    </UiButton>
                </div>
                <pre class="max-h-96 overflow-auto rounded-control bg-surface-muted p-3 font-mono text-xs leading-relaxed text-ink">{{ r.flash_json }}</pre>
            </div>
        </details>

        <UiTimeline v-if="r.events.length" :events="r.events" />
        <p v-else class="text-sm text-ink-muted">
            {{ r.nf_key
                ? 'A Flash ainda não registrou a coleta desta encomenda.'
                : 'O rastreio começa depois que a NF é emitida.' }}
        </p>
    </UiCard>

    <p class="mt-4 text-xs text-ink-muted">
        <template v-if="r.lote">Chegou no lote {{ r.lote }}</template>
        <template v-if="r.lote && r.external_id"> · </template>
        <template v-if="r.external_id">ID do resgate na Reconhece: <span class="font-mono">{{ r.external_id }}</span></template>
    </p>
</template>
