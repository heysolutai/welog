<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import CompanyForm from '../../../Components/CompanyForm.vue';
import FirstAccessStudio from '../../../Components/FirstAccessStudio.vue';
import KpiCard from '../../../Components/KpiCard.vue';
import LogoTile from '../../../Components/LogoTile.vue';
import MessageStudio from '../../../Components/MessageStudio.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import UiAlert from '../../../Components/UiAlert.vue';
import UiBadge from '../../../Components/UiBadge.vue';
import UiButton from '../../../Components/UiButton.vue';
import UiCard from '../../../Components/UiCard.vue';
import UiCheckbox from '../../../Components/UiCheckbox.vue';
import UiCode from '../../../Components/UiCode.vue';
import UiDrawer from '../../../Components/UiDrawer.vue';
import UiEmpty from '../../../Components/UiEmpty.vue';
import UiField from '../../../Components/UiField.vue';
import UiIcon from '../../../Components/UiIcon.vue';
import UiInput from '../../../Components/UiInput.vue';
import UiMenu from '../../../Components/UiMenu.vue';
import UiModal from '../../../Components/UiModal.vue';
import UiRefreshButton from '../../../Components/UiRefreshButton.vue';
import UiRowLink from '../../../Components/UiRowLink.vue';
import UiTable from '../../../Components/UiTable.vue';
import UiToggle from '../../../Components/UiToggle.vue';
import { formatDate, formatDocument, formatPhone, maskCep, maskDocument, maskPhone } from '../../../lib/format';
import { useConfirm } from '../../../composables/useConfirm';
import { useRowAction } from '../../../composables/useRowAction';
import { useToast } from '../../../composables/useToast';

const props = defineProps({
    company: { type: Object, required: true },
    customization_catalog: { type: Array, default: () => [] },
    import_columns_catalog: { type: Array, default: () => [] },
    logo_levels_catalog: { type: Array, default: () => [] },
    projects:{ type: Array, default: () => [] },
    end_customers: { type: Array, default: () => [] },
    redemptions: { type: Array, default: () => [] },
});

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

const semAdmin = computed(() => props.company.admins.length === 0);

/** Primeiro e último nome, para o disco de identidade da linha do admin. */
function iniciais(nome) {
    const partes = (nome ?? '').trim().split(/\s+/).filter(Boolean);
    if (!partes.length) return '?';

    return (partes[0][0] + (partes.length > 1 ? partes[partes.length - 1][0] : '')).toUpperCase();
}

const kpis = computed(() => [
    {
        label: 'Projetos',
        value: props.projects.length,
        icon: 'projects',
        tone: 'brand',
        hint: props.projects.length ? `${props.projects.filter((p) => p.released).length} liberados` : 'nenhum ainda',
    },
    {
        label: 'Admin Cliente',
        value: props.company.admins.length,
        icon: 'users',
        // O zero aqui é o bloqueio da operação inteira, não um dado neutro.
        tone: semAdmin.value ? 'danger' : 'brand',
        hint: semAdmin.value ? 'ninguém cadastra Colaborador' : 'podem cadastrar Colaboradores',
    },
    {
        label: 'Colaboradores',
        value: props.end_customers.length,
        icon: 'users',
        tone: 'brand',
        hint: props.end_customers.length
            ? `${props.end_customers.filter((c) => c.access_pending).length} sem primeiro acesso`
            : 'nenhum cadastrado',
    },
    {
        label: 'Resgates',
        value: props.redemptions.length,
        icon: 'truck',
        tone: 'brand',
        hint: props.redemptions.length
            ? `${props.redemptions.filter((r) => r.status === 'delivered').length} entregues`
            : 'nenhum ainda',
    },
]);

function missingList(labels) {
    if (labels.length < 2) return labels[0];

    return `${labels.slice(0, -1).join(', ')} e ${labels[labels.length - 1]}`;
}

const dados = computed(() => [
    { label: 'CNPJ', value: formatDocument(props.company.cnpj) },
    // Company Name (UAT): o nome que os e-mails ao Colaborador mostram. Sem ele,
    // vale a razão social — é o que o "Não informado" da lista comunica.
    { label: 'Nome de exibição (e-mails)', value: props.company.display_name },
    { label: 'Código Omie', value: props.company.omie_customer_code },
    { label: 'Contato', value: props.company.contact_name },
    { label: 'E-mail', value: props.company.contact_email },
    { label: 'Telefone', value: formatPhone(props.company.contact_phone) },
    { label: 'Endereço', value: props.company.full_address },
]);

