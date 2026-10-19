<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import ClientTree from '../../../Components/ClientTree.vue';
import CompanyForm from '../../../Components/CompanyForm.vue';
import { useConfirm } from '../../../composables/useConfirm';
import PageHeader from '../../../Components/PageHeader.vue';
import UiAlert from '../../../Components/UiAlert.vue';
import UiBadge from '../../../Components/UiBadge.vue';
import UiButton from '../../../Components/UiButton.vue';
import UiCode from '../../../Components/UiCode.vue';
import UiDrawer from '../../../Components/UiDrawer.vue';
import UiEmpty from '../../../Components/UiEmpty.vue';
import UiField from '../../../Components/UiField.vue';
import UiFileDrop from '../../../Components/UiFileDrop.vue';
import UiIcon from '../../../Components/UiIcon.vue';
import UiInput from '../../../Components/UiInput.vue';
import UiMenu from '../../../Components/UiMenu.vue';
import UiPagination from '../../../Components/UiPagination.vue';
import UiRefreshButton from '../../../Components/UiRefreshButton.vue';
import UiRowErrors from '../../../Components/UiRowErrors.vue';
import UiRowLink from '../../../Components/UiRowLink.vue';
import UiSearchInput from '../../../Components/UiSearchInput.vue';
import UiTable from '../../../Components/UiTable.vue';
import UiTabs from '../../../Components/UiTabs.vue';
import { formatDocument, maskCep, maskDocument, maskPhone } from '../../../lib/format';

const props = defineProps({
    companies: { type: Array, default: () => [] },
});

const inertiaPage = usePage();

const EMPTY = {
    name: '', display_name: '', trade_name: '', cnpj: '',
    contact_name: '', contact_email: '', contact_phone: '',
    zip: '', street: '', number: '', complement: '', district: '', city: '', state: '',
    notes: '',
};

const PER_PAGE = 25;

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

const query = ref('');
const filter = ref('todas');
const page = ref(1);

// Árvore é o padrão: a pergunta que traz alguém aqui é "quem cuida deste
// cliente / este Projeto tem admin / esta pessoa está cadastrada", e não a
// conferência de campos do cadastro, que é o que a tabela serve.
const view = ref('arvore');

/*
 * Busca por PESSOA (e-mail ou nome), no servidor.
 *
 * A busca da tela só enxerga o que está carregado — razão social e CNPJ — e
 * procurar alguém pelo e-mail não devolvia nada. As pessoas vivem em três
 * níveis abaixo e em três papéis diferentes, então quem sabe responder é o
 * banco.
 */
const peopleHits = ref(null);
let buscaEmCurso = null;

