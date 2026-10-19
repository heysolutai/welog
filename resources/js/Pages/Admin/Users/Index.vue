<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import UiBadge from '../../../Components/UiBadge.vue';
import UiButton from '../../../Components/UiButton.vue';
import UiDrawer from '../../../Components/UiDrawer.vue';
import UiEmpty from '../../../Components/UiEmpty.vue';
import UiField from '../../../Components/UiField.vue';
import UiInput from '../../../Components/UiInput.vue';
import UiMenu from '../../../Components/UiMenu.vue';
import UiRefreshButton from '../../../Components/UiRefreshButton.vue';
import UiSearchInput from '../../../Components/UiSearchInput.vue';
import UiTable from '../../../Components/UiTable.vue';
import { useConfirm } from '../../../composables/useConfirm';
import { useRowAction } from '../../../composables/useRowAction';
import { useToast } from '../../../composables/useToast';

const props = defineProps({
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
});

/**
 * Quem cadastra decide pelo que a pessoa poderá fazer, não pelo código do
 * perfil. Os perfis atribuíveis continuam vindo de `roles`; daqui sai só o
 * texto que acompanha cada um.
 */
const ROLES = {
    admin_wegift: {
        label: 'Admin WeGift',
        summary: 'acesso total',
        description: 'Tudo, incluindo usuários, empresas, modelos de e-mail e benefícios fiscais',
    },
    wegift_n1: {
        label: 'Operador N1',
        summary: 'opera e libera',
        description: 'Cria projetos, libera resgates e importa planilhas',
    },
    wegift_n2: {
        label: 'Operador N2',
        summary: 'somente consulta',
        description: 'Somente consulta. Não libera projeto nem importa planilha',
    },
};

const roleLabel = (value) =>
    ROLES[value]?.label ?? props.roles.find((role) => role.value === value)?.label ?? value;

const roleOptions = computed(() =>
    props.roles.map((role) => ({
        value: role.value,
        label: roleLabel(role.value),
        description: ROLES[role.value]?.description ?? null,
    })),
);

// Precedência da situação: Inativo > Convite pendente > Ativo (§11.4).
const SITUATIONS = {
    inactive: { label: 'Inativo', variant: 'neutral' },
    pending: { label: 'Convite pendente', variant: 'warning' },
    active: { label: 'Ativo', variant: 'success' },
};

function situationOf(user) {
    if (!user.active) return 'inactive';

    return user.access_pending ? 'pending' : 'active';
}

const situationBadge = (user) => SITUATIONS[situationOf(user)];

const search = ref('');
const situation = ref('all');

const counts = computed(() =>
    props.users.reduce(
        (total, user) => {
            total[situationOf(user)] += 1;

            return total;
        },
        { active: 0, pending: 0, inactive: 0 },
    ),
);

const filters = computed(() => [
    { key: 'all', label: 'Todos', count: null },
    { key: 'active', label: 'Ativos', count: counts.value.active },
    { key: 'pending', label: 'Convite pendente', count: counts.value.pending },
    { key: 'inactive', label: 'Inativos', count: counts.value.inactive },
]);

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.users.filter((user) => {
        if (situation.value !== 'all' && situationOf(user) !== situation.value) return false;

        return term === '' || `${user.name} ${user.email}`.toLowerCase().includes(term);
    });
});

const filtering = computed(() => search.value.trim() !== '' || situation.value !== 'all');

const resultLabel = computed(() =>
    filtering.value
        ? `mostrando ${visible.value.length} de ${props.users.length}`
        : `${props.users.length} ${props.users.length === 1 ? 'usuário' : 'usuários'}`,
);

const emptyMessage = computed(() =>
    search.value.trim()
        ? `Nenhum usuário para "${search.value.trim()}".`
        : 'Nenhum usuário nessa situação.',
);

const columns = [
    { key: 'person', label: 'Pessoa' },
    { key: 'role', label: 'Perfil' },
    { key: 'situation', label: 'Situação' },
    { key: 'actions', label: 'Ações', srOnlyLabel: true, align: 'end', width: '5rem' },
];

