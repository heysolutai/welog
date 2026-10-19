<script setup>
/**
 * Transportadoras — a tabela de parametrização da expedição.
 *
 * O que se decide aqui, e que não existia antes, é COMO cada transportadora
 * entrega o rastreio. Umas dão um código, e a WeLog monta o link com o molde
 * cadastrado; outras dão o link pronto, e aí não há código nenhum para
 * digitar. O drawer de expedição lê essa escolha e pede o campo certo — antes
 * o campo era sempre "Código de rastreio", e quem tinha link colava a URL
 * dentro dele, que ia para o e-mail do presenteado escrita como código.
 */
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import UiAlert from '../../../Components/UiAlert.vue';
import UiBadge from '../../../Components/UiBadge.vue';
import UiButton from '../../../Components/UiButton.vue';
import UiCheckbox from '../../../Components/UiCheckbox.vue';
import UiDrawer from '../../../Components/UiDrawer.vue';
import UiEmpty from '../../../Components/UiEmpty.vue';
import UiField from '../../../Components/UiField.vue';
import UiHintCard from '../../../Components/UiHintCard.vue';
import UiInput from '../../../Components/UiInput.vue';
import UiMenu from '../../../Components/UiMenu.vue';
import UiRefreshButton from '../../../Components/UiRefreshButton.vue';
import UiSelect from '../../../Components/UiSelect.vue';
import UiTable from '../../../Components/UiTable.vue';
import { useConfirm } from '../../../composables/useConfirm';
import { useRowAction } from '../../../composables/useRowAction';

const props = defineProps({
    carriers: { type: Array, default: () => [] },
});

const camposDeRastreio = [
    { value: 'code', label: 'Código de rastreio' },
    { value: 'link', label: 'Link de rastreio' },
];

const columns = [
    { key: 'name', label: 'Transportadora' },
    { key: 'field', label: 'Rastreio' },
    { key: 'shipments', label: 'Expedições', align: 'end', width: '7rem' },
    { key: 'status', label: 'Situação', width: '8rem' },
    { key: 'actions', label: 'Ações', srOnlyLabel: true, align: 'end', width: '5rem' },
];

const drawerOpen = ref(false);
const editing = ref(null);

const vazio = () => ({ name: '', tracking_field: 'code', tracking_url_template: '', active: true });

const form = useForm(vazio());

/** `defaults` → `reset` → `clearErrors`: o erro de uma não vaza para a próxima. */
function load(values) {
    form.defaults(values);
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}

function openCreate() {
    editing.value = null;
    load(vazio());
}

function openEdit(carrier) {
    editing.value = carrier;
    load({
        name: carrier.name,
        tracking_field: carrier.tracking_field,
        tracking_url_template: carrier.tracking_url_template ?? '',
        active: carrier.active,
    });
}

const usaLink = computed(() => form.tracking_field === 'link');

function submit() {
    const opcoes = {
        preserveScroll: true,
        onSuccess: () => (drawerOpen.value = false),
    };

    if (editing.value) {
        form.put(route('admin.carriers.update', editing.value.id), opcoes);

        return;
    }

    form.post(route('admin.carriers.store'), opcoes);
}

const confirm = useConfirm();
const rowAction = useRowAction();

async function remove(carrier) {
    const ok = await confirm({
        title: `Remover a transportadora ${carrier.name}?`,
        description:
            'Ela deixa de aparecer na expedição. Se já tiver expedido algum resgate, o servidor recusa a remoção. Nesse caso, desative-a.',
        confirmLabel: 'Remover',
        variant: 'danger',
    });

    if (!ok) return;

    rowAction.run(carrier.id, route('admin.carriers.destroy', carrier.id), {
        method: 'delete',
        errorKey: 'carrier',
    });
}

function menuItems(carrier) {
    return [
        { label: 'Editar', onSelect: () => openEdit(carrier) },
        {
            label: 'Remover',
            danger: true,
            // Integrada ou já usada: o servidor recusa, e desabilitar aqui
            // evita oferecer uma ação que só existe para falhar.
            disabled: carrier.integrated || carrier.shipments > 0 || rowAction.pending(carrier.id),
            onSelect: () => remove(carrier),
        },
    ];
}

const rotuloDoCampo = (carrier) =>
    camposDeRastreio.find((campo) => campo.value === carrier.tracking_field)?.label ?? carrier.tracking_field;
</script>