async function buscarPessoas(termo) {
    const q = termo.trim();

    // O mesmo piso do servidor: menos que isto casaria com quase tudo.
    if (q.length < 3) {
        peopleHits.value = null;

        return;
    }

    // Só o resultado da ÚLTIMA digitação vale: sem isto, uma resposta lenta de
    // "yag" chega depois de "yago" e sobrescreve a lista com o termo antigo.
    const meuTurno = Symbol('busca');
    buscaEmCurso = meuTurno;

    try {
        const resposta = await fetch(`${route('admin.companies.people.search')}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
        });

        if (buscaEmCurso !== meuTurno) return;

        peopleHits.value = resposta.ok ? await resposta.json() : null;
    } catch {
        if (buscaEmCurso === meuTurno) peopleHits.value = null;
    }
}

watch(query, (termo) => {
    resetPage();
    buscarPessoas(termo);
});

/** Empresas que a busca alcança: por cadastro OU por gente dentro delas. */
const companyIdsComPessoa = computed(() => new Set(peopleHits.value?.company_ids ?? []));

const incomplete = (company) => company.missing_fields.length > 0;
const withoutAdmin = (company) => company.admins.length === 0;

const tabs = computed(() => [
    { key: 'todas', label: 'Todas', count: props.companies.length },
    { key: 'incompletas', label: 'Cadastro incompleto', count: props.companies.filter(incomplete).length },
    { key: 'sem-admin', label: 'Sem Admin Cliente', count: props.companies.filter(withoutAdmin).length },
]);

function matchesFilter(company) {
    if (filter.value === 'incompletas') return incomplete(company);
    if (filter.value === 'sem-admin') return withoutAdmin(company);

    return true;
}

function matchesQuery(company) {
    const term = query.value.trim().toLowerCase();

    if (!term) return true;

    if (`${company.name} ${company.display_name ?? ''} ${company.trade_name ?? ''}`.toLowerCase().includes(term)) {
        return true;
    }

    // A pessoa encontrada traz a empresa dela junto: procurar por e-mail tem
    // de devolver o cliente onde aquele e-mail está.
    if (companyIdsComPessoa.value.has(company.id)) return true;

    /*
     * Busca por CNPJ só quando o termo é MESMO um documento.
     *
     * Antes bastava sobrar um dígito depois de tirar a pontuação, e o "2" de
     * "yagocandidom2" casava com todo CNPJ que tivesse um 2 — a busca por
     * e-mail devolvia uma empresa que não tem ninguém com aquele e-mail.
     *
     * Duas condições: pelo menos 3 dígitos, e o termo sem nada além de
     * dígitos e pontuação de documento. "51.785" procura CNPJ; "yago2" não.
     */

    if (!/^[\d.\-/\s]+$/.test(term)) return false;

    const digits = term.replace(/\D/g, '');

    return digits.length >= 3 && String(company.cnpj ?? '').includes(digits);
}

// Cadastro incompleto sobe ao topo: é a única linha da lista que pede ação.
const filtered = computed(() =>
    props.companies
        .filter((company) => matchesFilter(company) && matchesQuery(company))
        .sort((a, b) => Number(incomplete(b)) - Number(incomplete(a))),
);

const paged = computed(() =>
    filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE),
);

const resultLabel = computed(() => {
    const total = props.companies.length;

    if (query.value.trim() || filter.value !== 'todas') {
        return `mostrando ${filtered.value.length} de ${total}`;
    }

    return total === 1 ? '1 empresa' : `${total} empresas`;
});

const emptyMessage = computed(() => {
    if (query.value.trim()) return `Nenhuma empresa para "${query.value.trim()}".`;
    if (filter.value === 'incompletas') return 'Nenhuma empresa com cadastro incompleto.';
    if (filter.value === 'sem-admin') return 'Todas as Empresas têm pelo menos um Admin Cliente.';

    return 'Nenhuma empresa cadastrada. Elas normalmente nascem do webhook da Omie.';
});

function resetPage() {
    page.value = 1;
}

const columns = [
    { key: 'company', label: 'Empresa' },
    { key: 'cnpj', label: 'CNPJ' },
    { key: 'admins', label: 'Admin Cliente' },
    { key: 'end_customers', label: 'Colaboradores', numeric: true },
    { key: 'projects', label: 'Projetos', numeric: true },
    { key: 'situation', label: 'Situação' },
    { key: 'actions', label: 'Ações', srOnlyLabel: true },
];

// --- textos derivados ---

/** Segunda linha da célula de Admin Cliente: quantos mais e quantos ainda não entraram. */
function adminsHint(company) {
    const extra = company.admins.length - 1;
    const pending = company.admins.filter((admin) => admin.access_pending).length;
    const partes = [];

    if (extra > 0) partes.push(`+${extra}`);
    if (pending > 0) partes.push(plural(pending, 'convite pendente', 'convites pendentes'));

    return partes.join(' · ');
}

function situationBadge(company) {
    const total = company.missing_fields.length;

    if (total === 0) return { variant: 'success', label: 'Completo' };

    return { variant: 'warning', label: `${plural(total, 'campo', 'campos')} em falta` };
}

// --- cadastro da empresa ---
const companyOpen = ref(false);
const editingId = ref(null);
const companyForm = useForm({ ...EMPTY });

const editing = computed(() => props.companies.find((c) => c.id === editingId.value) ?? null);

function openCreate() {
    editingId.value = null;
    companyForm.defaults({ ...EMPTY });
    companyForm.reset();
    companyForm.clearErrors();
    companyOpen.value = true;
}

function openEdit(company) {
    editingId.value = company.id;
    // Campo nulo do banco vira string vazia: o input não aceita null. A UF vem
    // para uma lista fechada, então precisa bater com o valor da opção.
    companyForm.defaults({
        ...Object.fromEntries(Object.keys(EMPTY).map((key) => [key, company[key] ?? ''])),
        cnpj: maskDocument(company.cnpj ?? ''),
        contact_phone: maskPhone(company.contact_phone ?? ''),
        zip: maskCep(company.zip ?? ''),
        state: String(company.state ?? '').toUpperCase(),
    });
    companyForm.reset();
    companyForm.clearErrors();
    companyOpen.value = true;
}

function saveCompany() {
    const options = { preserveScroll: true, onSuccess: () => (companyOpen.value = false) };

    if (editingId.value) {
        companyForm.put(route('admin.companies.update', editingId.value), options);

        return;
    }

    companyForm.post(route('admin.companies.store'), options);
}

const menuItems = (company) => [
    { label: 'Abrir Empresa', href: route('admin.companies.show', company.id) },
    {
        label: incomplete(company) ? 'Completar cadastro' : 'Editar cadastro',
        onSelect: () => openEdit(company),
    },
    { label: 'Adicionar Admin Cliente', onSelect: () => openAdmin(company) },
    {
        label: 'Excluir empresa',
        danger: true,
        separatorBefore: true,
        onSelect: () => destroyCompany(company),
    },
];

// Exclusão definitiva: a confirmação lista o que vai junto, porque "excluir
// empresa" leva Projetos, resgates e as contas das pessoas dela.
const confirm = useConfirm();

async function destroyCompany(company) {
    const partes = [];
    if (company.projects_count > 0) partes.push(`${company.projects_count} projeto(s) com resgates e rastreios`);
    const contas = (company.admins?.length ?? 0) + (company.end_customers_count ?? 0);
    if (contas > 0) partes.push(`${contas} conta(s) de acesso (Admin Cliente e Colaboradores)`);

    await confirm({
        title: `Excluir "${company.name}"?`,
        description: (partes.length
            ? `Serão removidos também: ${partes.join(' e ')}. `
            : '') + 'A exclusão é permanente e não pode ser desfeita.',
        confirmLabel: 'Excluir empresa',
        variant: 'danger',
        errorBag: 'destroy',
        action: () => router.delete(route('admin.companies.destroy', company.id), { preserveScroll: true }),
    });
}

// --- Admin Cliente da empresa ---
const adminOpen = ref(false);
const adminCompany = ref(null);
const adminForm = useForm({ name: '', email: '' });

function openAdmin(company) {
    adminCompany.value = company;
    adminForm.reset();
    adminForm.clearErrors();
    adminOpen.value = true;
}

function saveAdmin() {
    adminForm.post(route('admin.companies.admins.store', adminCompany.value.id), {
        preserveScroll: true,
        onSuccess: () => (adminOpen.value = false),
    });
}

// --- importação em lote ---
const batchOpen = ref(false);
const batchForm = useForm({ file: null });
const batchResult = ref(null);

function openBatch() {
    // Painel reaberto é sessão nova: arquivo e resultado do envio anterior não
    // podem reaparecer como se fossem de agora.
    batchForm.defaults({ file: null }).reset().clearErrors();
    batchResult.value = null;
    batchOpen.value = true;
}

function submitBatch() {
    if (!batchForm.file) return;

    batchResult.value = null;
    batchForm.post(route('admin.companies.batch'), {
        preserveScroll: true,
        onSuccess: () => batchForm.defaults({ file: null }).reset(),
        onFinish: captureBatchResult,
    });
}

/**
 * O backend responde em dois canais opostos — os totais em `flash.status` e as
 * linhas recusadas no bag `batch`. O painel junta os dois num sinal só.
 */
function captureBatchResult() {
    const totals = String(inertiaPage.props.flash?.status ?? '')
        .match(/^(\d+) empresas cadastradas e (\d+) atualizadas/);
    const rows = batchForm.errors.batch ?? null;

    // Sem nenhum dos dois é erro de arquivo: ele mora no campo, não aqui.
    if (!totals && !rows) return;

    batchResult.value = {
        created: totals ? Number(totals[1]) : null,
        updated: totals ? Number(totals[2]) : null,
        rows,
    };
    // Lote com linhas recusadas volta pelo canal de erro: o onSuccess não roda
    // e é aqui que o arquivo já processado sai do campo.
    batchForm.file = null;
}

const batchFailed = computed(() =>
    batchResult.value?.rows ? batchResult.value.rows.split(' | ').filter(Boolean).length : 0,
);

const batchVariant = computed(() => {
    if (batchResult.value?.created === null) return 'danger';

    return batchFailed.value > 0 ? 'warning' : 'success';
});
</script>

<template>
    <Head title="Clientes" />

    <!--
        Sem "Nova empresa": o cliente nasce no fluxo de criar Projeto, junto
        com o Admin Cliente e o próprio Projeto. Empresa cadastrada aqui, solta,
        ficava sem admin e sem Projeto — parada, e sem nada na tela dizendo o
        que faltava. A importação por planilha continua, que é carga inicial.
    -->
    <PageHeader
        title="Clientes"
        subtitle="Cada Cliente tem um Admin Cliente, que cadastra os Colaboradores que resgatam os kits."
    >
        <template #actions>
            <UiRefreshButton />
            <UiButton variant="secondary" icon="upload" @click="openBatch">Importar planilha</UiButton>
            <UiButton icon="plus" :href="route('projects.create')">Novo Projeto</UiButton>
        </template>
    </PageHeader>

    <div class="space-y-3">
        <UiSearchInput
            v-model="query"
            placeholder="Buscar por empresa, CNPJ, e-mail ou nome de pessoa"
            :result-label="resultLabel"
        />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <UiTabs
                v-model="filter"
                :tabs="tabs"
                variant="segmented"
                panel-prefix="empresas"
                @update:model-value="resetPage"
            />

            <!-- Duas leituras do MESMO recorte: a busca e o filtro acima valem
                 para as duas. A árvore responde "quem cuida disto"; a tabela,
                 "o que falta no cadastro" e as ações por linha. -->
            <div class="flex items-center gap-1 rounded-control bg-surface-muted p-1">
                <button
                    v-for="modo in [
                        { key: 'arvore', label: 'Árvore', icon: 'projects' },
                        { key: 'tabela', label: 'Tabela', icon: 'reports' },
                    ]"
                    :key="modo.key"
                    type="button"
                    :aria-pressed="view === modo.key"
                    :class="[
                        'inline-flex min-h-8 items-center gap-1.5 rounded-control px-3 text-sm font-medium transition-colors',
                        view === modo.key ? 'bg-surface text-ink shadow-xs' : 'text-ink-muted hover:text-ink',
                    ]"
                    @click="view = modo.key"
                >
                    <UiIcon :name="modo.icon" :size="15" aria-hidden="true" />
                    {{ modo.label }}
                </button>
            </div>
        </div>
    </div>

    <section
        :id="`empresas-${filter}`"
        role="tabpanel"
        tabindex="0"
        :aria-labelledby="`empresas-aba-${filter}`"
        class="mt-4 space-y-3"
    >
        <!-- Quem a busca achou, antes da árvore: o resultado responde na hora
             "esta pessoa existe, é isto e está aqui", e a árvore abaixo já
             abre no caminho dela. -->
        <div
            v-if="peopleHits?.people?.length"
            class="rounded-card bg-surface p-4 shadow-card"
        >
            <p class="mb-2 text-xs font-semibold text-ink-muted">
                {{ plural(peopleHits.people.length, 'pessoa encontrada', 'pessoas encontradas') }}
            </p>

            <ul class="divide-y divide-line">
                <li v-for="pessoa in peopleHits.people" :key="pessoa.id" class="flex flex-wrap items-center gap-2 py-2">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-ink">{{ pessoa.name }}</span>
                        <span class="block truncate text-xs text-ink-muted">{{ pessoa.email }}</span>
                    </span>

                    <UiBadge variant="neutral">{{ pessoa.role }}</UiBadge>
                    <UiBadge v-if="pessoa.access_pending" variant="warning">Convite pendente</UiBadge>
                    <span v-if="pessoa.company" class="text-xs text-ink-muted">{{ pessoa.company }}</span>

                    <Link
                        v-for="projeto in pessoa.projects"
                        :key="projeto.id"
                        :href="projeto.url"
                        class="text-xs font-medium text-brand-700 hover:underline"
                    >
                        {{ projeto.name }}
                    </Link>
                </li>
            </ul>
        </div>

        <ClientTree
            v-if="view === 'arvore'"
            :companies="paged"
            :empty-message="emptyMessage"
            :people-hits="peopleHits"
        />

        <UiTable v-else :columns="columns" caption="Clientes, com Admin Cliente e situação do cadastro">
            <tr v-for="company in paged" :key="company.id">
                <th scope="row" class="font-normal">
                    <!-- A razão social é o próprio atalho para a página da
                         Empresa: o caminho mais curto não pode passar pelo menu. -->
                    <UiRowLink
                        :href="route('admin.companies.show', company.id)"
                        :hint="company.trade_name"
                        class="max-w-72"
                    >
                        {{ company.name }}
                        <UiBadge v-if="company.origem" variant="neutral" class="ml-1.5">{{ company.origem }}</UiBadge>
                    </UiRowLink>
                    <!-- Nome de exibição (Company Name, UAT): quando definido,
                         é este o nome que o Colaborador lê nos e-mails. -->
                    <p v-if="company.display_name" class="max-w-72 truncate text-xs text-ink-muted">
                        Nos e-mails: {{ company.display_name }}
                    </p>
                </th>

                <td><UiCode :value="formatDocument(company.cnpj)" /></td>

                <td>
                    <template v-if="company.admins.length">
                        <p class="max-w-48 truncate">{{ company.admins[0].name }}</p>
                        <p v-if="adminsHint(company)" class="text-xs text-ink-muted">
                            {{ adminsHint(company) }}
                        </p>
                    </template>

                    <UiBadge v-else variant="danger">Sem Admin Cliente</UiBadge>
                </td>

                <td>{{ company.end_customers_count }}</td>
                <td>{{ company.projects_count }}</td>

                <td>
                    <UiBadge :variant="situationBadge(company).variant">
                        {{ situationBadge(company).label }}
                    </UiBadge>
                </td>

                <td>
                    <UiMenu :label="`Ações de ${company.name}`" :items="menuItems(company)" />
                </td>
            </tr>

            <template #empty>
                <UiEmpty icon="companies" :message="emptyMessage">
                    <template v-if="!query.trim() && filter === 'todas'" #action>
                        <UiButton icon="plus" @click="openCreate">Nova empresa</UiButton>
                    </template>
                </UiEmpty>
            </template>
        </UiTable>

        <UiPagination
            v-model="page"
            label="Empresas"
            :total="filtered.length"
            :per-page="PER_PAGE"
        />
    </section>

    <UiDrawer
        v-model:open="companyOpen"
        :title="editing ? editing.name : 'Nova empresa'"
        :description="editing
            ? `${formatDocument(editing.cnpj)}${editing.omie_customer_code ? ' · criada pela Omie' : ''}`
            : null"
    >
        <form id="form-empresa" @submit.prevent="saveCompany">
            <CompanyForm :form="companyForm" :missing="editing?.missing_fields ?? []" />
        </form>

        <template #footer>
            <UiButton type="submit" form="form-empresa" :loading="companyForm.processing">
                Salvar empresa
            </UiButton>
            <UiButton variant="ghost" @click="companyOpen = false">Cancelar</UiButton>
        </template>
    </UiDrawer>

    <UiDrawer
        v-model:open="adminOpen"
        :title="`Novo Admin Cliente · ${adminCompany?.name ?? ''}`"
        :description="`Ela recebe um convite por e-mail. Ao definir a senha, passa a ver apenas os Projetos da ${adminCompany?.name ?? 'Empresa'} e a cadastrar os Colaboradores dessa Empresa.`"
    >
        <form id="form-admin" class="space-y-4" @submit.prevent="saveAdmin">
            <UiField label="Nome" :error="adminForm.errors.name" required>
                <UiInput v-model="adminForm.name" autocomplete="name" :invalid="!!adminForm.errors.name" />
            </UiField>

            <UiField label="E-mail" :error="adminForm.errors.email" required>
                <UiInput
                    v-model="adminForm.email"
                    type="email"
                    autocomplete="email"
                    inputmode="email"
                    :invalid="!!adminForm.errors.email"
                />
            </UiField>
        </form>

        <template #footer>
            <UiButton type="submit" form="form-admin" :loading="adminForm.processing">
                Criar e enviar convite
            </UiButton>
            <UiButton variant="ghost" @click="adminOpen = false">Cancelar</UiButton>
        </template>
    </UiDrawer>

    <UiDrawer
        v-model:open="batchOpen"
        title="Importar empresas por planilha"
        description="A planilha completa quem já existe e cria quem ainda não existe. A empresa é reconhecida pelo CNPJ."
        size="lg"
    >
        <ol class="space-y-5">
            <li class="flex gap-3">
                <span
                    aria-hidden="true"
                    class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700"
                >
                    1
                </span>
                <div class="min-w-0 flex-1 space-y-2">
                    <h3 class="text-sm font-semibold text-ink">
                        <span class="sr-only">Passo 1. </span>Baixe o modelo
                    </h3>

                    <!-- Download de arquivo: <a> de verdade. `UiButton :href`
                         renderiza <Link>, que interceptaria o clique. -->
                    <a
                        :href="route('admin.companies.template')"
                        class="h-control-md inline-flex items-center justify-center gap-2 rounded-control bg-brand-600 px-4 text-sm font-medium whitespace-nowrap text-ink-inverse transition-colors select-none hover:bg-brand-700"
                    >
                        <UiIcon name="download" />
                        Baixar modelo (.xlsx)
                    </a>

                    <p class="text-sm text-ink-muted">
                        Já vem com o cabeçalho e um exemplo preenchido: apague a linha de exemplo
                        antes de enviar.
                    </p>
                </div>
            </li>

            <li class="flex gap-3">
                <span
                    aria-hidden="true"
                    class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700"
                >
                    2
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="mb-2 text-sm font-semibold text-ink">
                        <span class="sr-only">Passo 2. </span>Envie
                    </h3>

                    <form id="form-lote-empresas" @submit.prevent="submitBatch">
                        <UiField label="Arquivo da planilha" :error="batchForm.errors.file">
                            <UiFileDrop
                                :file="batchForm.file"
                                :progress="batchForm.progress?.percentage ?? null"
                                :disabled="batchForm.processing"
                                @select="batchForm.file = $event"
                                @clear="batchForm.file = null"
                            >
                                <template #result>
                                    <UiAlert
                                        v-if="batchResult"
                                        :variant="batchVariant"
                                        title="Resultado da importação"
                                        dismissible
                                        @dismiss="batchResult = null"
                                    >
                                        <p
                                            v-if="batchResult.created !== null"
                                            class="flex flex-wrap items-center gap-x-4 gap-y-1 font-medium"
                                        >
                                            <span class="inline-flex items-center gap-1.5">
                                                <UiIcon name="plus" :size="16" class="text-success" />
                                                {{ plural(batchResult.created, 'cadastrada', 'cadastradas') }}
                                            </span>
                                            <span class="inline-flex items-center gap-1.5">
                                                <UiIcon name="check" :size="16" class="text-success" />
                                                {{ plural(batchResult.updated, 'atualizada', 'atualizadas') }}
                                            </span>
                                            <span v-if="batchFailed" class="inline-flex items-center gap-1.5">
                                                <UiIcon name="close" :size="16" class="text-danger" />
                                                {{ plural(batchFailed, 'recusada', 'recusadas') }}
                                            </span>
                                        </p>

                                        <!-- Lote recusado inteiro: não há contagem nem linha a corrigir. -->
                                        <p v-if="batchResult.created === null">{{ batchResult.rows }}</p>

                                        <template v-else-if="batchResult.rows">
                                            <p
                                                class="mt-3 mb-1.5 text-xs font-semibold tracking-wider text-ink-muted uppercase"
                                            >
                                                Linhas que falharam
                                            </p>
                                            <UiRowErrors :raw="batchResult.rows" />
                                            <p class="mt-2">
                                                Corrija apenas essas linhas e envie de novo. As que já
                                                entraram não serão duplicadas.
                                            </p>
                                        </template>

                                        <template v-if="batchResult.rows" #actions>
                                            <UiButton variant="secondary" size="sm" @click="batchResult = null">
                                                Enviar planilha corrigida
                                            </UiButton>
                                        </template>
                                    </UiAlert>
                                </template>
                            </UiFileDrop>
                        </UiField>
                    </form>
                </div>
            </li>
        </ol>

        <template #footer>
            <UiButton
                type="submit"
                form="form-lote-empresas"
                :disabled="!batchForm.file"
                :loading="batchForm.processing"
            >
                Importar planilha
            </UiButton>
            <UiButton variant="ghost" @click="batchOpen = false">Fechar</UiButton>
        </template>
    </UiDrawer>
</template>
