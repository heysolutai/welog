<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCode from '../../Components/UiCode.vue';
import UiDrawer from '../../Components/UiDrawer.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiField from '../../Components/UiField.vue';
import UiInput from '../../Components/UiInput.vue';
import UiMenu from '../../Components/UiMenu.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiSearchInput from '../../Components/UiSearchInput.vue';
import UiTable from '../../Components/UiTable.vue';
import { useConfirm } from '../../composables/useConfirm';
import { useRowAction } from '../../composables/useRowAction';

const props = defineProps({
    benefits: { type: Array, default: () => [] },
});

const search = ref('');

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.benefits;

    return props.benefits.filter((benefit) =>
        `${benefit.product_code} ${benefit.benefit_code} ${benefit.description ?? ''}`
            .toLowerCase()
            .includes(term),
    );
});

const resultLabel = computed(() =>
    search.value.trim()
        ? `mostrando ${visible.value.length} de ${props.benefits.length}`
        : `${props.benefits.length} ${props.benefits.length === 1 ? 'regra' : 'regras'}`,
);

const emptyMessage = computed(() => `Nenhuma regra para "${search.value.trim()}".`);

const columns = [
    { key: 'product', label: 'Produto na Omie' },
    { key: 'benefit', label: 'Benefício' },
    { key: 'description', label: 'Descrição' },
    { key: 'actions', label: 'Ações', srOnlyLabel: true, align: 'end', width: '5rem' },
];

const drawerOpen = ref(false);
const editing = ref(null);
const form = useForm({ product_code: '', benefit_code: '', description: '' });

/** `defaults` → `reset` → `clearErrors`: o erro de uma regra não vaza para a próxima. */
function load(values) {
    form.defaults(values);
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}

function openCreate() {
    editing.value = null;
    load({ product_code: '', benefit_code: '', description: '' });
}

function openEdit(benefit) {
    editing.value = benefit;
    load({
        product_code: benefit.product_code,
        benefit_code: benefit.benefit_code,
        description: benefit.description ?? '',
    });
}

/**
 * O endpoint grava por `product_code` (updateOrCreate): criar com um código que
 * já existe sobrescreve a regra atual em silêncio. Aviso antes do clique, com
 * o array que já está nas props.
 */
const overwriting = computed(() => {
    if (editing.value) return null;

    const code = form.product_code.trim().toLowerCase();
    if (!code) return null;

    return props.benefits.find((benefit) => String(benefit.product_code).toLowerCase() === code) ?? null;
});

const submitLabel = computed(() => (overwriting.value ? 'Substituir regra' : 'Salvar regra'));

function submit() {
    form.post(route('benefits.store'), {
        preserveScroll: true,
        onSuccess: () => (drawerOpen.value = false),
    });
}

const confirm = useConfirm();
const rowAction = useRowAction();

async function remove(benefit) {
    const ok = await confirm({
        title: `Remover a regra do produto ${benefit.product_code}?`,
        description: `A partir de agora, os pedidos criados na Omie para ${benefit.product_code} vão sem o benefício ${benefit.benefit_code}. As importações já processadas não mudam.`,
        confirmLabel: 'Remover regra',
        variant: 'danger',
    });

    if (!ok) return;

    rowAction.run(benefit.id, route('benefits.destroy', benefit.id), { method: 'delete' });
}

function menuItems(benefit) {
    return [
        { label: 'Editar regra', onSelect: () => openEdit(benefit) },
        {
            label: 'Remover regra',
            danger: true,
            disabled: rowAction.pending(benefit.id),
            onSelect: () => remove(benefit),
        },
    ];
}
</script>

<template>
    <Head title="Benefícios Fiscais" />

    <PageHeader
        title="Benefícios Fiscais"
        subtitle="Cada código de produto recebe um código de benefício quando o pedido é criado na Omie (Importações Parceiros)."
    >
        <template #actions>
            <UiRefreshButton />
            <UiButton icon="plus" @click="openCreate">Nova regra</UiButton>
        </template>
    </PageHeader>

    <UiSearchInput
        v-if="benefits.length"
        v-model="search"
        placeholder="código de produto, benefício ou descrição"
        :result-label="resultLabel"
        class="mb-4"
    />

    <UiTable
        :columns="columns"
        caption="Regras de benefício fiscal por código de produto"
        :max-rows="12"
    >
        <tr v-for="benefit in visible" :key="benefit.id">
            <td><UiCode :value="benefit.product_code" /></td>
            <td><UiCode :value="benefit.benefit_code" /></td>
            <td>
                <template v-if="benefit.description">{{ benefit.description }}</template>
                <template v-else>
                    <span aria-hidden="true" class="text-ink-subtle">-</span>
                    <span class="sr-only">Sem descrição</span>
                </template>
            </td>
            <td>
                <UiMenu :label="`Ações da regra ${benefit.product_code}`" :items="menuItems(benefit)" />
            </td>
        </tr>

        <template #empty>
            <UiEmpty v-if="search.trim()" icon="benefits" :message="emptyMessage" />
            <UiEmpty
                v-else
                icon="benefits"
                title="Nenhuma regra cadastrada"
                message="Sem regra, os pedidos das Importações Parceiros são criados na Omie sem benefício fiscal."
            >
                <template #action>
                    <UiButton icon="plus" @click="openCreate">Nova regra</UiButton>
                </template>
            </UiEmpty>
        </template>
    </UiTable>

    <UiDrawer
        v-model:open="drawerOpen"
        :title="editing ? `Regra do produto ${editing.product_code}` : 'Nova regra'"
        description="Vale para os pedidos criados na Omie a partir de agora."
    >
        <form id="form-regra" class="space-y-4" @submit.prevent="submit">
            <UiField
                label="Código do produto na Omie"
                :error="form.errors.product_code"
                :hint="editing ? 'O código identifica a regra e não pode ser alterado.' : null"
                required
            >
                <!-- Na edição o código é a chave do registro: continua legível e
                     copiável, mas não editável. -->
                <UiInput
                    v-model="form.product_code"
                    :readonly="!!editing"
                    :data-autofocus="editing ? null : true"
                    class="read-only:bg-surface-muted read-only:text-ink-muted"
                />
            </UiField>

            <UiField label="Código do benefício" :error="form.errors.benefit_code" required>
                <UiInput v-model="form.benefit_code" :data-autofocus="editing ? true : null" />
            </UiField>

            <UiField label="Descrição" :error="form.errors.description">
                <UiInput v-model="form.description" />
            </UiField>

            <UiAlert v-if="overwriting" variant="warning">
                Já existe uma regra para {{ overwriting.product_code }} (benefício
                {{ overwriting.benefit_code }}). Salvar substitui a regra atual.
            </UiAlert>
        </form>

        <template #footer>
            <UiButton type="submit" form="form-regra" :loading="form.processing">
                {{ submitLabel }}
            </UiButton>
            <UiButton variant="ghost" @click="drawerOpen = false">Cancelar</UiButton>
        </template>
    </UiDrawer>
</template>