const projectColumns = [
    { key: 'name', label: 'Projeto' },
    { key: 'kit', label: 'Kit' },
    { key: 'stage', label: 'Etapa' },
    { key: 'deadline', label: 'Prazo' },
    { key: 'end_customers', label: 'Colaboradores', numeric: true },
    { key: 'redemptions', label: 'Resgates', numeric: true },
];

const kitLabel = (project) => {
    if (!project.kits.length) return null;
    if (project.kits.length === 1) return project.kits[0];

    return `${project.kits[0]} +${project.kits.length - 1}`;
};


// --- edição do cadastro ---
const EMPTY = {
    name: '', display_name: '', trade_name: '', cnpj: '',
    contact_name: '', contact_email: '', contact_phone: '',
    zip: '', street: '', number: '', complement: '', district: '', city: '', state: '',
    notes: '',
};

const editOpen = ref(false);
const companyForm = useForm({ ...EMPTY });

function openEdit() {
    companyForm.defaults({
        ...Object.fromEntries(Object.keys(EMPTY).map((key) => [key, props.company[key] ?? ''])),
        cnpj: maskDocument(props.company.cnpj ?? ''),
        contact_phone: maskPhone(props.company.contact_phone ?? ''),
        zip: maskCep(props.company.zip ?? ''),
        state: String(props.company.state ?? '').toUpperCase(),
    });
    companyForm.reset();
    companyForm.clearErrors();
    editOpen.value = true;
}

function saveCompany() {
    companyForm.put(route('admin.companies.update', props.company.id), {
        preserveScroll: true,
        onSuccess: () => (editOpen.value = false),
    });
}

// --- Admin Cliente ---
const adminOpen = ref(false);
const adminForm = useForm({ name: '', email: '' });

function openAdmin() {
    adminForm.reset();
    adminForm.clearErrors();
    adminOpen.value = true;
}

function saveAdmin() {
    adminForm.post(route('admin.companies.admins.store', props.company.id), {
        preserveScroll: true,
        onSuccess: () => (adminOpen.value = false),
    });
}

// --- Customizações ---
/*
 * Parametrizações do Admin WeGift, fora do cadastro.
 *
 * Mora num modal e não num card aberto porque não é dado que se consulta: é
 * ajuste raro, feito uma vez quando a campanha nasce.
 *
 * O catálogo de features vem do SERVIDOR (`customization_catalog`), não de uma
 * lista escrita aqui: feature nova nasce no model e aparece sozinha nesta
 * tela, sem passar por mais um arquivo.
 */
const customOpen = ref(false);

/*
 * Estado local das chaves, espelhado do servidor.
 *
 * A chave precisa reagir NO CLIQUE. Esperando o round-trip do Inertia, ela
 * ficava parada por uns décimos e a pessoa clicava de novo — o que desfazia o
 * primeiro clique. Aqui vira na hora e o servidor confirma; se a resposta vier
 * com erro, `onError` devolve ao valor anterior.
 */
const features = ref({ ...props.company.customizations });
const featurePendente = ref(new Set());

watch(() => props.company.customizations, (v) => (features.value = { ...v }));

function ligado(chave) {
    return features.value[chave] === true;
}

/*
 * O nome da feature é fixo — a chave ao lado já diz se está ligada. A frase
 * de apoio acompanha o estado: desligada, diz o que está acontecendo AGORA,
 * em vez de anunciar o que aconteceria se estivesse ligada.
 */
function descricaoDaFeature(feature) {
    return !ligado(feature.key) && feature.description_off ? feature.description_off : feature.description;
}

/*
 * Expandir e recolher, no padrão dos Ajustes: a lista fechada mostra o que
 * está ligado numa olhada, e o detalhe abre só onde a pessoa vai mexer.
 *
 * Tem detalhe só a feature ligada: desligada, não há o que ajustar.
 */
const expandidas = ref(new Set());

function temDetalhe(chave) {
    return ligado(chave);
}

const expandida = (chave) => temDetalhe(chave) && expandidas.value.has(chave);

function alternarExpansao(chave, abrir = !expandidas.value.has(chave)) {
    const copia = new Set(expandidas.value);
    abrir ? copia.add(chave) : copia.delete(chave);
    expandidas.value = copia;
}