const drawerOpen = ref(false);
const editing = ref(null);
const form = useForm({ name: '', email: '', role: '' });

/** `defaults` → `reset` → `clearErrors`: sem isso o erro de um registro vaza para o próximo. */
function load(values) {
    form.defaults(values);
    form.reset();
    form.clearErrors();
    drawerOpen.value = true;
}

function openCreate() {
    editing.value = null;
    // Nenhum perfil pré-marcado: conceder acesso é escolha consciente.
    load({ name: '', email: '', role: '' });
}

function openEdit(user) {
    editing.value = user;
    load({ name: user.name, email: user.email, role: user.role });
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (drawerOpen.value = false) };

    if (editing.value) {
        // O e-mail não é editável — fora do payload do PUT.
        form
            .transform(({ name, role }) => ({ name, role }))
            .put(route('admin.users.update', editing.value.id), options);

        return;
    }

    form.transform((data) => data).post(route('admin.users.store'), options);
}

const confirm = useConfirm();
const rowAction = useRowAction();
const toast = useToast();

async function toggleAccess(user) {
    const deactivating = user.active;

    await confirm({
        title: deactivating
            ? `Desativar o acesso de ${user.name}?`
            : `Ativar o acesso de ${user.name}?`,
        description: deactivating
            ? 'Ele deixa de conseguir entrar no WeLog no próximo login. Os projetos e resgates que ele tocou continuam no sistema. Você pode reativar depois.'
            : 'A pessoa volta a conseguir entrar no WeLog com o mesmo e-mail.',
        confirmLabel: deactivating ? 'Desativar acesso' : 'Ativar acesso',
        variant: deactivating ? 'danger' : 'default',
        errorBag: 'active',
        action: () => rowAction.run(user.id, route('admin.users.toggle', user.id)),
    });
}

function resend(user) {
    rowAction.run(user.id, route('admin.users.resend', user.id), {
        errorKey: 'invite',
        // Ação sem formulário: a recusa vira aviso, não erro de campo (§9.3).
        onError: (errors) => toast.warning(errors.invite),
    });
}

function menuItems(user) {
    const items = [{ label: 'Editar', onSelect: () => openEdit(user) }];

    if (user.access_pending) {
        items.push({
            label: 'Reenviar convite',
            disabled: rowAction.pending(user.id),
            onSelect: () => resend(user),
        });
    }

    items.push({
        label: user.active ? 'Desativar acesso' : 'Ativar acesso',
        danger: user.active,
        separatorBefore: true,
        disabled: rowAction.pending(user.id),
        onSelect: () => toggleAccess(user),
    });

    // Excluir é diferente de desativar: desativar guarda o histórico, excluir
    // apaga a conta. Por isso vem por último e separado.
    items.push({
        label: 'Excluir usuário',
        danger: true,
        separatorBefore: true,
        disabled: rowAction.pending(user.id),
        onSelect: () => destroyUser(user),
    });

    return items;
}

