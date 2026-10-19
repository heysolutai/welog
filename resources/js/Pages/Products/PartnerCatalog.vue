<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiBadge from '../../Components/UiBadge.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCode from '../../Components/UiCode.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiPagination from '../../Components/UiPagination.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiSearchInput from '../../Components/UiSearchInput.vue';
import UiSelect from '../../Components/UiSelect.vue';
import UiTable from '../../Components/UiTable.vue';

const props = defineProps({
    skus: { type: Object, required: true },
    filters: { type: Object, default: () => ({ q: '', status: 'pendentes' }) },
    summary: { type: Object, default: () => ({ total: 0, pendentes: 0 }) },
    products: { type: Array, default: () => [] },
    canLink: { type: Boolean, default: false },
});

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

const q = ref(props.filters.q ?? '');

watch(
    () => props.filters.q,
    (entrando) => (q.value = entrando ?? ''),
);

function navegar(extra) {
    router.get(
        route('partner-skus.index'),
        { q: q.value || undefined, status: props.filters.status, ...extra },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const abas = [
    { key: 'pendentes', label: 'Sem vínculo' },
    { key: 'vinculados', label: 'Vinculados' },
    { key: 'todos', label: 'Todos' },
];

/*
 * O vínculo grava NO CLIQUE do seletor, sem botão de confirmar.
 *
 * Quem abre esta tela tem uma lista de pendências para zerar, uma a uma; um
 * "salvar" por linha dobraria os cliques sem decidir nada — a escolha do
 * produto já é a decisão inteira.
 */
const gravando = ref(new Set());

function vincular(sku, produtoId) {
    const pendentes = new Set(gravando.value);
    pendentes.add(sku.id);
    gravando.value = pendentes;

    router.patch(
        route('partner-skus.link', sku.id),
        { product_id: produtoId || null },
        {
            preserveScroll: true,
            onFinish: () => {
                const restantes = new Set(gravando.value);
                restantes.delete(sku.id);
                gravando.value = restantes;
            },
        },
    );
}

const opcoes = computed(() => [
    { value: '', label: 'Sem vínculo' },
    ...props.products.map((p) => ({ value: String(p.id), label: `${p.omie_code} · ${p.name}` })),
]);

const colunas = [
    { key: 'sku', label: 'SKU do parceiro' },
    { key: 'produto', label: 'Produto' },
    { key: 'categoria', label: 'Categoria' },
    { key: 'vinculo', label: 'Kit na Omie' },
];
</script>

<template>
    <Head title="Catálogo do parceiro" />

    <PageHeader
        title="Catálogo do parceiro"
        subtitle="Itens que a Reconhece cadastrou na vitrine e o kit da Omie que cada um expede."
    >
        <template #actions>
            <UiRefreshButton />
            <Link :href="route('products.index')">
                <UiButton variant="ghost" icon="catalog">Produtos da Omie</UiButton>
            </Link>
        </template>
    </PageHeader>

    <!--
        O aviso vem antes da lista porque é o motivo de a tela existir: SKU sem
        vínculo trava o resgate em "produto não cadastrado", e isso só aparece
        com a campanha rodando e o colaborador esperando do outro lado.
    -->
    <UiAlert v-if="summary.pendentes > 0" variant="warning" title="Itens sem vínculo" class="mb-6">
        <p>
            {{ plural(summary.pendentes, 'item ainda não está vinculado', 'itens ainda não estão vinculados') }}
            a um kit da Omie. Resgate de qualquer um deles vai travar.
        </p>
    </UiAlert>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <UiButton
            v-for="aba in abas"
            :key="aba.key"
            :variant="filters.status === aba.key ? 'secondary' : 'ghost'"
            size="sm"
            @click="navegar({ status: aba.key })"
        >
            {{ aba.label }}
            <UiBadge v-if="aba.key === 'pendentes' && summary.pendentes" variant="warning" class="ml-1">
                {{ summary.pendentes }}
            </UiBadge>
        </UiButton>

        <UiSearchInput v-model="q" class="ml-auto w-full sm:w-80" placeholder="SKU, nome ou código do pai" @search="navegar({})" />
    </div>

    <UiEmpty
        v-if="!skus.data.length"
        icon="catalog"
        title="Nada por aqui"
        :description="filters.status === 'pendentes'
            ? 'Todo item do catálogo já está vinculado a um kit da Omie.'
            : 'O catálogo chega pelo webhook da Reconhece.'"
    />

    <UiTable v-else :columns="colunas">
        <tr v-for="sku in skus.data" :key="sku.id">
            <td>
                <div class="flex items-center gap-3">
                    <img
                        v-if="sku.image"
                        :src="sku.image"
                        alt=""
                        class="size-10 shrink-0 rounded-card object-cover"
                        loading="lazy"
                    />
                    <div class="min-w-0">
                        <UiCode>{{ sku.sku }}</UiCode>
                        <!-- Código do pai só quando difere: repetir o mesmo
                             código duas vezes na linha é ruído. -->
                        <p v-if="!sku.avulso" class="mt-0.5 text-sm text-ink-muted">
                            Modelo de <UiCode>{{ sku.parent_code }}</UiCode>
                        </p>
                    </div>
                </div>
            </td>

            <td>
                <p class="font-medium text-ink">{{ sku.name }}</p>
                <p v-if="sku.variacao" class="mt-0.5 text-sm text-ink-muted">{{ sku.variacao }}</p>
                <UiBadge v-if="!sku.enabled" variant="neutral" class="mt-1">Desativado na vitrine</UiBadge>
            </td>

            <td class="text-sm text-ink-muted">
                <template v-if="sku.product_type">
                    {{ sku.product_type }}
                    <span v-if="sku.department"> · {{ sku.department }}</span>
                </template>
                <span v-else class="text-ink-subtle">-</span>
            </td>

            <td>
                <UiSelect
                    v-if="canLink"
                    :model-value="sku.product ? String(sku.product.id) : ''"
                    :options="opcoes"
                    :invalid="!sku.product"
                    :disabled="gravando.has(sku.id)"
                    aria-label="Kit da Omie"
                    @update:model-value="vincular(sku, $event)"
                />

                <template v-else>
                    <span v-if="sku.product" class="text-sm text-ink">
                        {{ sku.product.omie_code }} · {{ sku.product.name }}
                    </span>
                    <UiBadge v-else variant="warning">
                        Sem vínculo
                    </UiBadge>
                </template>
            </td>
        </tr>
    </UiTable>

    <UiPagination v-if="skus.data.length" :links="skus.links" class="mt-6" />
</template>