function alternarFeature(chave, valor) {
    const anterior = ligado(chave);
    features.value = { ...features.value, [chave]: valor };

    // Ligou: o próximo passo é ajustar o detalhe, então ele já abre.
    if (valor) alternarExpansao(chave, true);

    const pendentes = new Set(featurePendente.value);
    pendentes.add(chave);
    featurePendente.value = pendentes;

    router.patch(route('admin.companies.customizations.update', props.company.id), {
        key: chave,
        enabled: valor,
    }, {
        preserveScroll: true,
        onError: () => (features.value = { ...features.value, [chave]: anterior }),
        onFinish: () => {
            const restantes = new Set(featurePendente.value);
            restantes.delete(chave);
            featurePendente.value = restantes;
        },
    });
}

// --- Campos para resgate (detalhe da feature `import_fields`) ---
/*
 * Espelho local, como o das chaves: a caixa marca NO CLIQUE e o servidor
 * confirma. Se a resposta vier com erro, volta ao que era.
 */
const colunas = ref([...(props.company.import_columns ?? [])]);
const colunasPendente = ref(false);

watch(() => props.company.import_columns, (v) => (colunas.value = [...(v ?? [])]));

function colunaMarcada(chave) {
    return colunas.value.includes(chave);
}

function alternarColuna(chave, marcada) {
    const anterior = [...colunas.value];
    colunas.value = marcada ? [...colunas.value, chave] : colunas.value.filter((c) => c !== chave);
    colunasPendente.value = true;

    router.put(route('admin.companies.import-columns.update', props.company.id), { columns: colunas.value }, {
        preserveScroll: true,
        onError: () => (colunas.value = anterior),
        onFinish: () => (colunasPendente.value = false),
    });
}

// --- Estúdio de mensagens (detalhe da feature `welog_sends_emails`) ---
// Um modal de cada vez: o Estúdio toma o lugar das Customizações e, ao
// fechar, devolve a pessoa a elas.
const estudioOpen = ref(false);

function abrirEstudio() {
    customOpen.value = false;
    estudioOpen.value = true;
}

function fecharEstudio() {
    estudioOpen.value = false;
    customOpen.value = true;
    // O Estúdio grava por fora das props desta tela: a contagem de etapas
    // enviadas precisa ser relida.
    router.reload({ only: ['company'] });
}

// --- E-mail de atendimento (detalhe da feature `portal_support`) ---
// Um campo de texto, e por isso com "Salvar": gravar a cada tecla mandaria
// endereços pela metade para o portal.
const emailAtendimento = useForm({ email: props.company.support_email ?? '' });

watch(
    () => props.company.support_email,
    (v) => emailAtendimento.defaults({ email: v ?? '' }).reset(),
);

function salvarEmailDeAtendimento() {
    emailAtendimento.put(route('admin.companies.support-email.update', props.company.id), {
        preserveScroll: true,
    });
}

// --- Página de primeiro acesso (detalhe da feature `first_access_page`) ---
// Como o Estúdio: os campos e a prévia num modal largo, que toma o lugar das
// Customizações e, ao fechar, devolve a pessoa a elas.
const paginaOpen = ref(false);

function abrirPagina() {
    customOpen.value = false;
    paginaOpen.value = true;
}

function fecharPagina() {
    paginaOpen.value = false;
    customOpen.value = true;
}

// --- Logo (detalhe da feature `project_logo`) ---
/*
 * Onde a logo aparece: no topo das telas do Cliente, ao lado do nome de cada
 * Projeto, ou nos dois. Mesmo espelho local das chaves.
 */
const niveis = ref([...(props.company.logo_levels ?? [])]);
const niveisPendente = ref(false);

watch(() => props.company.logo_levels, (v) => (niveis.value = [...(v ?? [])]));

const nivelMarcado = (chave) => niveis.value.includes(chave);

const descricaoDoNivel = (chave) => props.logo_levels_catalog.find((n) => n.key === chave)?.description;

function alternarNivel(chave, marcado) {
    const anterior = [...niveis.value];
    niveis.value = marcado ? [...niveis.value, chave] : niveis.value.filter((n) => n !== chave);
    niveisPendente.value = true;

    router.put(route('admin.companies.logo-levels.update', props.company.id), { levels: niveis.value }, {
        preserveScroll: true,
        onError: () => (niveis.value = anterior),
        onFinish: () => (niveisPendente.value = false),
    });
}

/* `useRowAction` não serve aqui: ele monta a própria chamada a partir de uma
   URL e não carrega arquivo. O pendente por dono ('empresa' ou o id do
   Projeto) fica neste Set. */
const logoEnviando = ref(new Set());
const logoErro = ref(null);