<template>
    <Head title="Transportadoras" />

    <PageHeader
        title="Transportadoras"
        subtitle="Quem entrega os kits e como cada uma informa o rastreio: um código, que vira link pelo molde, ou o link pronto."
    >
        <template #actions>
            <UiRefreshButton />
            <UiButton icon="plus" @click="openCreate">Nova transportadora</UiButton>
        </template>
    </PageHeader>

    <UiTable :columns="columns" caption="Transportadoras cadastradas">
        <template v-for="carrier in props.carriers" :key="carrier.id">
            <tr>
                <td>
                    <span class="font-medium text-ink">{{ carrier.name }}</span>
                    <span v-if="carrier.integrated" class="ml-2 text-xs text-ink-muted">
                        rastreio automático
                    </span>
                </td>
                <td>
                    {{ rotuloDoCampo(carrier) }}
                    <span v-if="carrier.tracking_field === 'code' && !carrier.tracking_url_template" class="block text-xs text-ink-muted">
                        sem link de acompanhamento
                    </span>
                </td>
                <td class="tabular-nums">{{ carrier.shipments }}</td>
                <td>
                    <UiBadge :variant="carrier.active ? 'success' : 'neutral'">
                        {{ carrier.active ? 'Ativa' : 'Inativa' }}
                    </UiBadge>
                </td>
                <td>
                    <UiMenu :label="`Ações de ${carrier.name}`" :items="menuItems(carrier)" />
                </td>
            </tr>

            <!-- A recusa da remoção explica o porquê (já expediu, é integrada), e
                 o lugar de ler isso é embaixo da linha de onde ela partiu. -->
            <tr v-if="rowAction.error(carrier.id)">
                <td :colspan="columns.length" class="text-sm text-danger">
                    {{ rowAction.error(carrier.id) }}
                </td>
            </tr>
        </template>

        <template #empty>
            <UiEmpty
                icon="truck"
                title="Nenhuma transportadora cadastrada"
                message="Sem transportadora cadastrada não é possível expedir resgates."
            >
                <template #action>
                    <UiButton icon="plus" @click="openCreate">Nova transportadora</UiButton>
                </template>
            </UiEmpty>
        </template>
    </UiTable>

    <UiDrawer
        v-model:open="drawerOpen"
        :title="editing ? editing.name : 'Nova transportadora'"
        description="Vale para as expedições feitas a partir de agora."
    >
        <form id="form-transportadora" class="space-y-5" @submit.prevent="submit">
            <UiField label="Nome" :error="form.errors.name" required>
                <UiInput v-model="form.name" data-autofocus maxlength="80" autocomplete="off" />
            </UiField>

            <UiField
                label="Como o rastreio chega"
                :error="form.errors.tracking_field"
                required
                hint="É o campo que o operador vai preencher ao expedir por esta transportadora."
            >
                <UiSelect v-model="form.tracking_field" :options="camposDeRastreio" />
            </UiField>

            <!-- Só para quem entrega código: quem entrega link não tem código
                 para encaixar no molde. -->
            <UiField
                v-if="!usaLink"
                label="Link de acompanhamento"
                :error="form.errors.tracking_url_template"
                hint="Opcional. Use {codigo} onde o código de rastreio entra. Em branco, o código aparece como texto."
            >
                <UiInput
                    v-model="form.tracking_url_template"
                    placeholder="https://rastreio.exemplo.com.br/?objeto={codigo}"
                    autocomplete="off"
                />
            </UiField>

            <UiHintCard v-else icon="truck" title="Link de rastreio">
                O operador cola o endereço completo a cada expedição, como a transportadora informar.
                Não há código a digitar nem molde a cadastrar.
            </UiHintCard>

            <UiCheckbox
                v-model="form.active"
                label="Ativa"
                description="Inativa some da tela de expedição, mas continua nomeando os resgates que já expediu."
            />

            <UiAlert v-if="editing?.integrated" variant="info">
                Os Correios têm integração própria: com a prepostagem ligada, o código de rastreio nasce
                da API e o acompanhamento é automático.
            </UiAlert>
        </form>

        <template #footer>
            <UiButton type="submit" form="form-transportadora" :loading="form.processing">
                {{ editing ? 'Salvar' : 'Cadastrar' }}
            </UiButton>
            <UiButton variant="ghost" @click="drawerOpen = false">Cancelar</UiButton>
        </template>
    </UiDrawer>
</template>