async function destroyUser(user) {
    await confirm({
        title: `Excluir ${user.name}?`,
        description: 'A conta e o histórico dela são apagados definitivamente. '
            + 'Para apenas tirar o acesso mantendo o histórico, use "Desativar acesso".',
        confirmLabel: 'Excluir usuário',
        variant: 'danger',
        errorBag: 'destroy',
        action: () => router.delete(route('admin.users.destroy', user.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Equipe WeGift" />

    <PageHeader
        title="Equipe WeGift"
        subtitle="Quem da equipe entra no sistema e o que cada um pode fazer."
    >
        <template #actions>
            <UiRefreshButton />
            <UiButton icon="plus" @click="openCreate">Novo usuário</UiButton>
        </template>
    </PageHeader>

    <div v-if="users.length" class="mb-4 space-y-3">
        <UiSearchInput v-model="search" placeholder="nome ou e-mail" :result-label="resultLabel" />

        <div role="group" aria-label="Filtrar por situação" class="flex flex-wrap gap-2">
            <button
                v-for="filter in filters"
                :key="filter.key"
                type="button"
                :aria-pressed="situation === filter.key"
                :class="[
                    'h-control-sm inline-flex items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors',
                    situation === filter.key
                        ? 'border-brand-600 bg-brand-50 text-brand-700'
                        : 'border-line bg-surface text-ink-muted hover:text-ink',
                ]"
                @click="situation = filter.key"
            >
                {{ filter.label }}
                <span v-if="filter.count !== null" class="tabular-nums">{{ filter.count }}</span>
            </button>
        </div>
    </div>

    <UiTable
        :columns="columns"
        caption="Equipe WeGift, com perfil de acesso e situação"
        :max-rows="12"
    >
        <!-- Linha inteira esmaecida quando o acesso está desativado. -->
        <tr v-for="user in visible" :key="user.id" :class="!user.active && 'text-ink-muted'">
            <td>
                <p class="font-medium">{{ user.name }}</p>
                <p class="text-xs text-ink-muted">{{ user.email }}</p>
            </td>
            <td>
                <p>{{ roleLabel(user.role) }}</p>
                <p v-if="ROLES[user.role]" class="text-xs text-ink-muted">
                    {{ ROLES[user.role].summary }}
                </p>
            </td>
            <td>
                <UiBadge :variant="situationBadge(user).variant">
                    {{ situationBadge(user).label }}
                </UiBadge>
            </td>
            <td>
                <UiMenu :label="`Ações de ${user.name}`" :items="menuItems(user)" />
            </td>
        </tr>

        <template #empty>
            <UiEmpty v-if="filtering" icon="users" :message="emptyMessage" />
            <UiEmpty v-else icon="users" message="Nenhum usuário WeGift.">
                <template #action>
                    <UiButton icon="plus" @click="openCreate">Novo usuário</UiButton>
                </template>
            </UiEmpty>
        </template>
    </UiTable>

    <UiDrawer
        v-model:open="drawerOpen"
        :title="editing ? editing.name : 'Novo usuário'"
        :description="editing ? editing.email : null"
    >
        <form id="form-usuario" class="space-y-4" @submit.prevent="submit">
            <UiField label="Nome" :error="form.errors.name" required>
                <UiInput v-model="form.name" data-autofocus autocomplete="name" />
            </UiField>

            <UiField
                v-if="!editing"
                label="E-mail"
                :error="form.errors.email"
                hint="Recebe o convite para criar a senha."
                required
            >
                <UiInput v-model="form.email" type="email" autocomplete="email" />
            </UiField>

            <div v-else>
                <p class="text-sm font-medium text-ink">E-mail</p>
                <p class="mt-1.5 text-sm text-ink">{{ editing.email }}</p>
                <p class="mt-1.5 text-xs text-ink-muted">O e-mail não pode ser alterado.</p>
            </div>

            <!-- Bag `role` da atualização (auto-rebaixamento) cai aqui, dentro do painel. -->
            <UiField
                as="group"
                label="O que essa pessoa poderá fazer?"
                :error="form.errors.role"
                required
            >
                <div class="space-y-2">
                    <label
                        v-for="option in roleOptions"
                        :key="option.value"
                        :class="[
                            'flex cursor-pointer gap-3 rounded-control border p-3 transition-colors',
                            form.role === option.value
                                ? 'border-brand-600 bg-brand-50'
                                : 'border-line hover:border-line-strong',
                        ]"
                    >
                        <input
                            v-model="form.role"
                            type="radio"
                            name="perfil"
                            :value="option.value"
                            required
                            class="mt-0.5 size-4 shrink-0 accent-brand-600"
                        />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-ink">{{ option.label }}</span>
                            <span v-if="option.description" class="mt-0.5 block text-sm text-ink-muted">
                                {{ option.description }}
                            </span>
                        </span>
                    </label>
                </div>
            </UiField>
        </form>

        <template #footer>
            <UiButton type="submit" form="form-usuario" :loading="form.processing">
                {{ editing ? 'Salvar usuário' : 'Criar e enviar convite' }}
            </UiButton>
            <UiButton variant="ghost" @click="drawerOpen = false">Cancelar</UiButton>
        </template>
    </UiDrawer>
</template>