function marcarEnviando(chave, ativo) {
    const copia = new Set(logoEnviando.value);
    ativo ? copia.add(chave) : copia.delete(chave);
    logoEnviando.value = copia;
}

function enviarLogo(url, chave, arquivo) {
    logoErro.value = null;

    router.post(url, { logo: arquivo }, {
        forceFormData: true,
        preserveScroll: true,
        // O modal segue aberto: quem sobe a logo de um Projeto normalmente
        // sobe a do seguinte, e fechar a cada envio obrigaria a reabrir.
        onStart: () => marcarEnviando(chave, true),
        onFinish: () => marcarEnviando(chave, false),
        onError: (errors) => (logoErro.value = errors.logo ?? 'Não foi possível enviar a logo.'),
    });
}

function removerLogo(url, chave) {
    logoErro.value = null;

    router.delete(url, {
        preserveScroll: true,
        onStart: () => marcarEnviando(chave, true),
        onFinish: () => marcarEnviando(chave, false),
    });
}

// Reenvio do convite: o e-mail pode falhar/expirar e o WeGift precisa
// disparar de novo sem recriar o usuário. Estado por linha (useRowAction).
const adminAction = useRowAction();
const toast = useToast();

function resendAdminInvite(admin) {
    adminAction.run(admin.id, route('admin.companies.admins.resend', [props.company.id, admin.id]), {
        errorKey: 'invite',
        onError: (errors) => toast.warning(errors.invite),
    });
}

// Tirar um Admin Cliente: ele vê todos os Projetos da empresa, então sai de
// todos. Sem histórico a conta é apagada; com histórico, desativada.
const confirm = useConfirm();

const adminMenu = (admin) => [
    {
        label: 'Remover da empresa',
        icon: 'trash',
        danger: true,
        onSelect: () => removerAdmin(admin),
    },
];

async function removerAdmin(admin) {
    const ultimo = props.company.admins.length === 1;

    await confirm({
        title: `Remover ${admin.name} da ${props.company.name}?`,
        description: 'A pessoa perde o acesso a todos os Projetos da empresa.'
            + (ultimo ? ' A empresa fica sem Admin Cliente e ninguém de lá cadastra Colaboradores.' : '')
            + ' Para ela seguir em alguns Projetos, cadastre-a depois como Admin Projeto em cada um.',
        confirmLabel: 'Remover da empresa',
        variant: 'danger',
        errorBag: 'remove',
        action: () => router.delete(route('admin.companies.admins.destroy', [props.company.id, admin.id]), {
            preserveScroll: true,
        }),
    });
}
</script>

