<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiBadge from '../../Components/UiBadge.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCode from '../../Components/UiCode.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiField from '../../Components/UiField.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiInput from '../../Components/UiInput.vue';
import UiMenu from '../../Components/UiMenu.vue';
import UiModal from '../../Components/UiModal.vue';
import UiPagination from '../../Components/UiPagination.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiSearchInput from '../../Components/UiSearchInput.vue';
import UiTable from '../../Components/UiTable.vue';
import { useConfirm } from '../../composables/useConfirm';
import { formatDate } from '../../lib/format';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, default: () => ({ q: '' }) },
    summary: { type: Object, default: () => ({ total: 0, kits: 0 }) },
    canImport: { type: Boolean, default: false },
    canPublish: { type: Boolean, default: false },
    sugestoes: { type: Object, default: () => ({ product_types: [], departments: [] }) },
});

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

const quantity = new Intl.NumberFormat('pt-BR');

const q = ref(props.filters.q ?? '');
const searching = computed(() => (props.filters.q ?? '') !== '');

// A página não é remontada a cada busca: sem isto, voltar no histórico deixa
// o campo com o termo antigo.
watch(
    () => props.filters.q,
    (incoming) => (q.value = incoming ?? ''),
);

function search(term) {
    router.get(
        route('products.index'),
        { q: term || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function clearSearch() {
    q.value = '';
    search('');
}

const resultLabel = computed(() =>
    searching.value
        ? `${props.products.total} de ${plural(props.summary.total, 'produto', 'produtos')}`
        : `${plural(props.summary.total, 'produto', 'produtos')}, ${plural(props.summary.kits, 'kit', 'kits')}`,
);

const emptySearchMessage = computed(() => `Nenhum produto para "${props.filters.q}".`);

/**
 * A importação é um job assíncrono: sem sinal de progresso o operador clica de
 * novo e enfileira outro. A lista se atualiza sozinha até o catálogo crescer
 * ou até o tempo que vale a pena esperar acabar.
 */
const POLL_EVERY = 5000;
const POLL_FOR = 60000;

const importForm = useForm({});
const importing = ref(false);

let timer = null;
let deadline = 0;
let baseline = 0;

function stopPolling() {
    clearTimeout(timer);
    timer = null;
    importing.value = false;
}

function reload() {
    router.reload({
        only: ['products', 'summary'],
        onFinish: () => {
            if (!importing.value) return;
            if (Date.now() > deadline) stopPolling();
            else poll();
        },
    });
}

function poll() {
    clearTimeout(timer);
    timer = setTimeout(reload, POLL_EVERY);
}

function refreshNow() {
    clearTimeout(timer);
    reload();
}

// Chegou produto novo: o aviso e o polling perdem a razão de existir.
watch(
    () => props.summary.total,
    (total) => {
        if (importing.value && total !== baseline) stopPolling();
    },
);

function importCatalog() {
    importForm.post(route('products.import'), {
        preserveScroll: true,
        onSuccess: () => {
            importing.value = true;
            baseline = props.summary.total;
            deadline = Date.now() + POLL_FOR;
            poll();
        },
    });
}

onBeforeUnmount(() => clearTimeout(timer));

const importBusy = computed(() => importForm.processing || importing.value);
const importLabel = computed(() => (importing.value ? 'Importando…' : 'Importar da Omie'));

const columns = [
    { key: 'code', label: 'Código' },
    { key: 'name', label: 'Produto' },
    { key: 'type', label: 'Tipo' },
    { key: 'stock', label: 'Estoque', numeric: true },
    { key: 'synced', label: 'Sincronizado' },
    { key: 'actions', label: 'Ações', align: 'end' },
];

const confirm = useConfirm();

/*
 * Variações da grade da Omie (PRD11361_1, _2, _3) como subitens do pai.
 *
 * Recolhidas por padrão: o botão já diz quantas são e quais, e uma lista
 * com todos os tamanhos abertos de todos os produtos esconderia o catálogo.
 */
const abertos = ref(new Set());

function alternar(id) {
    const proximos = new Set(abertos.value);
    proximos.has(id) ? proximos.delete(id) : proximos.add(id);
    abertos.value = proximos;
}

const rotuloDaVariacao = (v) => [v.color, v.size].filter(Boolean).join(' · ') || v.omie_code;

const estoqueDaLinha = (p) =>
    p.grupo_sem_pai ? (p.variacoes ?? []).reduce((total, v) => total + v.stock_qty, 0) : p.stock_qty;

/*
 * O menu de cada linha.
 *
 * Editar é link de verdade (abre em nova aba pelo clique do meio). Publicar
 * fica indisponível sem descrição, com o motivo no próprio item: a Reconhece a
 * exige, e é melhor saber aqui do que num erro depois do diálogo.
 */
function acoes(produto) {
    const itens = [{ label: 'Editar', icon: 'settings', href: route('products.edit', produto.id) }];

    if (props.canPublish) {
        itens.push({
            label: produto.has_description
                ? (produto.reconhece ? 'Atualizar na Reconhece' : 'Enviar para Reconhece')
                : 'Enviar para Reconhece (falta descrição)',
            icon: 'upload',
            disabled: !produto.has_description,
            onSelect: () => abrirPublicacao(produto),
        });

        itens.push({
            label: 'Excluir',
            icon: 'trash',
            danger: true,
            onSelect: () => excluir(produto),
        });
    }

    return itens;
}

async function excluir(produto) {
    await confirm({
        title: `Excluir "${produto.name}"?`,
        description: 'O produto sai da WeLog. Produto em uso por Projeto, autorização ou resgate não pode ser excluído.',
        warning: 'Se ele continuar ativo na Omie, volta na próxima importação.',
        confirmLabel: 'Excluir produto',
        variant: 'danger',
        errorBag: 'product',
        action: () => router.delete(route('products.destroy', produto.id), { preserveScroll: true }),
    });
}

/*
 * Publicação na vitrine da Reconhece.
 *
 * As duas categorias são obrigatórias lá e não existem na Omie: não há de onde
 * preenchê-las sozinho, e adivinhar criaria uma categoria errada no catálogo
 * do parceiro, que é pior do que perguntar. O diálogo abre com o que foi usado
 * da última vez, para republicar não virar um exercício de memória.
 */
const publicacao = ref(null);

const publicacaoForm = useForm({ product_type: '', department: '' });

function abrirPublicacao(produto) {
    publicacao.value = produto;
    publicacaoForm.clearErrors();
    publicacaoForm.product_type = produto.categorias?.product_type ?? '';
    publicacaoForm.department = produto.categorias?.department ?? '';
}

function publicar() {
    publicacaoForm.post(route('products.reconhece.publish', publicacao.value.id), {
        preserveScroll: true,
        onSuccess: () => (publicacao.value = null),
    });
}
</script>

<template>
    <Head title="Produtos" />
    <PageHeader title="Produtos" subtitle="Catálogo espelhado da Omie.">
        <template #actions>
            <UiRefreshButton />
            <UiButton v-if="canImport" variant="info" icon="download" :loading="importBusy" @click="importCatalog">
                {{ importLabel }}
            </UiButton>
        </template>
    </PageHeader>

    <UiAlert v-if="importing" variant="info" class="mb-4">
        Buscando produtos na Omie. A lista se atualiza sozinha nos próximos minutos.

        <template #actions>
            <UiButton variant="secondary" size="sm" @click="refreshNow">Atualizar agora</UiButton>
        </template>
    </UiAlert>

    <div class="mb-4">
        <UiSearchInput
            v-model="q"
            placeholder="Buscar por nome ou código"
            :result-label="resultLabel"
            @search="search"
        />
    </div>

    <UiTable :columns="columns" caption="Catálogo de produtos e kits espelhado da Omie">
        <template v-for="p in products.data" :key="p.id">
            <tr>
                <td><UiCode :value="p.omie_code" /></td>
                <td>
                    <div class="flex items-center gap-2.5">
                        <img
                            v-if="p.photo_url"
                            :src="p.photo_url"
                            alt=""
                            class="size-9 shrink-0 rounded border border-line object-cover"
                        />
                        <span class="min-w-0">
                            <span class="font-medium text-ink">{{ p.name }}</span>
                            <UiBadge v-if="!p.active" variant="neutral" class="ml-2">Inativo</UiBadge>
                            <button
                                v-if="p.variacoes?.length"
                                type="button"
                                class="mt-1 flex min-h-8 items-center gap-1 text-left text-xs font-medium text-brand-700 hover:underline"
                                :aria-expanded="abertos.has(p.id)"
                                @click="alternar(p.id)"
                            >
                                <UiIcon
                                    name="chevron-right"
                                    :size="12"
                                    :class="['shrink-0 transition-transform', abertos.has(p.id) && 'rotate-90']"
                                />
                                {{ plural(p.variacoes.length, 'variação', 'variações') }}
                                · {{ p.variacoes.map(rotuloDaVariacao).join(' · ') }}
                            </button>
                        </span>
                    </div>
                </td>
                <td>
                    <UiBadge v-if="p.is_kit" variant="info">
                        Kit com {{ plural(p.components_count, 'item', 'itens') }}
                    </UiBadge>
                    <span v-else-if="p.grupo_sem_pai" class="text-ink-muted">Grade</span>
                    <span v-else class="text-ink-muted">Produto</span>
                </td>
                <!-- Grade sem pai na Omie: a linha é do grupo, e o estoque é a
                     soma dos tamanhos (cada um mostra o seu ao abrir). -->
                <td :class="estoqueDaLinha(p) > 0 ? 'text-ink' : 'text-ink-muted'">
                    {{ quantity.format(estoqueDaLinha(p)) }}
                </td>
                <td class="text-ink-muted">
                    <template v-if="p.grupo_sem_pai">—</template>
                    <template v-else-if="p.stock_synced_at">{{ formatDate(p.stock_synced_at) }}</template>
                    <template v-else>
                        <span class="text-sm text-ink-muted">Nunca sincronizado</span>
                    </template>
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2">
                        <UiBadge v-if="p.reconhece" variant="success">Na Reconhece</UiBadge>
                        <!-- A linha do grupo não é um produto: as ações ficam em
                             cada tamanho. -->
                        <UiMenu v-if="!p.grupo_sem_pai" :items="acoes(p)" :label="`Ações de ${p.name}`" />
                    </div>
                </td>
            </tr>

            <!-- Subitens: os tamanhos da grade, cada um com o próprio estoque. -->
            <tr v-for="v in abertos.has(p.id) ? p.variacoes : []" :key="`v-${v.id}`" class="bg-surface-muted">
                <td class="pl-8"><UiCode :value="v.omie_code" /></td>
                <td>
                    <div class="flex items-center gap-2 pl-4">
                        <span class="text-ink-subtle" aria-hidden="true">└</span>
                        <span class="font-medium text-ink">{{ rotuloDaVariacao(v) }}</span>
                        <UiBadge v-if="!v.active" variant="neutral">Inativo</UiBadge>
                    </div>
                    <p class="mt-0.5 pl-9 text-xs text-ink-muted">{{ v.name }}</p>
                </td>
                <td><span class="text-ink-muted">Variação</span></td>
                <td :class="v.stock_qty > 0 ? 'text-ink' : 'text-ink-muted'">
                    {{ quantity.format(v.stock_qty) }}
                </td>
                <td class="text-ink-muted">
                    <template v-if="v.stock_synced_at">{{ formatDate(v.stock_synced_at) }}</template>
                    <span v-else class="text-sm text-ink-muted">Nunca sincronizado</span>
                </td>
                <td class="text-right">
                    <UiMenu
                        :items="[{ label: 'Editar', icon: 'settings', href: route('products.edit', v.id) }]"
                        :label="`Ações de ${v.name}`"
                    />
                </td>
            </tr>
        </template>

        <template #empty>
            <UiEmpty v-if="searching" icon="search" :message="emptySearchMessage">
                <template #action>
                    <UiButton variant="secondary" @click="clearSearch">Limpar busca</UiButton>
                </template>
            </UiEmpty>

            <UiEmpty v-else icon="catalog" message="Nenhum produto no catálogo.">
                <template v-if="canImport" #action>
                    <UiButton variant="info" icon="download" :loading="importBusy" @click="importCatalog">
                        {{ importLabel }}
                    </UiButton>
                </template>
            </UiEmpty>
        </template>
    </UiTable>

    <UiPagination
        :links="products.links"
        label="Produtos"
        :total="products.total"
        :per-page="products.per_page"
    />

    <UiModal
        :open="publicacao !== null"
        :title="publicacao ? `Publicar na Reconhece · ${publicacao.name}` : ''"
        @update:open="publicacao = null"
    >
        <p class="mb-5 text-sm text-ink-muted">
            As duas categorias são exigidas pela Reconhece e não existem na Omie. Use exatamente
            os nomes que eles cadastraram, senão a vitrine recusa o envio.
        </p>

        <div class="space-y-4">
            <UiField label="Categoria (nível 1)" :error="publicacaoForm.errors.product_type" required>
                <UiInput
                    v-model="publicacaoForm.product_type"
                    list="lista-categoria"
                    autocomplete="off"
                    placeholder="Casa e Construção"
                    :invalid="Boolean(publicacaoForm.errors.product_type)"
                />
            </UiField>

            <UiField label="Departamento (nível 2)" :error="publicacaoForm.errors.department" required>
                <UiInput
                    v-model="publicacaoForm.department"
                    list="lista-departamento"
                    autocomplete="off"
                    placeholder="Ferramentas"
                    :invalid="Boolean(publicacaoForm.errors.department)"
                />
            </UiField>
        </div>


        <datalist id="lista-categoria">
            <option v-for="c in sugestoes.product_types" :key="c" :value="c" />
        </datalist>
        <datalist id="lista-departamento">
            <option v-for="d in sugestoes.departments" :key="d" :value="d" />
        </datalist>

        <template #footer>
            <UiButton variant="ghost" @click="publicacao = null">Cancelar</UiButton>
            <UiButton :loading="publicacaoForm.processing" @click="publicar">
                {{ publicacao?.reconhece ? 'Atualizar na vitrine' : 'Publicar' }}
            </UiButton>
        </template>
    </UiModal>
</template>