<template>
    <Head :title="company.name" />

    <PageHeader
        :title="company.name"
        :subtitle="company.trade_name"
        :back="{ label: 'Clientes', href: route('admin.companies.index') }"
    >
        <template #actions>
            <UiRefreshButton />
            <UiButton variant="secondary" icon="plus" @click="openAdmin">Adicionar Admin Cliente</UiButton>
            <UiButton @click="openEdit">
                {{ company.missing_fields.length ? 'Completar cadastro' : 'Editar cadastro' }}
            </UiButton>
        </template>
    </PageHeader>

    <!-- Sem Admin Cliente a empresa está parada: ninguém lá dentro consegue
         cadastrar Colaborador, e sem Colaborador não há resgate. É o primeiro
         bloco da página por isso, e não por ordem de cadastro. -->
    <UiAlert v-if="semAdmin" variant="danger" title="Esta Empresa ainda não tem Admin Cliente">
        Sem Admin Cliente, ninguém da {{ company.name }} consegue cadastrar Colaboradores. E sem
        Colaborador cadastrado, nenhum kit pode ser resgatado.
        <template #actions>
            <UiButton size="sm" icon="plus" @click="openAdmin">Adicionar Admin Cliente</UiButton>
        </template>
    </UiAlert>

    <UiAlert v-if="company.missing_fields.length" variant="warning" :class="semAdmin && 'mt-4'">
        Falta {{ missingList(company.missing_fields) }} no cadastro desta Empresa.
        <template #actions>
            <UiButton size="sm" variant="secondary" @click="openEdit">Completar cadastro</UiButton>
        </template>
    </UiAlert>

    <section
        aria-label="Indicadores da Empresa"
        :class="[
            'grid gap-4 sm:grid-cols-2 xl:grid-cols-4',
            (semAdmin || company.missing_fields.length) && 'mt-5',
        ]"
    >
        <!-- Chapa no primeiro, como nas demais faixas: é a entrada da fila. -->
        <KpiCard v-for="(kpi, index) in kpis" :key="kpi.label" v-bind="kpi" :filled="index === 0" />
    </section>

    <UiCard title="Dados cadastrais" class="mt-5">
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div v-for="item in dados" :key="item.label" class="min-w-0">
                <dt class="text-xs tracking-wide text-ink-muted uppercase">{{ item.label }}</dt>
                <dd class="mt-0.5 text-sm text-ink">
                    <UiCode v-if="item.label === 'CNPJ'" :value="item.value" copyable />
                    <template v-else-if="item.value">{{ item.value }}</template>
                    <span v-else class="text-ink-subtle">Não informado</span>
                </dd>
            </div>
        </dl>
    </UiCard>

    <UiCard class="mt-5" padding="none">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
            <div>
                <!-- Sem a contagem ao lado do título: quem são está logo
                     abaixo, nomeado. O número só repetia em algarismo o que a
                     lista já mostra por extenso. -->
                <h2 class="text-sm font-semibold text-ink">Admin Cliente</h2>
                <p class="mt-0.5 text-sm text-ink-muted">
                    Quem, dentro da Empresa, cadastra os Colaboradores e acompanha os Projetos.
                </p>
            </div>

            <UiButton size="sm" variant="secondary" icon="plus" @click="openAdmin">
                Adicionar Admin Cliente
            </UiButton>
        </div>

        <ul v-if="company.admins.length" class="divide-y divide-line">
            <!--
                Nome e e-mail eram dois filhos de um `flex-wrap`, com o e-mail
                tomando o espaço livre. Em tela estreita a linha quebrava e as
                duas metades da MESMA pessoa viravam dois itens sem relação
                aparente. Agora são um bloco só — inicial, nome e e-mail
                empilhados — e o que sobra à direita é estado e ação.
            -->
            <li
                v-for="admin in company.admins"
                :key="admin.id"
                class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 text-sm"
            >
                <span
                    aria-hidden="true"
                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700"
                >
                    {{ iniciais(admin.name) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium text-ink">{{ admin.name }}</span>
                    <span class="block truncate text-ink-muted">{{ admin.email }}</span>
                </span>
                <UiBadge :variant="admin.access_pending ? 'warning' : 'success'">
                    {{ admin.access_pending ? 'Convite pendente' : 'Acesso ativo' }}
                </UiBadge>
                <UiButton
                    v-if="admin.access_pending"
                    size="sm"
                    variant="secondary"
                    icon="emails"
                    :loading="adminAction.pending(admin.id)"
                    @click="resendAdminInvite(admin)"
                >
                    Reenviar convite
                </UiButton>
                <UiMenu :items="adminMenu(admin)" :label="`Ações de ${admin.name}`" />
            </li>
        </ul>

        <div v-else class="px-4 py-5">
            <UiEmpty
                icon="users"
                message="Nenhum Admin Cliente cadastrado. Esta Empresa não consegue operar sem pelo menos um."
            >
                <template #action>
                    <UiButton icon="plus" @click="openAdmin">Adicionar Admin Cliente</UiButton>
                </template>
            </UiEmpty>
        </div>
    </UiCard>

    <!--
        A lista de Colaboradores saiu desta tela (pedido do cliente): quem
        resgata é consultado DENTRO do Projeto, que é onde se vê qual kit cada
        pessoa pode pegar. Aqui ela era uma lista da Empresa inteira, sem esse
        vínculo — respondia "quem existe", nunca "quem pega o quê". O
        indicador do topo continua, porque "esta Empresa já tem gente
        cadastrada?" é pergunta de cliente, não de Projeto.
    -->
    <UiCard class="mt-5" padding="none">
        <div class="flex items-center justify-between gap-4 border-b border-line px-4 py-3">
            <h2 class="text-sm font-semibold text-ink">
                Projetos
                <span class="tabular-nums text-ink-muted">({{ projects.length }})</span>
            </h2>
        </div>

        <UiTable
            :columns="projectColumns"
            caption="Projetos da Empresa, com etapa, prazo e quantos Colaboradores e Resgates cada um tem"
        >
            <tr v-for="project in projects" :key="project.id">
                <th scope="row" class="font-normal">
                    <UiRowLink :href="route('projects.show', project.id)">{{ project.name }}</UiRowLink>
                </th>
                <td>{{ kitLabel(project) ?? '-' }}</td>
                <td><StatusBadge :status="project.pipeline_status" /></td>
                <td>{{ project.delivery_deadline ? formatDate(project.delivery_deadline) : '-' }}</td>
                <td>{{ project.end_customers_count }}</td>
                <td>{{ project.redemptions_count }}</td>
            </tr>

            <template #empty>
                <UiEmpty
                    icon="projects"
                    message="Nenhum Projeto para esta Empresa. Eles nascem do Pedido de Venda na Omie."
                />
            </template>
        </UiTable>
    </UiCard>

    <!--
        Customizações fecha a página: é o que se ajusta DEPOIS que o cadastro,
        os Projetos e as pessoas já estão de pé. Um card-porta, não um
        formulário — o conteúdo vive no modal.

        A porta é a engrenagem sozinha, sem rótulo em botão: "Abrir
        customizações" escrito num botão de contorno pesava mais que o próprio
        título da seção e disputava a leitura com "Adicionar Admin Cliente", que
        é ação de verdade. Aqui é só um alvo discreto, no canto, com o nome da
        seção já dizendo o que há atrás.
    -->
    <UiCard class="mt-5" padding="none">
        <div class="flex items-center justify-between gap-3 px-4 py-3">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-ink">Customizações</h2>
                <p class="mt-0.5 text-sm text-ink-muted">
                    Logo, e-mails aos Colaboradores e campos para resgate. Só o Admin WeGift altera.
                </p>
            </div>

            <button
                type="button"
                class="flex size-9 shrink-0 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-surface-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                aria-label="Abrir customizações"
                title="Abrir customizações"
                @click="customOpen = true"
            >
                <UiIcon name="settings" :size="18" />
            </button>
        </div>
    </UiCard>

    <!--
        Lista de ajustes, no padrão dos Ajustes da Apple: UM grupo branco, uma
        linha por feature separada por um fio, com ícone, nome, uma frase curta
        do estado atual e a chave. A seta expande o detalhe NA PRÓPRIA linha,
        no mesmo branco e alinhado ao texto — fundo diferente fazia o detalhe
        parecer outra feature.

        A lista é gerada do catálogo do servidor: feature nova nasce no model e
        aparece aqui sem passar por este arquivo.
    -->
    <UiModal
        v-model:open="customOpen"
        :title="`Customizações · ${company.name}`"
        description="Cada ajuste é salvo na hora."
        size="md"
    >
        <UiAlert v-if="logoErro" variant="danger" class="mb-3">{{ logoErro }}</UiAlert>

        <ul class="divide-y divide-line overflow-hidden rounded-card border border-line">
            <li v-for="feature in customization_catalog" :key="feature.key">
                <div class="flex items-center gap-3 px-4 py-3">
                    <span
                        aria-hidden="true"
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700"
                    >
                        <UiIcon :name="feature.icon ?? 'settings'" :size="16" />
                    </span>

                    <!-- O texto também expande: alvo grande, como a linha
                         inteira de um ajuste. -->
                    <button
                        type="button"
                        class="min-w-0 flex-1 text-left disabled:cursor-default"
                        :disabled="!temDetalhe(feature.key)"
                        :aria-expanded="temDetalhe(feature.key) ? expandida(feature.key) : undefined"
                        :aria-controls="`detalhe-${feature.key}`"
                        @click="alternarExpansao(feature.key)"
                    >
                        <span class="block text-sm font-medium text-ink">{{ feature.label }}</span>
                        <span :id="`custom-${feature.key}`" class="block truncate text-xs text-ink-muted">
                            {{ descricaoDaFeature(feature) }}
                        </span>
                    </button>

                    <UiToggle
                        :model-value="ligado(feature.key)"
                        :label="feature.label"
                        :described-by="`custom-${feature.key}`"
                        :loading="featurePendente.has(feature.key)"
                        :state-label="false"
                        @update:model-value="alternarFeature(feature.key, $event)"
                    />

                    <!-- A seta ocupa o lugar mesmo sem detalhe: as chaves
                         ficam todas na mesma coluna. -->
                    <button
                        v-if="temDetalhe(feature.key)"
                        type="button"
                        class="-mr-1.5 flex size-7 shrink-0 items-center justify-center rounded-full text-ink-subtle transition-colors hover:bg-surface-muted hover:text-ink"
                        :aria-label="`${expandida(feature.key) ? 'Recolher' : 'Expandir'} ${feature.label}`"
                        :aria-expanded="expandida(feature.key)"
                        :aria-controls="`detalhe-${feature.key}`"
                        @click="alternarExpansao(feature.key)"
                    >
                        <UiIcon
                            name="chevronDown"
                            :size="16"
                            :class="['transition-transform duration-200', expandida(feature.key) && 'rotate-180']"
                        />
                    </button>
                    <span v-else aria-hidden="true" class="-mr-1.5 size-7 shrink-0" />
                </div>

                <!-- Detalhe: alinhado ao texto da linha (16 + 32 + 12 = 60px). -->
                <div v-if="expandida(feature.key)" :id="`detalhe-${feature.key}`" class="pr-4 pb-4 pl-15">
                    <!-- ── Logo ──────────────────────────────────────────── -->
                    <!--
                        Os níveis com a MESMA forma: o check e, embaixo, a logo
                        alinhada ao texto dele (16 + 10 = 26px). Assim as
                        miniaturas caem todas na mesma coluna. Projeto e
                        Colaborador usam a mesma imagem — a de cada Projeto —,
                        então a lista delas vem uma vez, depois dos dois checks.
                    -->
                    <div v-if="feature.key === 'project_logo'" class="space-y-1">
                        <div>
                            <UiCheckbox
                                :model-value="nivelMarcado('cliente')"
                                :disabled="niveisPendente"
                                @update:model-value="alternarNivel('cliente', $event)"
                            >
                                Cliente
                                <span class="text-ink-muted"> · {{ descricaoDoNivel('cliente') }}</span>
                            </UiCheckbox>

                            <div v-if="nivelMarcado('cliente')" class="pb-1 pl-6.5">
                                <LogoTile
                                    :name="company.display_name || company.name"
                                    :url="company.logo_url"
                                    :busy="logoEnviando.has('empresa')"
                                    @pick="enviarLogo(route('admin.companies.logo.store', company.id), 'empresa', $event)"
                                    @remove="removerLogo(route('admin.companies.logo.destroy', company.id), 'empresa')"
                                />
                            </div>
                        </div>

                        <div>
                            <UiCheckbox
                                :model-value="nivelMarcado('projeto')"
                                :disabled="niveisPendente"
                                @update:model-value="alternarNivel('projeto', $event)"
                            >
                                Projeto
                                <span class="text-ink-muted"> · {{ descricaoDoNivel('projeto') }}</span>
                            </UiCheckbox>

                            <UiCheckbox
                                :model-value="nivelMarcado('colaborador')"
                                :disabled="niveisPendente"
                                @update:model-value="alternarNivel('colaborador', $event)"
                            >
                                Colaborador
                                <span class="text-ink-muted"> · {{ descricaoDoNivel('colaborador') }}</span>
                            </UiCheckbox>

                            <template v-if="nivelMarcado('projeto') || nivelMarcado('colaborador')">
                                <p v-if="!projects.length" class="pl-6.5 text-xs text-ink-muted">
                                    Nenhum Projeto ainda. Eles nascem do Pedido de Venda na Omie.
                                </p>
                                <ul v-else class="space-y-2 pl-6.5">
                                    <li v-for="project in projects" :key="project.id">
                                        <LogoTile
                                            :name="project.name"
                                            :url="project.logo_url"
                                            :busy="logoEnviando.has(project.id)"
                                            @pick="enviarLogo(route('projects.logo.store', project.id), project.id, $event)"
                                            @remove="removerLogo(route('projects.logo.destroy', project.id), project.id)"
                                        />
                                    </li>
                                </ul>
                            </template>
                        </div>
                    </div>

                    <!-- ── Atendimento no portal ─────────────────────────── -->
                    <form
                        v-else-if="feature.key === 'portal_support'"
                        @submit.prevent="salvarEmailDeAtendimento"
                    >
                        <p class="text-xs font-medium text-ink" aria-hidden="true">E-mail de atendimento</p>
                        <div class="mt-1.5 flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <UiInput
                                    v-model="emailAtendimento.email"
                                    aria-label="E-mail de atendimento"
                                    type="email"
                                    size="sm"
                                    autocomplete="off"
                                    placeholder="atendimento@wegift.com.br"
                                    :invalid="!!emailAtendimento.errors.email"
                                />
                            </div>
                            <UiButton
                                type="submit"
                                variant="secondary"
                                size="sm"
                                :loading="emailAtendimento.processing"
                                :disabled="!emailAtendimento.isDirty"
                            >
                                Salvar
                            </UiButton>
                        </div>
                        <p v-if="emailAtendimento.errors.email" class="mt-1.5 text-xs text-danger">
                            {{ emailAtendimento.errors.email }}
                        </p>
                        <p v-else class="mt-1.5 text-xs text-ink-muted">
                            Aparece no topo de todas as telas do portal do Colaborador.
                        </p>
                    </form>

                    <!-- ── Página de primeiro acesso ─────────────────────── -->
                    <div
                        v-else-if="feature.key === 'first_access_page'"
                        class="flex items-center justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-xs font-medium text-ink">
                                /primeiro-acesso/{{ company.first_access?.slug }}
                            </p>
                            <p class="text-xs text-ink-muted">
                                {{ company.first_access?.dica ? 'Aviso próprio' : 'Aviso padrão' }}
                            </p>
                        </div>
                        <UiButton variant="secondary" size="sm" @click="abrirPagina">Personalizar</UiButton>
                    </div>

                    <!-- ── E-mails: o atalho para o Estúdio de mensagens ─── -->
                    <!-- O texto e o envio de cada etapa se ajustam no Estúdio.
                         O link de primeiro acesso não mora mais aqui: ele é
                         fixo por Cliente e aparece na tela do Projeto, onde o
                         Cliente o usa. -->
                    <div
                        v-else-if="feature.key === 'welog_sends_emails'"
                        class="flex items-center justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-ink">Mensagens do resgate</p>
                            <p class="text-xs text-ink-muted">
                                {{ company.etapas_ligadas }} de {{ company.etapas_total }} etapas enviadas
                            </p>
                        </div>
                        <UiButton variant="secondary" size="sm" @click="abrirEstudio">Personalizar</UiButton>
                    </div>

                    <!-- ── Campos para resgate ───────────────────────────── -->
                    <fieldset v-else-if="feature.key === 'import_fields'">
                        <legend class="sr-only">Campos coletados</legend>
                        <div class="flex flex-wrap gap-x-5">
                            <UiCheckbox
                                v-for="coluna in import_columns_catalog"
                                :key="coluna.key"
                                :model-value="coluna.locked || colunaMarcada(coluna.key)"
                                :label="coluna.label"
                                :disabled="coluna.locked || colunasPendente"
                                @update:model-value="alternarColuna(coluna.key, $event)"
                            />
                        </div>
                        <p class="text-xs text-ink-muted">
                            Desmarcados saem da planilha e do resgate.
                        </p>
                    </fieldset>
                </div>
            </li>
        </ul>

        <template #footer>
            <UiButton variant="secondary" @click="customOpen = false">Fechar</UiButton>
        </template>
    </UiModal>

    <!-- Estúdio de mensagens, aberto a partir das Customizações. Largo: a
         jornada, o rascunho e a prévia lado a lado. Fechar devolve às
         Customizações, que é de onde a pessoa veio. -->
    <UiModal
        :open="estudioOpen"
        :title="`Mensagens do resgate · ${company.name}`"
        description="O que os Colaboradores recebem em cada etapa, na voz do Cliente."
        size="xl"
        @update:open="(aberto) => { if (!aberto) fecharEstudio(); }"
    >
        <MessageStudio v-if="estudioOpen" :company-id="company.id" />
    </UiModal>

    <!-- Página de primeiro acesso, no mesmo arranjo: os campos e, embaixo, a
         página como o colaborador vai abri-la. -->
    <UiModal
        :open="paginaOpen"
        :title="`Página de primeiro acesso · ${company.name}`"
        description="Onde o colaborador pede o link para criar a senha."
        size="xl"
        @update:open="(aberto) => { if (!aberto) fecharPagina(); }"
    >
        <FirstAccessStudio v-if="paginaOpen" :company="company" />
    </UiModal>

    <UiDrawer
        v-model:open="editOpen"
        :title="company.name"
        :description="`${formatDocument(company.cnpj)}${company.omie_customer_code ? ' · criada pela Omie' : ''}`"
    >
        <form id="form-empresa" @submit.prevent="saveCompany">
            <CompanyForm :form="companyForm" :missing="company.missing_fields" />
        </form>

        <template #footer>
            <UiButton type="submit" form="form-empresa" :loading="companyForm.processing">
                Salvar empresa
            </UiButton>
            <UiButton variant="ghost" @click="editOpen = false">Cancelar</UiButton>
        </template>
    </UiDrawer>

    <UiDrawer
        v-model:open="adminOpen"
        :title="`Novo Admin Cliente · ${company.name}`"
        :description="`Ela recebe um convite por e-mail. Ao definir a senha, passa a ver apenas os Projetos da ${company.name} e a cadastrar os Colaboradores dessa Empresa.`"
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
</template>
