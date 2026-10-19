<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import ImportProgressDetails from '../../Components/ImportProgressDetails.vue';
import PortalScreenStudio from '../../Components/PortalScreenStudio.vue';
import ProductGalleryManager from '../../Components/ProductGalleryManager.vue';
import AdminRedemptionDrawer from '../../Components/AdminRedemptionDrawer.vue';
import ProjectAdminDrawer from '../../Components/ProjectAdminDrawer.vue';
import ProjectItemsChoice from '../../Components/ProjectItemsChoice.vue';
import { agruparPorGrade, compararOrdem } from '../../lib/grade';
import ProjectItemThumb from '../../Components/ProjectItemThumb.vue';
import ProjectRequestDrawer from '../../Components/ProjectRequestDrawer.vue';
import ProjectTimeline from '../../Components/ProjectTimeline.vue';
import ShipControl from '../../Components/ShipControl.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiBadge from '../../Components/UiBadge.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCard from '../../Components/UiCard.vue';
import UiCode from '../../Components/UiCode.vue';
import UiDrawer from '../../Components/UiDrawer.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiField from '../../Components/UiField.vue';
import UiFileDrop from '../../Components/UiFileDrop.vue';
import UiFormHeading from '../../Components/UiFormHeading.vue';
import UiFormSection from '../../Components/UiFormSection.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiInput from '../../Components/UiInput.vue';
import UiMenu from '../../Components/UiMenu.vue';
import UiModal from '../../Components/UiModal.vue';
import UiPagination from '../../Components/UiPagination.vue';
import UiPanel from '../../Components/UiPanel.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiRowErrors from '../../Components/UiRowErrors.vue';
import UiSearchInput from '../../Components/UiSearchInput.vue';
import UiSelect from '../../Components/UiSelect.vue';
import UiTable from '../../Components/UiTable.vue';
import UiTabs from '../../Components/UiTabs.vue';
import UiTooltip from '../../Components/UiTooltip.vue';
import { useCarriers } from '../../composables/useCarriers';
import { useConfirm } from '../../composables/useConfirm';
import { useRowAction } from '../../composables/useRowAction';
import { useTabQuery } from '../../composables/useTabQuery';
import { useToast } from '../../composables/useToast';
import {
    STAGE_IN_STOCK,
    deadlineState,
    formatDocument,
    formatDate,
    formatQuantity,
    maskCpf,
    maskPhone,
    pipelineIcons,
    pipelineLabels,
    pipelineStages,
} from '../../lib/format';

const props = defineProps({
    project: { type: Object, required: true },
    // Mantido pelo backend por compatibilidade; a tela usa project.products,
    // que traz foto, código e is_kit dos itens DO PEDIDO.
    kits: { type: Array, default: () => [] },
    permissions: { type: Object, required: true },
    // Importação por planilha em andamento (ou a última, por uma hora).
    // Planilha grande é cadastrada na fila, não no upload.
    import: { type: Object, default: null },
    // As duas listas chegam uma página por vez (ver recarregarLista).
    colaboradores: { type: Object, default: () => ({ data: [], total: 0, pagina: 1, por_pagina: 25, busca: '' }) },
    resgates: { type: Object, default: () => ({ data: [], total: 0, pagina: 1, por_pagina: 25 }) },
    // Ids de TODOS os resgates em transporte: a seleção em massa atravessa as páginas.
    em_transporte: { type: Array, default: () => [] },
});

const page = usePage();

/*
 * Colaboradores e Resgates chegam uma página por vez, já filtrados pelo
 * servidor. Num Projeto de nove mil pessoas a lista inteira eram megabytes a
 * cada abertura e a cada volta para a tela. Busca e página moram na
 * querystring (?busca=, ?pagina=, ?pagina_resgates=): recarregar, voltar pelo
 * navegador ou terminar uma ação devolve a mesma página.
 */
const recarregandoLista = ref(null);

function recarregarLista(prop, parametros) {
    const url = new URL(page.url, window.location.origin);

    for (const [chave, valor] of Object.entries(parametros)) {
        // Página 1 e busca vazia são o padrão: ficam fora da URL.
        if (valor === null || valor === '' || valor === 1) url.searchParams.delete(chave);
        else url.searchParams.set(chave, valor);
    }

    router.visit(`${url.pathname}${url.search}`, {
        only: [prop, ...(prop === 'resgates' ? ['em_transporte'] : [])],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (recarregandoLista.value = prop),
        onFinish: () => (recarregandoLista.value = null),
    });
}

const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
const displayTitle = computed(() => props.project.name.replace(/\s+[—–-]\s+/g, ' '));

/*
 * O título é "69895 - Template - Amostra": o número do pedido MAIS o nome que
 * a operação deu à campanha no campo "Nº do pedido do cliente" da Omie.
 *
 * Era só "Projeto 69895". O número sozinho não diz do que se trata, e o nome
 * sozinho se repete entre clientes ("Kits de Natal"); juntos, respondem qual
 * pedido é e o que ele é, sem depender do resumo abaixo.
 */
const projectHeading = computed(() => {
    const numero = props.project.omie_order_number;
    const campanha = (props.project.client_order_number ?? '').trim();

    if (!numero) return displayTitle.value;

    return campanha ? `${numero} - ${campanha}` : `Projeto ${numero}`;
});

// ── Abas ────────────────────────────────────────────────────────────────────
// O estado vive na querystring: sem isso o link com `?aba=resgates` (vindo da
// lista de Resgates) cairia sempre na primeira aba.
/*
 * A aba Resgates é da operação: é onde a WeGift expede e marca entregue. O
 * lado do Cliente só lia ali o que o Acompanhamento mostra melhor (filtros,
 * quem falta, rastreio, exportação), e a aba saiu para ele.
 */
const abaDeResgates = !props.permissions.client;

const aba = useTabQuery('aba', 'clientes', {
    allowed: ['admins', 'clientes', 'kits', ...(abaDeResgates ? ['resgates'] : [])],
});

const { carrierLabel, trackingLink } = useCarriers();

// ── Aba Colaboradores: uma linha por pessoa ─────────────────────────────────
/*
 * Uma pessoa pode ter itens garantidos e um ou mais grupos "escolhe 1 de N".
 * A lista é de GENTE: uma linha por pessoa, com o que ela tem a resgatar num
 * resumo curto ("2 itens + 1 de 3"). Todos os contadores de gente passam por
 * aqui — senão "3 pessoas" contaria três itens de um colaborador só.
 *
 * As linhas já chegam prontas do servidor (colaboradoresDoProjeto): montá-las
 * aqui, a partir de cada autorização, era receber dezenas de milhares de
 * registros num Projeto grande, e a página estourou a memória do servidor.
 * Cada linha: user, itens, itemsLabel, itemsTitle, primary, unidades,
 * resgatadas, redeemed, access_pending, notified_at, created_at.
 */
const customerRows = computed(() => props.colaboradores.data);

// Os números da lista inteira, contados no servidor (a página tem só 25).
const pessoas = computed(() => props.project.pessoas);

// ── Badge da aba de itens: "N kits, M itens" (is_kit separa) ────────────────
const itemsBadge = computed(() => {
    const kitsCount = props.project.products.filter((p) => p.is_kit).length;
    const looseCount = props.project.products.length - kitsCount;
    const parts = [];

    if (kitsCount) parts.push(plural(kitsCount, 'kit', 'kits'));
    if (looseCount) parts.push(plural(looseCount, 'item', 'itens'));

    return parts.length ? parts.join(', ') : '0 itens';
});

/*
 * Ordem pedida pela operação: Admin, Colaboradores, Itens, Resgates.
 *
 * É a ordem em que um Projeto ganha vida — quem administra, quem recebe, o
 * que vai no kit, o que já saiu —, e separa de vez o que antes se misturava
 * numa aba só: admin e colaborador eram "usuários".
 */
const tabs = computed(() => [
    { key: 'admins', label: 'Admin', icon: 'users', count: adminRows.value.length },
    { key: 'clientes', label: 'Colaboradores', icon: 'users', count: pessoas.value.total },
    { key: 'kits', label: 'Itens do pedido', icon: 'catalog', count: itemsBadge.value },
    ...(abaDeResgates
        ? [{ key: 'resgates', label: 'Resgates', icon: 'gift', count: props.project.resgates_resumo.total }]
        : []),
]);

const tabId = (key) => `projeto-aba-${key}`;
const panelId = (key) => `projeto-${key}`;

// ── Cabeçalho e faixa de metadados ─────────────────────────────────────────
const stageLabel = computed(() => pipelineLabels[props.project.pipeline_status] ?? props.project.pipeline_status);
const stageIcon = computed(() => pipelineIcons[props.project.pipeline_status] ?? 'projects');
/** Estado do prazo (Atrasado N dias / Vence hoje...), com o tom da lista. */
const prazo = computed(() => deadlineState(props.project.delivery_deadline));

/** Tooltip do prazo na visão do cliente: o prazo é estimativa, não contrato. */
const deadlineTooltip = 'Entrega sujeita a ajustes de prazo';

// ── Recarga automática (falha F10 do UAT) ───────────────────────────────────
// Webhook do Trello e sinal da Omie mudam o banco sem avisar a SPA aberta: a
// pessoa via a etapa velha e o botão de liberar não aparecia. Ao voltar o foco
// para a janela, a prop `project` é recarregada — no máximo uma vez a cada
// 10s, porque alt-tab dispara focus e visibilitychange quase juntos.
const AUTO_REFRESH_MS = 10000;
const LISTAS_E_PROJETO = ['project', 'colaboradores', 'resgates', 'em_transporte'];
let lastAutoRefresh = Date.now();

function autoRefresh() {
    if (document.visibilityState === 'hidden') return;
    if (Date.now() - lastAutoRefresh < AUTO_REFRESH_MS) return;

    lastAutoRefresh = Date.now();
    router.reload({ only: LISTAS_E_PROJETO });
}

onMounted(() => {
    window.addEventListener('focus', autoRefresh);
    document.addEventListener('visibilitychange', autoRefresh);
});

onBeforeUnmount(() => {
    window.removeEventListener('focus', autoRefresh);
    document.removeEventListener('visibilitychange', autoRefresh);
});

// ── Ação 1: liberar ─────────────────────────────────────────────────────────
const personCount = computed(() => pessoas.value.total);
const pendingAccessCount = computed(() => pessoas.value.acesso_pendente);
const notifiedCount = computed(() => pessoas.value.notificadas);

const releaseLabel = 'Liberar Resgates';

/**
 * Sub-status DENTRO da etapa.
 *
 * "Estoque Disponível" cobre dois momentos que não se parecem: o resgate
 * fechado, esperando alguém liberar, e o resgate aberto, com as pessoas já
 * recebendo. A etapa sozinha não distingue, e era ela que respondia "posso
 * avisar meu pessoal?" — a pergunta errada para a resposta certa.
 *
 * Só existe a partir de Em Estoque: antes disso a etapa já basta (Design,
 * Produção, Montagem contam a história inteira sozinhas).
 */
const subStatus = computed(() => {
    const project = props.project;

    if (project.released) {
        return {
            label: 'Resgates Liberados',
            tone: 'text-success',
            hint: `Liberado em ${formatDate(project.released_at)} · `
                + `${plural(notifiedCount.value, 'pessoa notificada', 'pessoas notificadas')} por e-mail.`,
        };
    }

    // Sem `hint` aqui: o que a liberação faz já está escrito ao lado do botão,
    // debaixo do título. Repetir no card de etapa fazia a mesma instrução
    // aparecer duas vezes na mesma tela, e nenhuma delas onde se clica.
    if (project.in_stock) {
        return { label: 'Pendente de Liberação', tone: 'text-warning', hint: null };
    }

    return { label: null, tone: '', hint: null };
});

/**
 * A ação primária nunca some (P2): ou está ativa, ou desabilitada com o motivo
 * ao lado, ou substituída pelo registro do que já aconteceu.
 */
const release = computed(() => {
    const project = props.project;

    if (project.released) return { kind: 'done' };
    if (!props.permissions.release) return { kind: 'note' };

    /*
     * Quem destrava é o ESTOQUE, não o cadastro.
     *
     * Exigir um Colaborador cadastrado invertia a ordem do trabalho real: a
     * liberação é a decisão de que o kit pode sair, e quem entra na lista
     * depois — contratação nova, alguém que faltou na planilha — recebe o
     * aviso no momento do cadastro. Segurar a liberação por causa de uma lista
     * ainda vazia atrasava o Projeto por um dado que chega sozinho.
     */
    if (project.can_be_released) return { kind: 'ready' };

    return {
        kind: 'blocked',
        reason: `O Projeto está em ${pipelineLabels[project.pipeline_status] ?? project.pipeline_status}. `
            + `O resgate só abre quando ele chegar na etapa ${pipelineLabels[STAGE_IN_STOCK]}.`,
    };
});

/**
 * Situação do Projeto na linguagem do RH — três estados, sem etapa de
 * fábrica. Cada um diz o que ele faz agora, porque a dúvida dele nunca é
 * "onde está o kit", é "eu já posso avisar meu pessoal?".
 */
/*
 * Cliente com os e-mails de comunicação desligados: a WeLog não escreve para
 * ninguém, então "notificada" e "recebeu o e-mail" não descrevem nada — o RH
 * leria que a pessoa recebeu um aviso que nunca saiu. Ali quem avisa é o
 * próprio Cliente, pelo link de primeiro acesso, e a data que importa na
 * lista é a do cadastro.
 */
const enviaEmails = computed(() => props.project.company.sends_emails !== false);

const rhStatus = computed(() => {
    const project = props.project;
    const total = personCount.value;
    // "Já resgatou" é quem resgatou alguma coisa, mesmo tendo mais a resgatar.
    const redeemed = pessoas.value.resgataram;

    if (project.released) {
        return {
            tone: 'success',
            icon: 'gift',
            title: `Resgate aberto desde ${formatDate(project.released_at)}`,
            text: total
                ? `${redeemed} de ${plural(total, 'pessoa', 'pessoas')} ${redeemed === 1 ? 'já resgatou' : 'já resgataram'}. `
                    + (enviaEmails.value
                        ? 'Todas receberam o e-mail com o link de acesso.'
                        : 'Elas entram pelo link de primeiro acesso.')
                : 'Nenhuma pessoa cadastrada ainda.',
            // Com gente cadastrada, o próximo passo é acompanhar: o atalho
            // abre o Acompanhamento já no recorte deste Projeto.
            acompanhar: total > 0,
            next: total
                ? null
                // Com o resgate aberto, cadastrar já avisa: a pessoa recebe o
                // e-mail no ato, sem depender de ninguém reenviar.
                : (enviaEmails.value
                    ? 'cadastre as pessoas. Quem entrar agora recebe o aviso na hora do cadastro.'
                    : 'cadastre as pessoas e divulgue o link de primeiro acesso.'),
        };
    }

    if (project.in_stock) {
        return {
            tone: 'info',
            icon: 'clock',
            title: 'Kit pronto, aguardando a WeGift abrir o resgate',
            text: enviaEmails.value
                ? 'Quando a WeGift liberar, cada pessoa cadastrada recebe automaticamente o e-mail com o link para criar a senha e resgatar.'
                : 'Quando a WeGift liberar, as pessoas cadastradas já podem entrar pelo link de primeiro acesso e resgatar.',
            next: total
                ? 'confira se todo mundo está na lista abaixo. Não precisa fazer mais nada.'
                : 'cadastre as pessoas que vão receber (uma a uma ou por planilha).',
        };
    }

    return {
        tone: 'info',
        icon: 'projects',
        title: 'Kit em produção',
        text: enviaEmails.value
            ? 'A WeGift está produzindo. Ninguém recebe e-mail nem consegue resgatar por enquanto.'
            : 'A WeGift está produzindo. Ninguém consegue resgatar por enquanto.',
        next: total
            ? 'a lista abaixo já está pronta; é só aguardar.'
            : 'aproveite para cadastrar as pessoas que vão receber (uma a uma ou por planilha).',
    };
});

const releaseForm = useForm({});
const confirm = useConfirm();

async function releaseProject() {
    const total = personCount.value;
    const pendingAccess = pendingAccessCount.value;

    // Recusa do backend (inclusive "Sem saldo em estoque para liberar: ...")
    // cai no bag `release` e aparece dentro do diálogo, que permanece aberto.
    await confirm({
        title: `Liberar os Resgates de "${props.project.name}"?`,
        description: enviaEmails.value
            ? `${plural(total, 'pessoa receberá', 'pessoas receberão')} o e-mail de resgate agora. ` +
                'Esta ação não pode ser desfeita. Pessoas cadastradas depois da liberação não recebem o ' +
                'e-mail automaticamente.'
            // E-mails desligados: liberar abre o resgate, e quem avisa é o Cliente.
            : `${plural(total, 'pessoa passa', 'pessoas passam')} a poder resgatar pelo link de primeiro acesso. ` +
                'A WeLog não envia e-mails a este Cliente. Esta ação não pode ser desfeita.',
        warning: enviaEmails.value && pendingAccess
            ? `${plural(pendingAccess, 'dessas pessoas ainda não criou', 'dessas pessoas ainda não criaram')} a senha de acesso. O e-mail delas vai com o link para criar.`
            : null,
        confirmLabel: releaseLabel,
        errorBag: 'release',
        action: () => releaseForm.post(route('projects.release', props.project.id), { preserveScroll: true }),
    });
}

/**
 * Freio de mão do resgate.
 *
 * Só a WeGift: bloquear é da WeGift crítica (Admin WeGift e N1); desbloquear,
 * só do Admin WeGift. Por isso o diálogo avisa quem solta depois.
 */
const blockForm = useForm({ reason: '' });

async function blockRedemptions() {
    await confirm({
        title: `Bloquear os resgates de "${props.project.name}"?`,
        description:
            'As pessoas param de conseguir resgatar na hora, e as solicitações de kit ficam suspensas. '
            + 'Quem já resgatou não é afetado.',
        warning: 'Só o Admin WeGift desbloqueia depois.',
        confirmLabel: 'Bloquear resgates',
        variant: 'danger',
        errorBag: 'block',
        action: () => blockForm.post(route('projects.redemptions.block', props.project.id), { preserveScroll: true }),
    });
}

async function unblockRedemptions() {
    await confirm({
        title: `Desbloquear os resgates de "${props.project.name}"?`,
        description: 'As pessoas voltam a resgatar de onde pararam. Ninguém recebe e-mail novo.',
        confirmLabel: 'Desbloquear resgates',
        errorBag: 'block',
        action: () => blockForm.post(route('projects.redemptions.unblock', props.project.id), { preserveScroll: true }),
    });
}

// ── Exclusão do Projeto (só Admin WeGift) ───────────────────────────────────
const destroyForm = useForm({});

async function destroyProject() {
    const autorizacoes = props.project.entitlements_count;
    const resgates = props.project.resgates_resumo.total;

    // Recusa do backend cai no bag `destroy` e aparece dentro do diálogo.
    // Sucesso redireciona para a lista de Projetos com a mensagem de status.
    await confirm({
        title: `Excluir "${props.project.name}"?`,
        description:
            'Ação definitiva, sem volta: apaga ' +
            `${plural(autorizacoes, 'usuário autorizado', 'usuários autorizados')}, ` +
            `${plural(resgates, 'resgate', 'resgates')} e todo o histórico de rastreio deste Projeto. ` +
            'A empresa e as contas das pessoas não são apagadas.',
        warning: props.project.released && resgates > 0
            ? `Este Projeto já foi liberado. Inclui ${plural(resgates, 'resgate já confirmado', 'resgates já confirmados')}, com rastreio.`
            : null,
        confirmLabel: 'Excluir projeto',
        variant: 'danger',
        errorBag: 'destroy',
        action: () => destroyForm.delete(route('projects.destroy', props.project.id)),
    });
}

// ── Aba Colaboradores ─────────────────────────────────────────────────────
// A busca vai ao servidor (sem acento e sem caixa, no nome e no e-mail).
const customerQuery = ref(props.colaboradores.busca ?? '');

function buscarColaboradores(termo) {
    recarregarLista('colaboradores', { busca: String(termo ?? '').trim(), pagina: 1 });
}

const customerPage = computed({
    get: () => props.colaboradores.pagina,
    set: (pagina) => recarregarLista('colaboradores', { pagina }),
});

// Busca sempre à mão: com poucas linhas ela não atrapalha e a tela fica previsível.
const showCustomerSearch = computed(() => pessoas.value.total > 0);

const pagedCustomers = customerRows;

const customerCountLabel = computed(() => {
    const total = pessoas.value.total;
    const shown = props.colaboradores.total;

    return props.colaboradores.busca ? `mostrando ${shown} de ${total}` : plural(total, 'pessoa', 'pessoas');
});

const customerEmptyMessage = computed(() =>
    props.colaboradores.busca
        ? `Nenhum Colaborador para "${props.colaboradores.busca}".`
        : 'Nenhum Colaborador cadastrado ainda.',
);

const customerColumns = computed(() => {
    const columns = [
        { key: 'user', label: 'Nome / e-mail' },
        { key: 'items', label: 'Itens' },
        { key: 'situation', label: 'Situação' },
    ];

    if (props.permissions.manage_customers) {
        columns.push({ key: 'actions', label: 'Ações', srOnlyLabel: true });
    }

    return columns;
});

/** Precedência da §11.4 — a data fica como texto secundário. */
function situation(row) {
    if (row.redeemed) return 'Resgatou';
    // Com mais de uma coisa a resgatar, "resgatou" pela metade precisa do número.
    if (row.resgatadas > 0) return `Resgatou ${row.resgatadas} de ${row.unidades}`;
    if (row.access_pending) return 'Acesso pendente';
    if (!enviaEmails.value) return 'Aguardando resgate';
    if (row.notified_at) return `Notificada em ${formatDate(row.notified_at)}`;

    return 'Ainda não notificada';
}

function situationNote(row) {
    if (!enviaEmails.value) return row.created_at ? `Criado em ${formatDate(row.created_at)}` : null;

    // Nos dois últimos estados a notificação já é o próprio rótulo.
    if (!row.resgatadas && !row.access_pending) return null;

    return row.notified_at
        ? `notificada em ${formatDate(row.notified_at)}`
        : 'ainda não notificada';
}

const { run, pending } = useRowAction();
const toast = useToast();

/*
 * Copiar o link de primeiro acesso.
 *
 * O selo de "copiado" volta sozinho: sem ele, a pessoa não tem como saber se
 * o clique pegou, e clica de novo.
 */
const primeiroAcessoCopiado = ref(false);

async function copiarPrimeiroAcesso() {
    try {
        await navigator.clipboard.writeText(props.project.company.first_access_url);
        primeiroAcessoCopiado.value = true;
        setTimeout(() => (primeiroAcessoCopiado.value = false), 2000);
    } catch {
        // Área de transferência bloqueada: o endereço está na tela e dá para
        // selecionar à mão.
        toast.warning("Não foi possível copiar. Selecione o endereço e copie manualmente.");
    }
}


function resendInvite(row) {
    run(row.primary.id, route('projects.entitlements.resend', [props.project.id, row.primary.id]), {
        errorKey: 'invite',
        // Ação sem formulário: o bag `invite` vira aviso (§9.3).
        onError: (errors) => toast.warning(errors.invite ?? 'Não foi possível reenviar o convite.'),
    });
}

const customerMenu = (row) => [
    // O convite do Admin é o de acesso, e mora na aba Admin.
    ...(row.admin
        ? []
        : [{
            label: 'Reenviar convite',
            icon: 'emails',
            disabled: !row.access_pending,
            onSelect: () => resendInvite(row),
        }]),
    {
        label: row.admin ? 'Tirar o resgate' : 'Remover do Projeto',
        icon: 'trash',
        danger: true,
        separatorBefore: !row.admin,
        onSelect: () => removeCustomer(row),
    },
];


/**
 * Tira a pessoa deste Projeto. É o que o RH usa quando alguém sai da empresa:
 * a confirmação diz se a conta some junto ou se ela continua em outro Projeto,
 * porque as duas consequências são bem diferentes.
 */
async function removeCustomer(row) {
    const resgatou = row.resgatadas > 0;

    // Admin com resgate: sai da lista, mas continua administrando.
    if (row.admin) {
        await confirm({
            title: `Tirar o resgate de ${row.user.name}?`,
            description: 'A pessoa deixa de ter presente neste Projeto e continua como Admin.'
                + (resgatou ? ' O que ela já resgatou também é apagado, com o rastreio.' : ''),
            confirmLabel: 'Tirar o resgate',
            variant: 'danger',
            errorBag: 'remove',
            action: () => router.delete(route('projects.collaborators.destroy', [props.project.id, row.user.id]), {
                preserveScroll: true,
            }),
        });

        return;
    }

    await confirm({
        title: `Remover ${row.user.name} deste Projeto?`,
        description: (row.itens > 1
            ? `A pessoa deixa de ter os itens deste Projeto (${row.itemsLabel}).`
            : 'A pessoa deixa de ter o presente para resgatar aqui.')
            + (resgatou ? ' O que ela já resgatou também é apagado, com o rastreio.' : '')
            + ' Se ela participar de outro Projeto, a conta continua ativa lá.',
        confirmLabel: 'Remover do Projeto',
        variant: 'danger',
        errorBag: 'remove',
        // Uma chamada só, com todas as autorizações da pessoa: antes era uma
        // por item, em fila, e cada uma recarregava a página com seu aviso.
        action: () => router.delete(route('projects.collaborators.destroy', [props.project.id, row.user.id]), {
            preserveScroll: true,
        }),
    });
}

// ── Aba Resgates ────────────────────────────────────────────────────────────
const redemptionPage = computed({
    get: () => props.resgates.pagina,
    set: (pagina) => recarregarLista('resgates', { pagina_resgates: pagina }),
});

const redemptionColumns = computed(() => [
    ...(props.permissions.critical
        ? [{ key: 'selecao', label: 'Seleção', srOnlyLabel: true, width: '3rem' }]
        : []),
    { key: 'user', label: 'Colaborador / destino' },
    { key: 'product', label: 'Item' },
    { key: 'status', label: 'Situação' },
    { key: 'ship', label: 'Ação' },
]);

const pagedRedemptions = computed(() => props.resgates.data);

// ── Entrega em massa ────────────────────────────────────────────────────────
// A transportadora entrega a campanha inteira e avisa de uma vez. Aqui a
// seleção atravessa as páginas de propósito: o Projeto JÁ é o recorte, e
// obrigar a marcar dez por vez em três páginas é a mesma tarefa de antes com
// mais cliques.
const selecionados = ref(new Set());

// Os ids, não as linhas: a página à vista tem só 25 deles.
const emTransporte = computed(() => props.em_transporte);

const marcado = (id) => selecionados.value.has(id);

function alternarResgate(id) {
    // Set novo a cada mudança: mutar o mesmo não dispara a reatividade.
    const proximo = new Set(selecionados.value);
    proximo.has(id) ? proximo.delete(id) : proximo.add(id);
    selecionados.value = proximo;
}

const todosMarcados = computed(
    () => emTransporte.value.length > 0 && emTransporte.value.every((id) => marcado(id)),
);

function alternarTodos() {
    selecionados.value = todosMarcados.value
        ? new Set()
        : new Set(emTransporte.value);
}

const totalMarcado = computed(() => selecionados.value.size);

const entregando = ref(false);

async function marcarEntregues() {
    const ids = [...selecionados.value];
    // O nome só está à mão quando a linha marcada é desta página.
    const nome = props.resgates.data.find((r) => r.id === ids[0])?.user;

    await confirm({
        title: ids.length === 1
            ? (nome ? `Marcar como entregue o resgate de ${nome}?` : 'Marcar 1 resgate como entregue?')
            : `Marcar ${ids.length} resgates como entregues?`,
        description: 'Cada pessoa recebe o e-mail "Pedido entregue!". Esta ação não pode ser desfeita.',
        confirmLabel: 'Marcar entregues',
        errorBag: 'deliver',
        action: () => {
            entregando.value = true;

            return router.post(route('redemptions.delivered.batch'), { ids }, {
                preserveScroll: true,
                onSuccess: () => (selecionados.value = new Set()),
                onFinish: () => (entregando.value = false),
            });
        },
    });
}

// ── Aba Itens do pedido ─────────────────────────────────────────────────────

const missing = (product) => Math.max(0, product.stock.required - product.stock.available);

// ── Etapa manual ───────────────────────────────────────────────────
// O Trello move o Projeto até "Envio" e a Omie o coloca em estoque. Quando
// a integração não está de pé, a operação ajusta aqui — inclusive para
// a etapa de estoque, que é o que destrava a liberação.
const stageDrawer = ref(false);
const stageFormId = useId();
const stageForm = useForm({ pipeline_status: props.project.pipeline_status });

const stageOptions = pipelineStages.map((status) => ({ value: status, label: pipelineLabels[status] ?? status }));
const stageChanged = computed(() => stageForm.pipeline_status !== props.project.pipeline_status);

function openStageDrawer() {
    stageForm.defaults({ pipeline_status: props.project.pipeline_status }).reset().clearErrors();
    stageDrawer.value = true;
}

function submitStage() {
    if (!stageChanged.value) return;

    stageForm.patch(route('projects.stage', props.project.id), {
        preserveScroll: true,
        onSuccess: () => (stageDrawer.value = false),
    });
}

// ── Edição de nome e prazo (inclusão UAT) ───────────────────────────────────
// O nome que a Omie monta nem sempre é como o cliente chama a campanha. Depois
// da primeira edição manual, o sync da Omie não sobrescreve mais nome/prazo.
const detailsDrawer = ref(false);
const detailsFormId = useId();

/** Cast 'date' chega como "2026-12-12T00:00:00Z"; o input date quer só o dia. */
const dateInputValue = (value) => (value ? String(value).slice(0, 10) : '');

const detailsForm = useForm({
    name: props.project.name,
    delivery_deadline: dateInputValue(props.project.delivery_deadline),
});

function openDetailsDrawer() {
    detailsForm
        .defaults({
            name: props.project.name,
            delivery_deadline: dateInputValue(props.project.delivery_deadline),
        })
        .reset()
        .clearErrors();
    detailsDrawer.value = true;
}

function useClientOrderNumber() {
    if (props.project.client_order_number) detailsForm.name = props.project.client_order_number;
}

function submitDetails() {
    detailsForm.patch(route('projects.details', props.project.id), {
        preserveScroll: true,
        onSuccess: () => (detailsDrawer.value = false),
    });
}

// ── Solicitação de kits e cadastro de Admin (drawers próprios) ──────────────
const requestDrawer = ref(false);
const adminDrawer = ref(false);

/*
 * Ações na linha do Admin: tirar e reenviar convite são do Admin WeGift, como
 * excluir o Projeto; permitir ou tirar o resgate de um Admin Projeto é também
 * do Admin Cliente.
 */
const mostraAcoesDeAdmin = computed(() => props.permissions.admin || props.permissions.grant_admin_redemption);

const adminColumns = computed(() => [
    { key: 'person', label: 'Admin' },
    { key: 'role', label: 'Papel' },
    { key: 'access', label: 'Acesso' },
    ...(mostraAcoesDeAdmin.value ? [{ key: 'actions', label: 'Ações', srOnlyLabel: true }] : []),
]);

/*
 * Quem administra este Projeto: os Admin Cliente (veem todos os Projetos da
 * empresa) e os Admins só deste. Os dois numa lista, porque é aqui que a
 * WeGift procura "quem cuida deste Projeto", e o Admin Cliente ficava de fora.
 */
const adminRows = computed(() => [
    ...(props.project.company.admins ?? []).map((a) => ({ ...a, active: true, papel: 'cliente' })),
    ...props.project.admins.map((a) => ({ ...a, papel: 'projeto' })),
]);

/** Convite de novo para quem ainda não criou a senha; o Admin Cliente usa o reenvio da empresa. */
function resendAdminInvite(admin) {
    const url = admin.papel === 'cliente'
        ? route('admin.companies.admins.resend', [props.project.company.id, admin.id])
        : route('projects.admins.resend', [props.project.id, admin.id]);

    run(`admin-${admin.id}`, url, {
        errorKey: 'invite',
        onError: (errors) => toast.warning(errors.invite ?? 'Não foi possível reenviar o convite.'),
    });
}

const adminMenu = (admin) => {
    const resgate = props.permissions.grant_admin_redemption && admin.papel === 'projeto'
        ? [admin.resgata
            ? { label: 'Tirar o resgate', icon: 'gift', onSelect: () => tirarResgateDoAdmin(admin) }
            : { label: 'Permitir resgate', icon: 'gift', onSelect: () => abrirResgateDoAdmin(admin) }]
        : [];

    if (!props.permissions.admin) return resgate;

    return [
        ...resgate,
        {
            label: 'Reenviar convite',
            icon: 'emails',
            disabled: !admin.access_pending || pending(`admin-${admin.id}`),
            separatorBefore: resgate.length > 0,
            onSelect: () => resendAdminInvite(admin),
        },
        {
            label: admin.papel === 'cliente' ? 'Remover da empresa' : 'Remover do Projeto',
            icon: 'trash',
            danger: true,
            separatorBefore: true,
            onSelect: () => (admin.papel === 'cliente' ? removeClientAdmin(admin) : removeAdmin(admin)),
        },
    ];
};

// ── Resgate do Admin do Projeto (para quem foi cadastrado sem ele) ─────────
const resgateDoAdminAberto = ref(false);
const adminDoResgate = ref(null);

function abrirResgateDoAdmin(admin) {
    adminDoResgate.value = admin;
    resgateDoAdminAberto.value = true;
}

async function tirarResgateDoAdmin(admin) {
    await confirm({
        title: `Tirar o resgate de ${admin.name}?`,
        description: 'A pessoa deixa de ter presente neste Projeto e continua como Admin. '
            + 'Se ela já resgatou, o resgate também é apagado, com o rastreio.',
        confirmLabel: 'Tirar o resgate',
        variant: 'danger',
        errorBag: 'remove',
        action: () => router.delete(route('projects.admins.redemption.revoke', [props.project.id, admin.id]), {
            preserveScroll: true,
        }),
    });
}

/**
 * O Admin Cliente não sai de um Projeto só: ele vê todos os da empresa, e
 * tirá-lo é tirá-lo de todos. A confirmação diz isso com todas as letras, e
 * avisa quando a empresa ficaria sem nenhum Admin Cliente.
 */
async function removeClientAdmin(admin) {
    const ultimo = (props.project.company.admins ?? []).length === 1;

    await confirm({
        title: `Remover ${admin.name} da ${props.project.company.name}?`,
        description: 'Admin Cliente vê todos os Projetos da empresa: a pessoa perde o acesso a todos, não só a este.'
            + (ultimo ? ' A empresa fica sem Admin Cliente.' : '')
            + ' Para ela seguir em alguns Projetos, cadastre-a depois como Admin Projeto em cada um.',
        confirmLabel: 'Remover da empresa',
        variant: 'danger',
        errorBag: 'remove',
        action: () => router.delete(route('admin.companies.admins.destroy', [props.project.company.id, admin.id]), {
            preserveScroll: true,
        }),
    });
}

/**
 * A conta do Admin existe só para este Projeto: sem histórico ela é apagada;
 * com pedidos ou planilhas no nome dele, fica desativada e o histórico fica.
 * Para quem confirma, o efeito é o mesmo: a pessoa perde o acesso agora.
 */
async function removeAdmin(admin) {
    await confirm({
        title: `Remover ${admin.name} deste Projeto?`,
        description: 'A pessoa perde o acesso ao Projeto na hora. O que ela já fez aqui continua registrado.',
        confirmLabel: 'Remover do Projeto',
        variant: 'danger',
        errorBag: 'remove',
        action: () => router.delete(route('projects.admins.destroy', [props.project.id, admin.id]), {
            preserveScroll: true,
        }),
    });
}

/** Só a WeGift crítica solicita kits, em nome do Cliente. */
const canRequestKits = computed(() => props.permissions.critical && props.project.released);

/**
 * Cadastrar Novo Admin. O Admin Projeto também cadastra — só que preso
 * ao próprio nível: outro Admin DESTE Projeto, nunca um Admin Cliente, que
 * enxergaria a empresa inteira. A trava real está no backend; aqui o drawer
 * nasce sem a escolha de tipo.
 */
const canManageAdmins = computed(() => props.permissions.manage_customers);

/**
 * As duas últimas etapas da esteira não vêm do `pipeline_status`: vêm dos KITS.
 *
 * "Envio" e "Concluído" não são um momento, são uma faixa. O primeiro kit sai
 * enquanto a maioria ainda nem foi resgatada, e o Projeto só acaba quando o
 * último chega. Tratá-las como as outras deixava a esteira parada em Estoque
 * enquanto a operação já estava despachando.
 *
 * A conta é de unidades, não de linhas de resgate. Uma solicitação de admin
 * leva trinta kits numa linha só: contando linhas, um Projeto de 150 fecharia
 * com cinco solicitações — e a esteira diria "concluído" com o pedido inteiro
 * ainda por despachar. O denominador é o que o PEDIDO pede: 150 requeridos,
 * 150 para concluir.
 *
 * Cancelado sai da conta: um resgate desfeito não é entrega pendente nem
 * entrega feita.
 */
const shipping = computed(() => {
    // O que o pedido pede, somando as linhas dele. `project.quantity` é o
    // reserva para o Projeto que ainda não tem item vinculado.
    const total = props.project.products.reduce(
        (soma, item) => soma + Number(item.stock?.required ?? 0),
        0,
    ) || Number(props.project.quantity ?? 0);

    // Somadas no servidor, sobre todos os resgates (a aba mostra uma página).
    const enviados = props.project.resgates_resumo.unidades_enviadas;
    const entregues = props.project.resgates_resumo.unidades_entregues;

    return {
        started: enviados > 0,
        // Teto em 1: entrega a mais (ajuste de quantidade depois do resgate)
        // não pode encher a barra além do fim.
        ratio: total > 0 ? Math.min(1, entregues / total) : 0,
        delivered: entregues,
        total,
    };
});


/*
 * Galeria do item principal: um painel só, reaproveitado por qualquer linha.
 *
 * Guarda a CHAVE da linha, não o objeto: depois de cada envio a página volta
 * com as props novas, e a galeria aberta tem de ser a delas. Na grade a chave
 * é o código do pai, que pode ainda nem estar cadastrado (só de memória).
 */
const galeriaDrawer = ref(false);

/*
 * Tirar item do Projeto (só Admin WeGift). No pai da grade saem todos os
 * tamanhos; em cada tamanho, só ele. Item com resgate não sai: o menu mostra
 * a opção apagada e diz por quê.
 */
function produtosDaLinha(row) {
    return row.grade ? (gradesDoPedido.value.get(row.product.omie_code) ?? []) : [row.product];
}

function itemMenu(row) {
    const produtos = produtosDaLinha(row);
    const comResgate = produtos.some((p) => p.resgates_ativos > 0);

    return [
        {
            label: comResgate ? 'Tem resgate: não sai do Projeto' : 'Remover do Projeto',
            icon: 'trash',
            danger: !comResgate,
            disabled: comResgate,
            onSelect: () => removerItem(row, produtos),
        },
    ];
}

async function removerItem(row, produtos) {
    await confirm({
        title: `Remover ${row.product.name} do Projeto?`,
        description: (row.grade ? `Saem os ${produtos.length} tamanhos. ` : '')
            + 'Quem tinha autorização para este item deixa de ter. A alteração do pedido na Omie não traz o item de volta, '
            + 'e dá para restaurar depois.',
        confirmLabel: 'Remover do Projeto',
        variant: 'danger',
        errorBag: 'remove',
        action: () => router.delete(route('projects.items.destroy', props.project.id), {
            data: { ids: produtos.map((p) => p.id) },
            preserveScroll: true,
        }),
    });
}

function restaurarItem(item) {
    run(`restaurar-${item.codigo}`, route('projects.items.restore', [props.project.id, item.codigo]), {
        errorKey: 'remove',
        onError: (errors) => toast.warning(errors.remove ?? 'Não foi possível restaurar o item.'),
    });
}

// Tela de resgate da campanha (engrenagem de Itens do pedido).
const telaDeResgateOpen = ref(false);
const galeriaChave = ref(null);

function abrirGaleria(row) {
    galeriaChave.value = row.grade ? `grade:${row.product.omie_code}` : `item:${row.product.id}`;
    galeriaDrawer.value = true;
}

const galeriaAberta = computed(() => {
    const chave = galeriaChave.value;
    if (!chave) return null;

    const produto = chave.startsWith('grade:')
        ? props.project.products.find((p) => p.grade?.pai_code === chave.slice(6))
        : props.project.products.find((p) => `item:${p.id}` === chave);

    return produto?.galeria ?? null;
});

/**
 * Itens do PEDIDO, linha a linha — todo registro de `project.products` aparece
 * no topo, mesmo quando o código também existe no BOM de um kit (falha F1 do
 * UAT: a caneca avulsa de 25 unidades sumia porque também vinha dentro do
 * kit). Item avulso e componente são dois papéis do mesmo produto: a linha
 * própria carrega a quantidade DO PEDIDO; a aninhada, a quantidade POR KIT.
 */
const bomCodes = computed(() => {
    const codes = new Set();

    // A árvore inteira, não só o primeiro nível: um item do pedido pode estar
    // dentro do sub-kit de um kit, e continua sendo o mesmo produto em dois
    // papéis.
    const percorrer = (components) => {
        for (const component of components ?? []) {
            codes.add(component.omie_code);
            percorrer(component.components);
        }
    };

    props.project.products.filter((p) => p.is_kit).forEach((p) => percorrer(p.components));

    return codes;
});

/** Item do pedido que também aparece dentro de um kit: ganha o rótulo discreto. */
const isLooseComponent = (product) => !product.is_kit && bomCodes.value.has(product.omie_code);

/**
 * Ramos recolhidos, pela CHAVE DE CAMINHO da linha (`item-7>CANECA>TAMPA`),
 * não pelo id do produto: o mesmo sub-kit pode aparecer dentro de dois kits do
 * pedido, e recolher um não pode fechar o outro.
 *
 * Guarda quem está FECHADO, não quem está aberto: um Projeto com kit novo
 * chega mostrando o conteúdo, que é o estado que a indentação veio entregar.
 * Recolher é a exceção, para quem já sabe o que tem dentro e quer varrer só a
 * lista de itens.
 */
const collapsedKits = ref(new Set());

const isCollapsed = (key) => collapsedKits.value.has(key);

function toggleKit(key) {
    // Set novo a cada troca: mutar o de dentro do ref é mais barato, mas
    // depende de o Vue rastrear coleção — trocar a referência não depende.
    const next = new Set(collapsedKits.value);

    next.has(key) ? next.delete(key) : next.add(key);
    collapsedKits.value = next;
}

/** Nó que abre: sub-kit sem estrutura espelhada ainda é folha na tela. */
const temFilhos = (node) => !!node.is_kit && (node.components?.length ?? 0) > 0;

/**
 * Achata a árvore do BOM em linhas de tabela, guardando a profundidade.
 *
 * A estrutura da Omie tem kit dentro de kit; a tabela é plana. Cada linha leva
 * seu `depth` e a tela transforma isso em recuo — é a indentação que carrega a
 * hierarquia, então ela precisa ser um dado da linha, não um estilo fixo.
 */
function componentRows(parentKey, components, depth) {
    return (components ?? []).flatMap((component) => {
        const key = `${parentKey}>${component.omie_code}`;
        const expandable = temFilhos(component);
        const rows = [{ key, depth, component, expandable }];

        if (!expandable || isCollapsed(key)) {
            return rows;
        }

        return rows.concat(componentRows(key, component.components, depth + 1));
    });
}

/**
 * Tamanhos da mesma grade da Omie (PRD11363_1, _2…) por pai, só quando há
 * dois ou mais no pedido: um tamanho sozinho é item como outro qualquer.
 */
const gradesDoPedido = computed(() => {
    const porPai = new Map();

    for (const product of props.project.products) {
        const pai = product.grade?.pai_code;
        if (!pai) continue;
        if (!porPai.has(pai)) porPai.set(pai, []);
        porPai.get(pai).push(product);
    }

    for (const [pai, irmaos] of porPai) {
        if (irmaos.length < 2) porPai.delete(pai);
    }

    return porPai;
});

const soma = (itens, campo) => itens.reduce((total, item) => total + Number(campo(item) ?? 0), 0);

/**
 * A linha do pai: as colunas somam os tamanhos. Estoque Omie só soma quando
 * TODOS foram consultados — somar metade leria como o saldo do Colete inteiro.
 */
function linhaDaGrade(pai, irmaos) {
    return {
        name: irmaos[0].grade.pai_nome,
        omie_code: pai,
        // As fotos são as do pai (os tamanhos repetem a galeria dele).
        photo_url: irmaos[0].photo_url,
        galeria: irmaos[0].galeria,
        tamanhos: irmaos.length,
        quantity: soma(irmaos, (p) => p.quantity),
        stock: {
            required: soma(irmaos, (p) => p.stock.required),
            project: soma(irmaos, (p) => p.stock.project),
            available: soma(irmaos, (p) => p.stock.available),
            synced_at: irmaos.every((p) => p.stock.synced_at) ? irmaos[0].stock.synced_at : null,
        },
    };
}

const itemRows = computed(() =>
    props.project.products.flatMap((product) => {
        // Tamanho de grade: o pai entra no lugar do primeiro tamanho, com os
        // tamanhos embaixo, na ordem de loja (PP, P, M…). Os outros tamanhos
        // já saíram junto com ele.
        const pai = product.grade?.pai_code;
        const irmaos = pai ? gradesDoPedido.value.get(pai) : null;

        if (irmaos) {
            if (irmaos[0].id !== product.id) return [];

            const key = `grade-${pai}`;
            const rows = [{ key, depth: 0, grade: true, product: linhaDaGrade(pai, irmaos), expandable: true }];

            if (isCollapsed(key)) return rows;

            const ordenados = [...irmaos].sort((a, b) => compararOrdem(a.grade.ordem, b.grade.ordem));

            return rows.concat(ordenados.map((p) => ({ key: `item-${p.id}`, depth: 1, tamanho: true, product: p })));
        }

        const key = `item-${product.id}`;
        // Só kit expande: item avulso com estrutura residual não vira árvore.
        const expandable = temFilhos(product);
        const rows = [{ key, depth: 0, product, expandable }];

        if (!expandable || isCollapsed(key)) {
            return rows;
        }

        return rows.concat(componentRows(key, product.components, 1));
    }),
);

/*
 * Três colunas de número, porque são três perguntas diferentes:
 *
 *   Qtde Requerida   a quantidade do Projeto, o que o pedido pede
 *   Estoque Projeto  o saldo que cai a cada resgate. Antes da liberação, "Não
 *                    Disponível": existe no banco, mas não está à disposição
 *   Estoque Omie     leitura da Omie, só consulta — a plataforma não mexe nele
 *
 * Uma coluna só de "Estoque" respondia a terceira e era lida como a segunda.
 */
const itemColumns = computed(() => {
    const columns = [
        { key: 'item', label: 'Item' },
        { key: 'quantity', label: 'Qtde Requerida', numeric: true },
    ];

    // "Estoque Projeto" vale para TODO MUNDO: é quanto ainda resta deste
    // Projeto, a pergunta que o cliente faz o tempo todo ("dá para mais
    // gente?"). Ficava escondido dele por estar no mesmo bloco de "Estoque
    // Omie", que é outra coisa — saldo do produto no ERP, somado entre
    // projetos, e aí sim assunto interno da WeGift.
    columns.push({ key: 'project_stock', label: 'Estoque Projeto', numeric: true });

    if (props.permissions.critical) {
        columns.push({ key: 'omie_stock', label: 'Estoque Omie', numeric: true });
        columns.push({ key: 'actions', label: 'Ações', srOnlyLabel: true });
    }

    return columns;
});

// ── Ação 2: adicionar Colaboradores ───────────────────────────────────────
const customerDrawer = ref(false);

/** Itens do pedido como opções do cadastro (foto + nome + código). */
const orderItems = computed(() => props.project.products);
/**
 * Item único no Projeto: o seletor some do formulário e da planilha. Uma lista
 * com uma opção só não é escolha — o item vai pré-selecionado no envio.
 */
const singleItem = computed(() => orderItems.value.length === 1);

const itemPickerOptions = computed(() =>
    orderItems.value.map((p) => ({ value: p.id, label: p.name, code: p.omie_code, photo: p.photo_url, grade: p.grade })),
);

// Os itens como o cadastro os mostra: os tamanhos de uma grade da Omie
// (Colete P, M, G…) são UM item.
const entradasDoPedido = computed(() => agruparPorGrade(itemPickerOptions.value));

// O rodapé do drawer é irmão do corpo: os botões alcançam o <form> por `form`.
const individualFormId = useId();

const blankCustomer = () => ({
    name: '',
    email: '',
    cpf: '',
    phone: '',
    // Pré-seleciona quando só há um item (o Colete com seus tamanhos conta
    // como um): não existe escolha a fazer.
    product_ids: entradasDoPedido.value.length === 1 ? [...entradasDoPedido.value[0].values] : [],
    mode: 'all',
});

const individualForm = useForm(blankCustomer());


const batchForm = useForm({ file: null });
const batchResult = ref(null);

/**
 * A cada envio bem-sucedido o `useForm` reancora os *defaults* nos dados que
 * acabaram de ir para o servidor. Um `reset()` seco, portanto, devolveria a
 * planilha já importada — a caixa reabriria com "lista.xlsx · 42 KB" e o botão
 * "Importar planilha" habilitado sobre um arquivo que já entrou, convidando ao
 * reenvio. Redefinir os defaults antes de resetar é o que faz os dois
 * formulários voltarem de fato ao estado inicial.
 */
function resetCustomerForms() {
    individualForm.defaults(blankCustomer()).reset().clearErrors();
    batchForm.defaults({ file: null }).reset().clearErrors();
    batchResult.value = null;
}

function openCustomerDrawer() {
    // Painel reaberto é sessão nova: arquivo, resultado e erros do envio
    // anterior não podem reaparecer como se fossem de agora (§9.1).
    resetCustomerForms();
    customerDrawer.value = true;
}

/**
 * A importação por planilha vive NA PÁGINA, não num painel lateral: são três
 * passos com download, tabela de códigos e um relatório de linhas recusadas
 * que a pessoa consulta enquanto corrige o arquivo. Espremido em 480px, o
 * relatório rolava dentro de um painel que rola, e fechar o painel para ver a
 * lista de quem entrou apagava o resultado.
 */
const batchPanel = ref(false);

/**
 * A planilha é de Colaborador e o painel dela mora naquela aba. Com o botão
 * fora das abas, ele precisa levar até lá — senão abriria um painel invisível
 * para quem está em Resgates.
 */
function abrirPlanilha() {
    if (aba.value !== 'clientes') {
        aba.value = 'clientes';
        batchPanel.value = false;
    }

    toggleBatchPanel();
}

function toggleBatchPanel() {
    batchPanel.value = !batchPanel.value;

    if (batchPanel.value) {
        batchForm.defaults({ file: null }).reset().clearErrors();
        batchResult.value = null;
    }
}

function addCustomer() {
    individualForm.post(route('projects.entitlements.store', props.project.id), {
        preserveScroll: true,
        // O painel continua aberto: cadastrar várias pessoas seguidas é o caso
        // comum — os itens escolhidos ficam, os dados da pessoa saem. O
        // `defaults()` dentro do onSuccess é o que impede o congelamento
        // automático.
        onSuccess: () => individualForm.defaults(blankCustomer()).reset('name', 'email', 'cpf', 'phone'),
    });
}

/*
 * Escolher o arquivo já valida a planilha e abre a prévia num modal; é de lá
 * que sai a importação. Não há botão "Enviar" no meio: ele só adiava a
 * pergunta que a pessoa acabou de fazer ao escolher o arquivo.
 *
 * `fetch` e não o `useForm`: o relatório volta como JSON e o arquivo continua
 * no campo, para o operador importar exatamente o que acabou de ver aprovado.
 */
const validacao = ref(null);
const validando = ref(false);
const validacaoFalhou = ref(null);
const validacaoAberta = ref(false);

// Os rótulos de Company::IMPORT_COLUMNS. Coluna fora do modelo aparece com o
// nome que veio na planilha, para a pessoa achar qual é.
const ROTULO_COLUNA = {
    nome: 'Nome',
    email: 'E-mail',
    funcao: 'Função',
    cpf: 'CPF',
    telefone: 'Telefone',
};

// As colunas da planilha, na ordem em que vieram.
const previaColunas = computed(() =>
    (validacao.value?.previa_colunas ?? []).map((c) => ({ key: c, label: ROTULO_COLUNA[c] ?? c })),
);

/*
 * O que cada pessoa recebe, decidido aqui e não na planilha: um item é
 * GARANTIDO (a pessoa recebe) ou entra na ESCOLHA (ela escolhe 1 entre os
 * marcados assim). Vale para todos os Colaboradores do arquivo.
 *
 * Abre com tudo na escolha, que é como as campanhas costumam ser montadas.
 * Com um item só não há o que escolher: ele vem garantido.
 */
const escolhaDosItens = ref({});

/*
 * Por item como o cadastro individual os mostra (entradasDoPedido): os
 * tamanhos de uma grade são UM item, o pai, e a pessoa escolhe o tamanho no
 * portal. Antes a lista era por tamanho (Colete G, Colete G2, Colete G4…), e
 * marcar o Colete exigia marcar cada tamanho.
 */
function reiniciarEscolha() {
    const padrao = entradasDoPedido.value.length > 1 ? 'escolha' : 'garantido';

    escolhaDosItens.value = Object.fromEntries(entradasDoPedido.value.map((e) => [e.key, padrao]));
}

const itensPorTipo = computed(() => {
    const doTipo = (tipo) => entradasDoPedido.value.filter((e) => escolhaDosItens.value[e.key] === tipo);

    return { garantidos: doTipo('garantido'), opcoes: doTipo('escolha') };
});

const listar = (itens) => {
    const nomes = itens.map((e) => e.label);

    return nomes.length > 1 ? `${nomes.slice(0, -1).join(', ')} e ${nomes.at(-1)}` : (nomes[0] ?? '');
};

/** Escolher entre um item só é recebê-lo: a importação espera por mais um. */
const escolhaIncompleta = computed(() => itensPorTipo.value.opcoes.length === 1);

const resumoDaEscolha = computed(() => {
    const { garantidos, opcoes } = itensPorTipo.value;

    if (opcoes.length < 2) return `Cada pessoa recebe ${garantidos.length === entradasDoPedido.value.length ? 'todos os itens' : listar(garantidos)}.`;
    if (!garantidos.length) return `Cada pessoa escolhe 1 entre ${listar(opcoes)}.`;

    return `Cada pessoa recebe ${listar(garantidos)} e escolhe 1 entre ${listar(opcoes)}.`;
});

/*
 * Fundo suave em todas: verde o que passou, vermelho o erro, âmbar o aviso.
 * O texto fica na cor normal nas certas — o verde é só o sinal de "ok".
 * Linha sem conferência (cabeçalho errado) fica sem cor: não há o que afirmar.
 */
function classeCelula(previa, coluna) {
    if (previa.status === null) return null;

    const estado = previa.celulas?.[coluna];

    if (estado === 'erro') return 'bg-danger-soft font-medium text-danger';
    if (estado === 'aviso') return 'bg-warning-soft text-warning';

    return 'bg-success-soft';
}

/** Quantas linhas com problema a prévia lista; o resto vira "+N". */
const TETO_DE_PROBLEMAS = 5;

// As mensagens chegam uma por problema; a pessoa corrige por linha.
const linhasComProblema = computed(() => {
    const porLinha = new Map();

    for (const m of validacao.value?.linhas ?? []) {
        const linha = porLinha.get(m.linha) ?? { linha: m.linha, tipo: m.tipo, mensagens: [] };

        linha.mensagens.push(m.mensagem);
        if (m.tipo === 'erro') linha.tipo = 'erro';

        porLinha.set(m.linha, linha);
    }

    return [...porLinha.values()];
});

const problemasOmitidos = computed(() =>
    validacao.value
        ? validacao.value.com_erro + validacao.value.com_aviso - Math.min(linhasComProblema.value.length, TETO_DE_PROBLEMAS)
        : 0,
);

// Prévia nova, escolha nova: a do arquivo anterior não vale para este.
watch(validacaoAberta, (aberta) => {
    if (aberta) reiniciarEscolha();
});

// Arquivo escolhido é arquivo a validar; trocou, o relatório é de outro.
watch(() => batchForm.file, (arquivo) => {
    validacao.value = null;
    validacaoFalhou.value = null;
    validando.value = false;

    if (arquivo) validarPlanilha();
});

/**
 * Fechar a prévia sem importar é desistir deste arquivo: a caixa volta vazia,
 * pronta para a planilha corrigida.
 */
function fecharValidacao() {
    validacaoAberta.value = false;
    batchForm.file = null;
}

function xsrf() {
    const par = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return par ? decodeURIComponent(par.split('=')[1]) : '';
}

async function validarPlanilha() {
    const arquivo = batchForm.file;

    if (!arquivo) return;

    validando.value = true;
    validacaoFalhou.value = null;

    const corpo = new FormData();
    corpo.append('file', arquivo);

    try {
        const resposta = await fetch(route('projects.entitlements.batch.validate', props.project.id), {
            method: 'POST',
            body: corpo,
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });

        const dados = await resposta.json().catch(() => null);

        // Trocou de arquivo enquanto este validava: a resposta é de outro.
        if (batchForm.file !== arquivo) return;

        if (resposta.status === 422) {
            validacaoFalhou.value = dados?.errors?.file?.[0] ?? 'Arquivo inválido. Envie .xlsx ou .csv.';
        } else if (!resposta.ok || !dados) {
            validacaoFalhou.value = 'Não foi possível validar agora. Tente de novo.';
        } else {
            validacao.value = dados;
            validacaoAberta.value = true;
        }
    } catch {
        if (batchForm.file === arquivo) validacaoFalhou.value = 'Sem conexão com o servidor. Tente de novo.';
    } finally {
        if (batchForm.file === arquivo) validando.value = false;
    }
}

function submitBatch() {
    // Importar é o passo depois de validar: sem relatório aprovado, não sai.
    if (!batchForm.file || !validacao.value?.pode_importar || escolhaIncompleta.value) return;

    batchResult.value = null;

    // Pede agora, no clique: o navegador só mostra o pedido com um gesto da
    // pessoa. É o que deixa avisar o fim da importação com a aba escondida.
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
    }

    batchForm.transform((dados) => ({
        ...dados,
        // Cada item vira os ids dos seus tamanhos: o servidor junta os
        // tamanhos de uma grade num "recebe um, escolhe o tamanho".
        garantidos: itensPorTipo.value.garantidos.flatMap((e) => e.values),
        opcoes: itensPorTipo.value.opcoes.flatMap((e) => e.values),
    })).post(route('projects.entitlements.batch', props.project.id), {
        preserveScroll: true,
        // Arquivo aceito é arquivo consumido: sai do campo e dos defaults antes
        // que o useForm os congele com ele dentro.
        onSuccess: () => {
            batchForm.defaults({ file: null }).reset();

            // Foi para a fila: o pop-up de acompanhamento abre no lugar da
            // prévia, e o link de primeiro acesso espera o fim.
            if (props.import?.running) acompanhamentoAberto.value = true;
        },
        onFinish: () => {
            // O resultado aparece no painel da página, embaixo do modal.
            validacaoAberta.value = false;
            captureBatchResult();
            oferecerLink(batchResult.value?.created);
        },
    });
}

/*
 * Importou Colaboradores: a próxima tarefa de quem subiu a planilha é
 * divulgar o link de primeiro acesso. O pop-up entrega o link na hora, em vez
 * de deixar a pessoa descobrir sozinha o painel no topo da aba.
 */
const linkAberto = ref(false);
const linkCadastrados = ref(0);

function oferecerLink(cadastrados) {
    // O link nasce no cadastro do primeiro Colaborador; sem ele, não há o que divulgar.
    if (!cadastrados || !props.project.company.first_access_url) return;

    linkCadastrados.value = cadastrados;
    linkAberto.value = true;
}

/**
 * O backend responde em dois canais opostos — o total cadastrado em
 * `flash.status` e as linhas recusadas no bag `batch`. O painel junta os dois
 * num sinal só, dentro do drawer (§10.3).
 */
function captureBatchResult() {
    // O status agora também pode trazer os Admins ("3 Colaboradores e 1 Admin
    // do Projeto cadastrados"): o casamento para no substantivo, não na frase.
    const created = String(page.props.flash?.status ?? '').match(/^(\d+)\s+Colaboradores?\b/);
    const rows = batchForm.errors.batch ?? null;

    // Sem nenhum dos dois é erro de arquivo: ele mora no campo, não aqui.
    if (!created && !rows) return;

    batchResult.value = {
        created: created ? Number(created[1]) : null,
        rows,
        // As recusadas numa planilha, para corrigir e subir de novo só elas.
        failuresUrl: page.props.flash?.import_failures_url ?? null,
    };
    // Lote com linhas recusadas volta pelo canal de erro: o onSuccess não roda
    // e é aqui que o arquivo já processado sai do campo. (Os defaults seguem
    // intactos nesse caminho — o useForm só os reancora em sucesso limpo.)
    batchForm.file = null;
}

const batchFailed = computed(() =>
    batchResult.value?.rows ? batchResult.value.rows.split(' | ').filter(Boolean).length : 0,
);

const batchVariant = computed(() => {
    if (batchResult.value?.created === null) return 'danger';

    return batchFailed.value > 0 ? 'warning' : 'success';
});

// ── Importação em segundo plano ─────────────────────────────────────────────
// Planilha grande deixou de ser cadastrada na requisição do upload: nove mil
// linhas passam de dez minutos e o servidor corta a requisição aos cinco. O
// trabalho foi para a fila, e esta tela é onde ele aparece — sem ela, o
// operador sobe o arquivo, lê "recebemos" e fica sem saber se está andando.
let pollId = null;

// O pop-up de acompanhamento. Declarado antes do `watch` abaixo, que roda já
// na montagem.
const acompanhamentoAberto = ref(false);
const linkPendente = ref(0);

function fecharAcompanhamento() {
    acompanhamentoAberto.value = false;

    if (linkPendente.value) {
        oferecerLink(linkPendente.value);
        linkPendente.value = 0;
    }
}

// Parar uma planilha errada no meio, sem depender do servidor. Os lotes que
// ainda estão na fila são descartados; quem já entrou fica no Projeto.
const cancelImportForm = useForm({});

async function cancelarImportacao() {
    const importacao = props.import;
    if (!importacao?.running) return;

    const entraram = importacao.created + importacao.admins;

    await confirm({
        title: `Cancelar a importação de "${importacao.filename}"?`,
        description: 'As linhas que ainda não entraram não serão cadastradas.',
        warning: entraram
            ? `${plural(entraram, 'pessoa já entrou e continua', 'pessoas já entraram e continuam')} no Projeto.`
            : null,
        confirmLabel: 'Cancelar importação',
        cancelLabel: 'Continuar importando',
        variant: 'danger',
        action: () => cancelImportForm.post(route('projects.imports.cancel', [props.project.id, importacao.id]), {
            preserveScroll: true,
            onSuccess: () => (acompanhamentoAberto.value = false),
        }),
    });
}

function pararPolling() {
    clearInterval(pollId);
    pollId = null;
}

/**
 * Enquanto corre, relê SÓ o contador: o Projeto e as listas são relidos uma
 * vez, quando a importação termina.
 */
watch(
    () => props.import?.running,
    (rodando, rodavaAntes) => {
        pararPolling();

        if (rodando) {
            pollId = setInterval(() => {
                router.reload({ only: ['import'], preserveScroll: true });
            }, 3000);

            return;
        }

        // Acabou de terminar: agora sim vale buscar a lista nova — e, com ela,
        // o link de primeiro acesso, que pode ter nascido nesta importação.
        // Com o pop-up de acompanhamento aberto, o link espera ele fechar:
        // dois modais um sobre o outro escondem o resultado.
        if (rodavaAntes) {
            router.reload({
                preserveScroll: true,
                onSuccess: () => {
                    if (acompanhamentoAberto.value) {
                        linkPendente.value = props.import?.created ?? 0;
                    } else {
                        oferecerLink(props.import?.created);
                    }
                },
            });
        }
    },
    { immediate: true },
);

onBeforeUnmount(pararPolling);
</script>

<template>
    <Head :title="displayTitle" />

    <!-- Cabeçalho no padrão das telas de cadastro: breadcrumb, voltar,
         título e, à direita, UM card de situação com as ações. -->
    <nav class="flex items-center gap-1.5 text-xs text-ink-muted" aria-label="Você está em">
        <Link :href="route(permissions.client ? 'projects.index' : 'dashboard')" prefetch class="hover:text-ink" aria-label="Início">
            <UiIcon name="home" :size="14" />
        </Link>
        <UiIcon name="chevron-right" :size="12" class="text-ink-subtle" aria-hidden="true" />
        <Link :href="route('projects.index')" prefetch class="hover:text-ink">Projetos</Link>
        <UiIcon name="chevron-right" :size="12" class="text-ink-subtle" aria-hidden="true" />
        <span class="truncate font-medium text-ink">{{ projectHeading }}</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
        <!--
            `items-center`: o bloco de texto se centraliza NO EIXO da logo.
            Antes a logo esticava para acompanhar a altura do bloco, e como o
            Admin Cliente não vê o botão "Liberar Resgates", o bloco dele é
            bem mais baixo — a moldura virava um retângulo largo e achatado, com
            um tamanho por perfil. Agora a logo tem medida fixa, igual nas duas
            visões, e quem se acomoda a ela é o texto.

            A seta de voltar fica de fora disso, com `self-start`: ela é um alvo
            de 36px, não um elemento que acompanha a altura do cabeçalho.
        -->
        <div class="flex items-center gap-3">
            <Link
                :href="route('projects.index')"
                prefetch
                class="mt-1 flex size-9 shrink-0 self-start items-center justify-center rounded-full border border-line bg-surface text-ink-muted transition-colors hover:text-ink"
                aria-label="Voltar para Projetos"
            >
                <UiIcon name="chevron-left" :size="18" />
            </Link>
            <!--
                Logo da campanha ao lado do nome, quando o Admin WeGift enviou
                uma em Customizações. `object-contain` e fundo claro: marca vem
                com respiro e proporção própria, e `cover` cortaria justamente o
                que identifica.

                A logo é IRMÃ do bloco de texto, não um item dentro dele. Antes
                ela dividia uma linha com o `<h1>` e o nome da empresa ficava
                FORA dessa linha — colado à esquerda, sob a marca em vez de sob
                o título. Agora título e empresa formam uma coluna só, alinhada
                ao lado da logo, e os dois começam no mesmo eixo.

                Sem logo não há espaço reservado nem moldura vazia: a linha
                volta a ser só o título, como era.
            -->
            <span
                v-if="project.logo_url"
                class="relative size-24 shrink-0 overflow-hidden rounded-card border border-line bg-surface sm:size-30"
            >
                <!--
                    Quadrado de medida FIXA — 120px no desktop.
                    Nada de esticar pela altura do vizinho: era isso que dava
                    uma logo por perfil de usuário. A imagem segue absoluta para
                    que a largura natural do arquivo não entre na conta; sem
                    isso, logo larga esticava a moldura e cada Projeto ficava
                    com um tamanho.
                -->
                <img :src="project.logo_url" alt="" class="absolute inset-0 size-full object-contain p-2.5" />
            </span>

            <div>
                <h1 class="text-title font-bold text-ink">{{ projectHeading }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ project.company.name }}</p>

                <!--
                    Liberar fica DEBAIXO DO TÍTULO, não dentro do card de
                    etapa. É a decisão do Projeto, a que abre o resgate para
                    todo mundo; ao lado de "Alterar etapa" ela virava mais uma
                    opção de um painel de manutenção. O card lá embaixo ficou
                    só com a etapa, que é o que ele sabe fazer.
                -->
                <!-- `lg` e sombra da própria cor: é a ação de maior
                     consequência da tela (abre o resgate para todo mundo, de
                     uma vez) e precisa ter o peso disso. Em `md`, ao lado de
                     "Editar dados" e "Atualizar", ela parecia uma irmã delas. -->
                <UiTooltip
                    v-if="release.kind === 'ready'"
                    class="mt-4"
                    placement="bottom"
                    text="Ao liberar, todo Colaborador já cadastrado recebe na hora um e-mail com o link para escolher o presente. Quem for cadastrado depois também recebe assim que entrar. A ação vale para o Projeto inteiro e não pode ser desfeita."
                >
                    <UiButton
                        variant="success"
                        size="lg"
                        class="shadow-success-sm hover:shadow-success"
                        icon="gift"
                        :loading="releaseForm.processing"
                        @click="releaseProject"
                    >
                        {{ releaseLabel }}
                    </UiButton>
                </UiTooltip>

                <!-- O que o botão faz agora vive no tooltip do próprio botão
                     (ver acima). O cartão que ficava aqui ocupava um bloco
                     permanente para explicar uma ação que acontece UMA vez na
                     vida do Projeto, e empurrava para baixo a ficha, a esteira
                     e as abas — tudo que se consulta o tempo todo. -->
            </div>
        </div>

        <div class="flex w-full flex-col gap-3 sm:w-auto sm:items-end">
            <!-- Barra de ações do cabeçalho: o Atualizar existe porque etapa e
                 estoque mudam por webhook, sem sinal para a SPA aberta. -->
            <div class="flex flex-wrap items-center gap-2">
                <UiRefreshButton :only="LISTAS_E_PROJETO" />
                <!-- O Acompanhamento já aberto neste Projeto; lá, o "Voltar"
                     traz de volta para cá. -->
                <UiButton
                    size="sm"
                    variant="secondary"
                    icon="reports"
                    :href="route('reports.index', { company: project.company.id, project: project.id })"
                    prefetch
                >
                    Acompanhamento
                </UiButton>
                <UiButton
                    v-if="permissions.critical"
                    size="sm"
                    variant="secondary"
                    @click="openDetailsDrawer"
                >
                    Editar dados
                </UiButton>
                <!-- Freio de mão: bloquear é da WeGift crítica; soltar, só
                     do Admin WeGift. -->
                <UiButton
                    v-if="!project.redemptions_blocked && permissions.block_redemptions && project.released"
                    size="sm"
                    variant="danger-soft"
                    :loading="blockForm.processing"
                    @click="blockRedemptions"
                >
                    Bloquear resgates
                </UiButton>
                <UiButton
                    v-if="project.redemptions_blocked && permissions.unblock_redemptions"
                    size="sm"
                    variant="secondary"
                    :loading="blockForm.processing"
                    @click="unblockRedemptions"
                >
                    Desbloquear resgates
                </UiButton>
                <UiButton
                    v-if="canRequestKits"
                    size="sm"
                    variant="secondary"
                    icon="gift"
                    @click="requestDrawer = true"
                >
                    Solicitar kits
                </UiButton>
            </div>

            <!-- Situação + ação num lugar só (operadores; o RH tem o card próprio). -->
            <div
                v-if="!permissions.client"
                class="flex w-full max-w-xl flex-wrap items-center gap-3 rounded-card bg-surface shadow-card p-4 sm:w-auto"
            >
                <span
                    :class="[
                        'flex size-9 shrink-0 items-center justify-center rounded-control',
                        release.kind === 'done' ? 'bg-success-soft text-success' : 'bg-brand-50 text-brand-700',
                    ]"
                >
                    <UiIcon :name="release.kind === 'done' ? 'check' : stageIcon" :size="18" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-ink">{{ stageLabel }}</p>

                    <!--
                        Sub-status: dentro de Estoque Disponível, o Projeto
                        ainda tem dois momentos muito diferentes — o resgate
                        está fechado ou aberto. Era isso que a etapa sozinha
                        não contava, e por isso alguém olhava "Em Estoque" e
                        não sabia se já podia avisar o pessoal.
                    -->
                    <p class="mt-0.5 text-xs font-medium" :class="subStatus.tone">{{ subStatus.label }}</p>

                    <p v-if="subStatus.hint" class="mt-0.5 max-w-sm text-xs text-ink-muted">{{ subStatus.hint }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <UiButton
                        v-if="permissions.critical"
                        size="sm"
                        variant="secondary"
                        icon="refresh"
                        @click="openStageDrawer"
                    >
                        Alterar etapa
                    </UiButton>
                </div>
            </div>
        </div>
    </div>

    <!--
        Faixa de identidade com ícones. O RH também a vê (sem Trello).

        `flex-wrap`, não grade de N colunas iguais. Os valores aqui têm
        tamanhos muito diferentes — um CNPJ tem 18 caracteres, o nome da
        campanha pode ter 40 —, e dividir a largura em partes iguais fazia
        todos caberem mal: sobrava espaço no "Pedido" e cortava "Projeto Teste
        An…" e "Abrir card no Tr…". Cortado, o dado não serve para conferir
        nem para copiar, que é o motivo de ele estar na faixa.

        Cada item pede a largura que precisa (`basis` mínimo, `grow`) e a
        faixa ganha uma segunda linha quando não couber. Nada de `truncate`:
        quem for longo quebra dentro do próprio bloco.
    -->
    <div class="mt-5 flex flex-wrap rounded-card bg-surface shadow-card">
        <div class="flex min-w-[13rem] flex-1 items-center gap-3 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-surface-muted text-ink-muted">
                <UiIcon name="companies" :size="16" />
            </span>
            <!--
                Sem `truncate` no CNPJ: ele tem tamanho fixo e conhecido, e
                cortado ("005.479.979…") não serve para conferir nem para
                copiar — que são os dois motivos de ele estar aqui. Quando não
                couber, quebra a linha em vez de sumir com o fim.
            -->
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">CNPJ</p>
                <p class="text-sm font-medium break-words text-ink tabular-nums">
                    {{ formatDocument(project.company.cnpj) }}
                </p>
            </div>
        </div>

        <div v-if="project.ordered_at" class="flex min-w-[13rem] flex-1 items-center gap-3 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-surface-muted text-ink-muted">
                <UiIcon name="calendar" :size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">Data de Criação</p>
                <p class="text-sm font-medium break-words text-ink tabular-nums">{{ formatDate(project.ordered_at) }}</p>
            </div>
        </div>

        <div class="flex min-w-[13rem] flex-1 items-center gap-3 p-4" :title="permissions.client ? deadlineTooltip : null">
            <span
                :class="[
                    'flex size-9 shrink-0 items-center justify-center rounded-control',
                    prazo?.tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-surface-muted text-ink-muted',
                ]"
            >
                <UiIcon name="calendar" :size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">Prazo de Entrega</p>
                <p class="text-sm font-medium break-words text-ink">
                    {{ project.delivery_deadline ? formatDate(project.delivery_deadline) : 'Sem prazo' }}
                    <span
                        v-if="prazo && prazo.tone !== 'default'"
                        :class="prazo.tone === 'danger' ? 'text-danger' : 'text-warning'"
                    >
                        · {{ prazo.label }}
                    </span>
                    <span v-if="permissions.client" class="sr-only">{{ deadlineTooltip }}.</span>
                </p>
            </div>
        </div>

        <div v-if="project.origem" class="flex min-w-[13rem] flex-1 items-center gap-3 p-4">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-surface-muted text-ink-muted">
                <UiIcon name="companies" :size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">Conta Omie</p>
                <p class="text-sm font-medium break-words text-ink">{{ project.origem }}</p>
            </div>
        </div>

        <a
            v-if="permissions.critical && project.trello_card_url"
            :href="project.trello_card_url"
            target="_blank"
            rel="noopener"
            class="flex min-w-[13rem] flex-1 items-center gap-3 rounded-card p-4 transition-colors hover:bg-surface-muted"
        >
            <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-surface-muted text-ink-muted">
                <UiIcon name="external" :size="16" />
            </span>
            <div class="min-w-0">
                <p class="text-xs text-ink-muted">Trello</p>
                <!-- "Abrir card" e não "Abrir card no Trello": o rótulo acima
                     já diz onde, e a repetição era o que estourava a largura. -->
                <p class="text-sm font-medium break-words text-brand-700">Abrir card</p>
            </div>
        </a>
    </div>

    <div>
            <!-- Fila parada tem de dizer POR QUE parou e quem parou: sem isto,
                 o próximo operador (ou o RH do cliente) só vê o resgate sem
                 funcionar e abre chamado. Aparece nas duas visões. -->
            <UiAlert
                v-if="project.redemptions_blocked"
                class="mt-4"
                variant="warning"
                title="Resgates bloqueados"
            >
                <p>
                    Bloqueado
                    <template v-if="project.redemptions_blocked_by">por {{ project.redemptions_blocked_by }}</template>
                    em {{ formatDate(project.redemptions_blocked_at) }}. As pessoas não conseguem resgatar
                    enquanto o bloqueio estiver ativo.
                </p>
                <p v-if="project.redemptions_block_reason" class="mt-1">
                    Motivo: {{ project.redemptions_block_reason }}
                </p>
                <p class="mt-1">
                    {{ permissions.unblock_redemptions
                        ? 'Use Desbloquear resgates no topo da tela para retomar.'
                        : 'Só o Admin WeGift desbloqueia.' }}
                </p>
            </UiAlert>

            <!-- O aviso "kit em estoque, resgate ainda não aberto" saiu: o
                 sub-status (Pendente de Liberação) diz a mesma coisa em duas
                 palavras, ao lado da etapa, e o botão de liberar agora está
                 debaixo do título. Três lugares repetindo o mesmo recado era
                 o que fazia a tela parecer cheia de aviso. -->

            <!-- RH (Admin Cliente): a esteira de produção é jargão de fábrica e
                 não responde à pergunta dele — "já posso avisar meu pessoal?".
                 Um bloco só, em três estados, com o que ele precisa fazer. -->
            <div
                v-if="permissions.client"
                :class="[
                    'mt-4 rounded-card bg-surface px-5 py-4',
                    rhStatus.tone === 'success' ? 'shadow-glow-success' : 'shadow-card',
                ]"
            >
                <div class="flex items-start gap-3">
                    <span
                        :class="[
                            'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-control',
                            rhStatus.tone === 'success' ? 'bg-success-soft text-success' : 'bg-surface-muted text-ink-muted',
                        ]"
                    >
                        <UiIcon :name="rhStatus.icon" :size="16" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-base font-semibold text-ink">{{ rhStatus.title }}</p>
                        <p class="mt-1 text-sm text-ink-muted">{{ rhStatus.text }}</p>
                        <p v-if="rhStatus.next" class="mt-2 text-sm font-medium text-ink">
                            O que você faz agora: {{ rhStatus.next }}
                        </p>
                        <UiButton
                            v-if="rhStatus.acompanhar"
                            class="mt-3"
                            size="sm"
                            variant="secondary"
                            icon-trailing="chevron-right"
                            :href="route('reports.index', { company: project.company.id, project: project.id })"
                            prefetch
                        >
                            Acompanhar resgates
                        </UiButton>
                    </div>
                </div>
            </div>

            <!--
                A esteira vale para TODO MUNDO, inclusive Admin Cliente e Admin
                Projeto. Ela estava escondida deles por ser vocabulário de
                fábrica, e o card de situação bastaria — mas "em que pé está o
                meu Projeto" é a pergunta que o cliente mais faz, e a resposta
                em uma olhada é justamente a esteira.

                O ajuste manual de etapa continua sendo só da WeGift: ele mora
                no card de situação, lá em cima.
            -->
            <div class="mt-4 overflow-hidden rounded-card bg-surface shadow-card px-4 pt-6 pb-3.5">
                <ProjectTimeline
                    :current="project.pipeline_status"
                    :sub-status="subStatus.label"
                    :released="project.released"
                    :shipping="shipping"
                />
            </div>

            <!-- O card "Estoque na Omie" saiu: a esteira logo acima já mostra o
                 Projeto em Estoque Disponível, e repetir o mesmo fato em selo
                 separado só somava um bloco à rolagem. O saldo item a item
                 continua na aba Itens do pedido, que é onde ele é acionável. -->
    </div>

        <div class="mt-6 border-b border-line pb-px">
        <UiTabs v-model="aba" :tabs="tabs" panel-prefix="projeto" />
    </div>

    <!--
        Cadastrar gente vive FORA das abas.
        As duas ações são irmãs — Admin e Colaborador — e separá-las, cada uma
        na sua aba, obrigava a trocar de aba para trocar de papel e fazia a
        mesma tarefa ter dois lugares. Aqui elas ficam juntas, na ordem pedida,
        e valem para qualquer aba aberta.
    -->
    <div v-if="permissions.manage_customers" class="mt-4 flex flex-wrap items-center gap-3">
        <!-- Um rótulo só: daqui só nasce Admin do Projeto, para quem quer que
             esteja cadastrando. -->
        <UiButton v-if="canManageAdmins" variant="secondary" icon="plus" @click="adminDrawer = true">
            Admin Projeto
        </UiButton>

        <UiButton icon="plus" @click="openCustomerDrawer">Colaborador</UiButton>

        <UiButton
            variant="secondary"
            icon="upload"
            :aria-expanded="batchPanel"
            aria-controls="painel-planilha"
            @click="abrirPlanilha"
        >
            Enviar planilha
        </UiButton>
    </div>

    <!-- Admin deste Projeto -->
    <section
        v-if="aba === 'admins'"
        :id="panelId('admins')"
        role="tabpanel"
        :aria-labelledby="tabId('admins')"
        tabindex="0"
        class="mt-6"
    >
        <UiFormHeading class="mb-3" icon="users" title="Admins deste Projeto" />

        <UiTable :columns="adminColumns" caption="Admins deste Projeto, com papel e situação do acesso">
            <tr v-for="admin in adminRows" :key="`${admin.papel}-${admin.id}`">
                <th scope="row" class="font-normal">
                    <span class="block font-medium text-ink">{{ admin.name }}</span>
                    <span class="block truncate text-xs text-ink-muted">{{ admin.email }}</span>
                </th>
                <td>
                    <span class="block text-ink">{{ admin.papel === 'cliente' ? 'Admin Cliente' : 'Admin Projeto' }}</span>
                    <span class="block text-xs text-ink-muted">
                        {{ admin.papel === 'cliente' ? 'Todos os Projetos da empresa' : 'Só este Projeto' }}
                        <template v-if="admin.resgata"> · também resgata</template>
                    </span>
                </td>
                <td>
                    <UiBadge v-if="!admin.active" variant="neutral">Inativo</UiBadge>
                    <UiBadge v-else-if="admin.access_pending" variant="warning">Convite pendente</UiBadge>
                    <UiBadge v-else variant="success">Acesso ativo</UiBadge>
                </td>
                <td v-if="mostraAcoesDeAdmin">
                    <div class="flex justify-end">
                        <UiMenu
                            v-if="adminMenu(admin).length"
                            :items="adminMenu(admin)"
                            :label="`Ações de ${admin.name}`"
                        />
                    </div>
                </td>
            </tr>

            <template #empty>
                <!-- Projeto sem admin não é erro: a WeGift também administra.
                     Mas é o que explica "ninguém do cliente acompanha isto". -->
                <UiEmpty
                    icon="users"
                    message="Nenhum Admin deste Projeto. Quem acompanha por aqui é a equipe WeGift."
                />
            </template>
        </UiTable>
    </section>

    <!-- Colaboradores -->
    <section
        v-if="aba === 'clientes'"
        :id="panelId('clientes')"
        role="tabpanel"
        :aria-labelledby="tabId('clientes')"
        tabindex="0"
        class="mt-6"
    >
        <UiFormHeading class="mb-3" icon="users" title="Colaboradores autorizados" />

        <!--
            O link que o Cliente publica na comunicação interna dele.

            Mora aqui porque a hora de entregá-lo é esta: quem acabou de subir a
            planilha precisa do endereço na mesma tela, não a três cliques no
            cadastro do Cliente. Ele nasce no primeiro envio de planilha.
        -->
        <UiPanel v-if="project.company.first_access_url" class="mb-4">
            <p class="text-sm font-medium text-ink">Link de primeiro acesso</p>
            <p class="mt-0.5 text-sm text-ink-muted">
                Mande para {{ project.company.name }} publicar na comunicação interna. O colaborador
                informa o e-mail corporativo e recebe o link para criar a senha.
            </p>

            <!-- UiCode lê `value`, não o slot: com o link no slot, o painel
                 mostrava uma caixa vazia. -->
            <UiCode class="mt-3 block break-all" :value="project.company.first_access_url" />

            <div class="mt-3">
                <UiButton variant="secondary" size="sm" @click="copiarPrimeiroAcesso">
                    <UiIcon :name="primeiroAcessoCopiado ? 'check' : 'copy'" :size="16" class="mr-1" />
                    {{ primeiroAcessoCopiado ? 'Copiado' : 'Copiar link' }}
                </UiButton>
            </div>
        </UiPanel>

        <!-- Planilha grande é cadastrada na fila, não no upload: sem este
             painel o operador sobe nove mil linhas, lê "recebemos" e fica sem
             saber se está andando. -->
        <UiPanel v-if="props.import" :tone="props.import.running ? 'muted' : 'default'" class="mb-4">
            <ImportProgressDetails :importacao="props.import" />

            <div v-if="props.import.running" class="mt-3 flex justify-end">
                <UiButton variant="danger-soft" size="sm" @click="cancelarImportacao">Cancelar importação</UiButton>
            </div>
        </UiPanel>

        <!-- O pop-up de acompanhamento: abre sozinho quando a planilha vai para
             a fila. Fechar não para nada; o aviso flutuante segue a pessoa nas
             outras telas e avisa quando termina. -->
        <UiModal
            :open="acompanhamentoAberto && props.import !== null"
            :title="props.import?.running ? 'Importando Colaboradores' : 'Importação concluída'"
            size="md"
            @update:open="(aberto) => { if (!aberto) fecharAcompanhamento(); }"
        >
            <ImportProgressDetails v-if="props.import" :importacao="props.import" />

            <template #footer>
                <UiButton data-autofocus @click="fecharAcompanhamento">
                    {{ props.import?.running ? 'Continuar usando a plataforma' : 'Fechar' }}
                </UiButton>
                <UiButton v-if="props.import?.running" variant="danger-soft" @click="cancelarImportacao">
                    Cancelar importação
                </UiButton>
            </template>
        </UiModal>

        <!-- Importação por planilha, na própria página. -->
        <UiCard v-if="batchPanel" id="painel-planilha" class="relative mb-4">
            <!-- Fora do #header: lá o X ganhava uma faixa só para ele e
                 empurrava o passo 1 para baixo. -->
            <UiButton
                class="absolute top-3 right-3"
                variant="ghost"
                size="sm"
                icon="close"
                icon-only
                label="Fechar importação por planilha"
                :disabled="batchForm.processing"
                @click="batchPanel = false"
            />

            <h2 class="sr-only">Importar Colaboradores por planilha</h2>

            <!-- Como as pessoas entram muda o que a importação faz com cada
                 linha: dizer antes evita esperar um convite que não vai sair. -->
            <p class="mb-5 flex items-start gap-2 pr-10 text-sm text-ink-muted">
                <UiIcon :name="enviaEmails ? 'emails' : 'external'" :size="16" class="mt-0.5 shrink-0" />
                <span v-if="enviaEmails">
                    <strong class="font-medium text-ink">Entrada por convite:</strong>
                    cada pessoa nova recebe o e-mail da WeLog para criar a senha.
                </span>
                <span v-else>
                    <strong class="font-medium text-ink">Entrada pelo link de primeiro acesso:</strong>
                    a importação só cria as contas, sem e-mail. As pessoas entram pelo link que o Cliente divulga.
                </span>
            </p>

            <ol class="space-y-6">
                <li class="flex gap-3">
                    <span
                        aria-hidden="true"
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700"
                    >
                        1
                    </span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <h3 class="text-sm font-semibold text-ink">
                            <span class="sr-only">Passo 1. </span>Exportar planilha modelo
                        </h3>

                        <!-- Download de arquivo: <a> de verdade. `UiButton :href`
                             renderiza <Link>, que interceptaria o clique. -->
                        <a
                            :href="route('projects.entitlements.template', project.id)"
                            class="h-control-md inline-flex items-center justify-center gap-2 rounded-control bg-brand-600 px-4 text-sm font-medium whitespace-nowrap text-ink-inverse transition-colors select-none hover:bg-brand-700"
                        >
                            <UiIcon name="download" />
                            Download (.xlsx)
                        </a>

                        <p class="text-sm text-ink-muted">
                            Já vem com o cabeçalho e um exemplo preenchido.
                        </p>

<p v-if="project.products.length > 1" class="text-sm text-ink-muted">
                            Só os dados das pessoas. Os itens que cada uma recebe você escolhe na hora de importar.
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
                    <div class="min-w-0 flex-1 space-y-2">
                        <h3 class="text-sm font-semibold text-ink">
                            <span class="sr-only">Passo 2. </span>Importar planilha preenchida
                        </h3>

                        <!-- Sem botão de enviar: escolher o arquivo já abre a prévia. -->
                        <UiField :error="batchForm.errors.file">
                            <UiFileDrop
                                :file="batchForm.file"
                                :progress="batchForm.progress?.percentage ?? null"
                                :disabled="batchForm.processing || validando"
                                @select="batchForm.file = $event"
                                @clear="batchForm.file = null"
                            >
                                <!-- Resultado durável: bloco que só fecha com clique (§9.3). -->
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
                                                <UiIcon name="check" :size="16" class="text-success" />
                                                {{ plural(batchResult.created, 'cadastrado', 'cadastrados') }}
                                            </span>
                                            <span v-if="batchFailed" class="inline-flex items-center gap-1.5">
                                                <UiIcon name="close" :size="16" class="text-danger" />
                                                {{ plural(batchFailed, 'não cadastrado', 'não cadastrados') }}
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
                                                Corrija apenas essas linhas e envie de novo.
                                                <template v-if="batchResult.created">
                                                    As {{ batchResult.created }} já cadastradas não serão
                                                    duplicadas.
                                                </template>
                                            </p>
                                        </template>

                                        <template v-if="batchResult.rows" #actions>
                                            <!-- <a>, não <Link>: é download de arquivo. -->
                                            <a
                                                v-if="batchResult.failuresUrl"
                                                :href="batchResult.failuresUrl"
                                                class="inline-flex h-control-sm items-center gap-1.5 rounded-full bg-brand-600 px-3 text-sm font-medium text-ink-inverse hover:bg-brand-700"
                                            >
                                                <UiIcon name="download" :size="16" />
                                                Baixar recusadas (.xlsx)
                                            </a>
                                            <UiButton variant="secondary" size="sm" @click="batchResult = null">
                                                Enviar planilha corrigida
                                            </UiButton>
                                        </template>
                                    </UiAlert>
                                </template>
                            </UiFileDrop>
                        </UiField>

                        <p v-if="validando" role="status" class="text-sm text-ink-muted">Lendo a planilha…</p>
                        <p v-else-if="validacaoFalhou" role="alert" class="text-sm text-danger">
                            {{ validacaoFalhou }}
                        </p>
                    </div>
                </li>
            </ol>
        </UiCard>

        <!-- Prévia ANTES de importar: abre sozinha quando o arquivo é
             escolhido, e a importação só sai daqui, depois de conferida. -->
        <UiModal
            :open="validacaoAberta && validacao !== null"
            title="Pré-visualização"
            :description="batchForm.file?.name ?? null"
            size="xl"
            :dismissible="!batchForm.processing"
            @update:open="(aberto) => { if (!aberto) fecharValidacao(); }"
        >
            <template v-if="validacao">
                <!-- O que cada pessoa recebe: decidido aqui, para todo o arquivo. -->
                <section
                    v-if="validacao.pode_importar && entradasDoPedido.length > 1"
                    aria-labelledby="escolha-itens"
                    class="mb-6 rounded-card border border-line p-4 sm:p-5"
                >
                    <h3 id="escolha-itens" class="text-sm font-semibold text-ink">Como cada pessoa recebe os itens</h3>
                    <p class="mt-0.5 text-xs text-ink-muted">
                        <span class="font-medium text-ink">Escolhe 1:</span> a pessoa escolhe um entre os itens marcados assim.
                        <span class="font-medium text-ink">Recebe sempre:</span> o item vai para todas as pessoas, sem escolha.
                    </p>

                    <ul class="mt-3 divide-y divide-line">
                        <!-- Os itens como no cadastro individual: o pai da grade
                             com os tamanhos, e não um tamanho por linha. -->
                        <li v-for="item in entradasDoPedido" :key="item.key" class="flex items-center gap-3 py-2.5">
                            <ProjectItemThumb :src="item.photo" size="sm" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink">{{ item.label }}</p>
                                <p class="truncate text-xs text-ink-muted">
                                    {{ item.code }}
                                    <template v-if="item.tamanhos.length">
                                        · Escolhe o tamanho no portal: {{ item.tamanhos.join(' · ') }}
                                    </template>
                                </p>
                            </div>

                            <div
                                role="radiogroup"
                                :aria-label="`Como ${item.label} entra`"
                                class="inline-flex shrink-0 rounded-full bg-surface-muted p-0.5 text-xs font-medium"
                            >
                                <button
                                    v-for="tipo in ['escolha', 'garantido']"
                                    :key="tipo"
                                    type="button"
                                    role="radio"
                                    :aria-checked="escolhaDosItens[item.key] === tipo"
                                    :disabled="batchForm.processing"
                                    :class="[
                                        'rounded-full px-3.5 py-1.5 transition-colors',
                                        escolhaDosItens[item.key] === tipo
                                            ? (tipo === 'garantido' ? 'bg-surface text-ink shadow-xs' : 'bg-brand-600 text-ink-inverse shadow-xs')
                                            : 'text-ink-muted hover:text-ink',
                                    ]"
                                    @click="escolhaDosItens[item.key] = tipo"
                                >
                                    {{ tipo === 'garantido' ? 'Recebe sempre' : 'Escolhe 1' }}
                                </button>
                            </div>
                        </li>
                    </ul>

                    <p
                        class="mt-3 flex items-start gap-2 rounded-control px-3 py-2.5 text-sm"
                        :class="escolhaIncompleta ? 'bg-warning-soft text-warning' : 'bg-brand-50 text-ink'"
                        aria-live="polite"
                    >
                        <UiIcon :name="escolhaIncompleta ? 'alert' : 'gift'" :size="16" class="mt-0.5 shrink-0" :class="escolhaIncompleta ? '' : 'text-brand-600'" />
                        <template v-if="escolhaIncompleta">
                            Para ter escolha, marque pelo menos 2 itens como Escolhe 1.
                        </template>
                        <template v-else>{{ resumoDaEscolha }}</template>
                    </p>
                </section>

                <!-- O resumo: o que veio, o que entra e o que fica de fora. -->
                <!-- Juntos à esquerda: no modal largo, três colunas iguais
                     espalhavam os números de ponta a ponta. -->
                <dl class="flex flex-wrap gap-x-12 gap-y-3">
                    <div>
                        <dt class="text-xs text-ink-muted">Carregadas</dt>
                        <dd class="mt-0.5 text-2xl font-semibold text-ink tabular-nums">
                            {{ formatQuantity(validacao.total) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Prontas</dt>
                        <dd class="mt-0.5 text-2xl font-semibold text-ink tabular-nums">
                            {{ formatQuantity(validacao.prontas) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Com erro</dt>
                        <dd
                            class="mt-0.5 text-2xl font-semibold tabular-nums"
                            :class="validacao.com_erro ? 'text-danger' : 'text-ink'"
                        >
                            {{ formatQuantity(validacao.com_erro) }}
                        </dd>
                    </div>
                </dl>

                <p v-if="validacao.com_aviso" class="mt-2 text-xs text-ink-muted">
                    {{ plural(validacao.com_aviso, 'linha tem', 'linhas têm') }} aviso e
                    {{ validacao.com_aviso === 1 ? 'entra' : 'entram' }} mesmo assim.
                </p>

                <ul v-if="validacao.cabecalho.length" class="mt-5 space-y-1 text-sm">
                    <li
                        v-for="(m, i) in validacao.cabecalho"
                        :key="`c${i}`"
                        class="flex gap-2"
                        :class="m.tipo === 'erro' ? 'text-danger' : 'text-ink-muted'"
                    >
                        <UiIcon :name="m.tipo === 'erro' ? 'close' : 'alert'" :size="16" class="mt-0.5 shrink-0" />
                        {{ m.mensagem }}
                    </li>
                </ul>

                <!--
                    As primeiras linhas, coluna por coluna como no arquivo
                    (telefone já normalizado). Tabela própria, e não UiTable:
                    cada linha da planilha cabe numa linha só.
                -->
                <div v-if="validacao.previa?.length" class="mt-6">
                    <p class="mb-2 text-xs text-ink-muted">
                        <template v-if="validacao.previa_omitidas">
                            Primeiras {{ validacao.previa.length }} de {{ formatQuantity(validacao.total) }} linhas
                        </template>
                        <template v-else>Linhas da planilha</template>
                    </p>

                    <div class="scrollbar-slim overflow-x-auto rounded-control border border-line">
                        <table class="w-full border-collapse text-sm text-ink">
                            <caption class="sr-only">Primeiras linhas da planilha</caption>
                            <thead>
                                <tr class="border-b border-line bg-surface-muted">
                                    <th
                                        scope="col"
                                        class="w-14 px-3 py-2 text-right align-bottom text-xs font-semibold text-ink-muted"
                                    >
                                        Linha
                                    </th>
                                    <th
                                        v-for="c in previaColunas"
                                        :key="c.key"
                                        scope="col"
                                        class="px-3 py-2 text-left align-bottom text-xs font-semibold whitespace-nowrap text-ink-muted"
                                    >
                                        {{ c.label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="p in validacao.previa"
                                    :key="p.linha"
                                    class="border-b border-line last:border-b-0"
                                >
                                    <th
                                        scope="row"
                                        class="px-3 py-2 text-right text-xs font-normal text-ink-muted tabular-nums"
                                    >
                                        {{ p.linha }}
                                    </th>
                                    <td
                                        v-for="c in previaColunas"
                                        :key="c.key"
                                        :class="['px-3 py-2 whitespace-nowrap', classeCelula(p, c.key)]"
                                    >
                                        {{ p.valores[c.key] }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- O que corrigir, uma linha da planilha por item. -->
                <div v-if="linhasComProblema.length" class="mt-5">
                    <p class="mb-1 text-xs text-ink-muted">Linhas a corrigir</p>
                    <ul class="divide-y divide-line text-sm">
                        <li
                            v-for="l in linhasComProblema.slice(0, TETO_DE_PROBLEMAS)"
                            :key="l.linha"
                            class="flex gap-3 py-2"
                        >
                            <span class="shrink-0 whitespace-nowrap text-ink-muted tabular-nums">Linha {{ l.linha }}</span>
                            <span :class="l.tipo === 'erro' ? 'text-danger' : 'text-warning'">
                                {{ l.mensagens.join(' ') }}
                            </span>
                        </li>
                    </ul>
                    <p v-if="problemasOmitidos > 0" class="mt-1 text-xs text-ink-muted">
                        + {{ plural(problemasOmitidos, 'outra linha', 'outras linhas') }}
                    </p>
                </div>

                <p v-if="!validacao.pode_importar" class="mt-5 text-sm text-ink">
                    Nada a importar. Corrija a planilha e envie de novo.
                </p>
                <p v-else-if="validacao.com_erro" class="mt-5 text-sm text-ink-muted">
                    As linhas com erro ficam de fora da importação.
                </p>
            </template>

            <template #footer>
                <template v-if="validacao?.pode_importar">
                    <UiButton
                        :loading="batchForm.processing"
                        :disabled="escolhaIncompleta"
                        data-autofocus
                        @click="submitBatch"
                    >
                        Importar {{ plural(validacao.prontas, 'linha', 'linhas') }}
                    </UiButton>
                    <UiButton variant="ghost" :disabled="batchForm.processing" @click="fecharValidacao">
                        Cancelar
                    </UiButton>
                </template>
                <UiButton v-else variant="secondary" @click="fecharValidacao">Fechar</UiButton>
            </template>
        </UiModal>

        <!-- Depois de importar: o link de primeiro acesso, pronto para copiar,
             e o que fazer com ele em três passos. -->
        <UiModal
            :open="linkAberto"
            title="Colaboradores importados"
            size="md"
            @update:open="linkAberto = $event"
        >
            <p class="flex items-center gap-2 text-sm text-ink">
                <UiIcon name="check" :size="16" class="shrink-0 text-success" />
                {{ plural(linkCadastrados, 'Colaborador cadastrado', 'Colaboradores cadastrados') }}.
            </p>

            <h3 class="mt-5 text-base font-semibold text-ink">Agora, divulgue o link de primeiro acesso</h3>
            <p class="mt-1 text-sm text-ink-muted">
                É por ele que cada Colaborador entra na plataforma pela primeira vez.
            </p>

            <div
                class="mt-4 flex flex-wrap items-center gap-3 rounded-control border border-line bg-surface-muted p-3"
            >
                <p class="min-w-0 flex-1 font-mono text-sm break-all text-ink">
                    {{ project.company.first_access_url }}
                </p>
                <UiButton size="sm" data-autofocus @click="copiarPrimeiroAcesso">
                    <UiIcon :name="primeiroAcessoCopiado ? 'check' : 'copy'" :size="16" class="mr-1" />
                    {{ primeiroAcessoCopiado ? 'Copiado' : 'Copiar link' }}
                </UiButton>
            </div>
            <p class="sr-only" role="status">{{ primeiroAcessoCopiado ? 'Link copiado' : '' }}</p>

            <ol class="mt-5 space-y-3 text-sm text-ink">
                <li class="flex gap-3">
                    <span
                        aria-hidden="true"
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700"
                    >
                        1
                    </span>
                    <span class="pt-0.5">Copie o link acima.</span>
                </li>
                <li class="flex gap-3">
                    <span
                        aria-hidden="true"
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700"
                    >
                        2
                    </span>
                    <span class="pt-0.5">
                        Envie para {{ project.company.name }} publicar na comunicação interna: e-mail, intranet
                        ou grupo de WhatsApp.
                    </span>
                </li>
                <li class="flex gap-3">
                    <span
                        aria-hidden="true"
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700"
                    >
                        3
                    </span>
                    <span class="pt-0.5">
                        O Colaborador abre o link, informa o e-mail corporativo e recebe um e-mail para criar a
                        senha.
                    </span>
                </li>
            </ol>

            <p class="mt-5 text-xs text-ink-muted">
                Só entra quem está na lista importada. O link continua disponível no topo desta aba.
            </p>

            <template #footer>
                <UiButton variant="secondary" @click="linkAberto = false">Concluir</UiButton>
            </template>
        </UiModal>

        <!-- A busca fica colada na tabela que ela filtra. Acima do painel de
             importação, parecia pertencer a ele. -->
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <UiSearchInput
                v-if="showCustomerSearch"
                v-model="customerQuery"
                class="min-w-0 flex-1"
                placeholder="nome ou e-mail"
                :result-label="customerCountLabel"
                @search="buscarColaboradores"
            />
            <p v-else class="ml-auto text-xs tabular-nums text-ink-muted">{{ customerCountLabel }}</p>
        </div>

        <UiTable
            :columns="customerColumns"
            caption="Colaboradores autorizados neste Projeto"
            :aria-busy="recarregandoLista === 'colaboradores' ? 'true' : null"
            :class="['transition-opacity', recarregandoLista === 'colaboradores' && 'opacity-60']"
        >
            <tr v-for="row in pagedCustomers" :key="row.key">
                <th scope="row">
                    <span class="flex flex-wrap items-center gap-1.5">
                        <span class="text-ink">{{ row.user.name }}</span>
                        <!-- Admin do Projeto que também resgata: só a etiqueta. -->
                        <UiBadge v-if="row.admin" variant="neutral">Admin</UiBadge>
                    </span>
                    <span class="block text-xs font-normal text-ink-muted">{{ row.user.email }}</span>
                </th>

                <!-- O resumo ("2 itens + 1 de 3"); os nomes ficam no balão. -->
                <td>
                    <span class="text-ink" :title="row.itemsTitle">{{ row.itemsLabel }}</span>
                </td>

                <td class="whitespace-nowrap">
                    <span class="block text-ink">{{ situation(row) }}</span>
                    <span v-if="situationNote(row)" class="block text-xs text-ink-muted">
                        {{ situationNote(row) }}
                    </span>
                </td>

                <td v-if="permissions.manage_customers">
                    <div class="flex items-center justify-end gap-2">
                        <span v-if="pending(row.primary.id)" role="status" class="text-xs text-ink-muted">
                            Reenviando…
                        </span>
                        <UiMenu
                            :items="customerMenu(row)"
                            :label="`Ações de ${row.user.name}`"
                        />
                    </div>
                </td>
            </tr>

            <template #empty>
                <UiEmpty :icon="colaboradores.busca ? 'search' : 'users'" :message="customerEmptyMessage">
                    <template v-if="!colaboradores.busca && permissions.manage_customers" #action>
                        <UiButton icon="plus" @click="openCustomerDrawer">Colaborador</UiButton>
                    </template>
                </UiEmpty>
            </template>
        </UiTable>

        <UiPagination
            v-model="customerPage"
            :total="colaboradores.total"
            :per-page="colaboradores.por_pagina"
            label="Colaboradores"
        />
    </section>

    <!-- Resgates -->
    <section
        v-else-if="aba === 'resgates' && abaDeResgates"
        :id="panelId('resgates')"
        role="tabpanel"
        :aria-labelledby="tabId('resgates')"
        tabindex="0"
        class="mt-6"
    >
        <UiFormHeading class="mb-3" icon="gift" title="Resgates do Projeto" />

        <!-- Barra de seleção: aparece com a primeira linha marcada e some
             sozinha. A contagem vale o Projeto inteiro, não só esta página. -->
        <UiPanel v-if="permissions.critical && totalMarcado > 0" tone="muted" class="sticky top-2 z-20 mb-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm font-medium text-ink">
                    {{ totalMarcado }} {{ totalMarcado === 1 ? 'resgate selecionado' : 'resgates selecionados' }}
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <UiButton size="sm" :loading="entregando" @click="marcarEntregues">
                        Marcar entregues
                    </UiButton>
                    <UiButton variant="ghost" size="sm" @click="selecionados = new Set()">
                        Limpar seleção
                    </UiButton>
                </div>
            </div>
        </UiPanel>

        <!-- "Selecionar todos" mora aqui, e não no cabeçalho da tabela: só as
             linhas em transporte podem ser marcadas, e uma caixa no cabeçalho
             prometeria marcar a coluna inteira. -->
        <div v-if="permissions.critical && emTransporte.length" class="mb-2 flex justify-end">
            <UiButton variant="ghost" size="sm" @click="alternarTodos">
                {{ todosMarcados
                    ? 'Desmarcar todos'
                    : `Selecionar ${emTransporte.length} em transporte` }}
            </UiButton>
        </div>

        <UiTable
            :columns="redemptionColumns"
            caption="Resgates confirmados neste Projeto"
            :aria-busy="recarregandoLista === 'resgates' ? 'true' : null"
            :class="['transition-opacity', recarregandoLista === 'resgates' && 'opacity-60']"
        >
            <tr v-for="redemption in pagedRedemptions" :key="redemption.id">
                <td v-if="permissions.critical">
                    <input
                        v-if="redemption.status === 'shipped'"
                        type="checkbox"
                        class="size-4 accent-brand-600"
                        :checked="marcado(redemption.id)"
                        :aria-label="`Selecionar o resgate de ${redemption.user}`"
                        @change="alternarResgate(redemption.id)"
                    />
                    <span v-else class="sr-only">Este resgate não está em transporte</span>
                </td>

                <th scope="row">
                    <span class="flex flex-wrap items-center gap-1.5">
                        <span class="text-ink">{{ redemption.user }}</span>
                        <!-- Solicitação feita por um admin, não resgate individual. -->
                        <UiBadge v-if="redemption.is_request" variant="info">Solicitação</UiBadge>
                    </span>
                    <span v-if="redemption.cpf" class="block text-xs font-normal text-ink-muted tabular-nums">
                        CPF {{ redemption.cpf }}
                    </span>
                    <span v-if="redemption.address" class="block text-xs font-normal text-ink-muted">
                        {{ redemption.address }}
                    </span>
                    <span v-if="redemption.notes" class="block text-xs font-normal text-ink-muted">
                        Obs.: {{ redemption.notes }}
                    </span>
                </th>

                <td>
                    {{ redemption.product }}
                    <span v-if="redemption.quantity > 1" class="block text-xs text-ink-muted tabular-nums">
                        {{ plural(redemption.quantity, 'unidade', 'unidades') }}
                    </span>
                </td>

                <td>
                    <StatusBadge :status="redemption.status" kind="redemption" />

                    <div
                        v-if="redemption.status === 'shipped'"
                        class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-muted"
                    >
                        <span v-if="redemption.carrier">{{ carrierLabel(redemption.carrier) }}</span>
                        <UiCode v-if="redemption.tracking_code" :value="redemption.tracking_code" truncate="end" />
                        <a
                            v-if="trackingLink(redemption)"
                            :href="trackingLink(redemption)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-brand-700 underline underline-offset-2"
                        >
                            Rastrear
                        </a>
                    </div>

                    <p
                        v-else-if="redemption.status === 'delivered' && redemption.delivered_at"
                        class="mt-1 text-xs text-ink-muted"
                    >
                        {{ formatDate(redemption.delivered_at) }}
                    </p>
                </td>

                <td><ShipControl :redemption="redemption" :can-ship="permissions.critical" /></td>
            </tr>

            <template #empty>
                <UiEmpty
                    icon="redemptions"
                    message="Nenhum resgate confirmado ainda. Eles aparecem aqui conforme os Colaboradores informam o endereço."
                />
            </template>
        </UiTable>

        <UiPagination
            v-model="redemptionPage"
            :total="resgates.total"
            :per-page="resgates.por_pagina"
            label="Resgates"
        />
    </section>

    <!-- Itens do pedido -->
    <!--
        `v-else-if` explícito, não `v-else`: a cadeia começava em
        Colaboradores, e quando a aba Admin entrou antes dela como um `v-if`
        próprio, o `v-else` daqui virou "nenhuma das outras duas" e passou a
        aparecer JUNTO com a aba Admin. Condição nomeada não depende de quem
        veio antes.
    -->
    <section
        v-else-if="aba === 'kits'"
        :id="panelId('kits')"
        role="tabpanel"
        :aria-labelledby="tabId('kits')"
        tabindex="0"
        class="mt-6"
    >
        <UiFormHeading class="mb-3" icon="catalog" title="Itens do pedido">
            <!-- A tela de resgate desta campanha: textos e o nome e a descrição
                 de cada item como o colaborador os lê. -->
            <button
                v-if="permissions.critical"
                type="button"
                class="ml-1 flex size-8 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-surface-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                aria-label="Personalizar a tela de resgate"
                title="Personalizar a tela de resgate"
                @click="telaDeResgateOpen = true"
            >
                <UiIcon name="settings" :size="17" />
            </button>
        </UiFormHeading>

        <UiTable
            v-if="project.products.length"
            :columns="itemColumns"
            caption="Itens do pedido deste Projeto"
            density="compact"
        >
            <!--
                A linha do componente não leva divisória nem fundo branco: o
                conteúdo do kit tem de ler como UM bloco aberto embaixo dele.
                Com o traço de sempre, cada componente virava uma linha igual
                às outras da tabela, e a expansão parecia empurrar itens
                soltos para a lista.
            -->
            <tr
                v-for="row in itemRows"
                :key="row.key"
                :class="(row.component || row.tamanho) && 'border-t-0 bg-surface-muted/50'"
            >
                <!-- Pai da grade da Omie: os tamanhos do pedido abrem embaixo. -->
                <th v-if="row.grade" scope="row">
                    <div class="flex items-start gap-3">
                        <ProjectItemThumb :src="row.product.photo_url" />

                        <button
                            type="button"
                            class="-ml-1 flex items-start gap-2 rounded-control px-1 py-0.5 text-left transition-colors hover:bg-surface-muted"
                            :aria-expanded="!isCollapsed(row.key)"
                            @click="toggleKit(row.key)"
                        >
                            <UiIcon
                                name="chevronRight"
                                :size="16"
                                aria-hidden="true"
                                :class="[
                                    'mt-0.5 shrink-0 text-ink-muted transition-transform motion-reduce:transition-none',
                                    !isCollapsed(row.key) && 'rotate-90',
                                ]"
                            />
                            <span class="min-w-0">
                                <span class="block font-medium text-ink">{{ row.product.name }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                    <UiCode :value="row.product.omie_code" />
                                    <span class="text-xs text-ink-muted">
                                        {{ isCollapsed(row.key) ? 'mostrar' : 'recolher' }}
                                        {{ plural(row.product.tamanhos, 'tamanho', 'tamanhos') }}
                                    </span>
                                </span>
                            </span>
                        </button>
                    </div>
                </th>

                <!-- Linha de item do pedido: kit vira gatilho de recolher. -->
                <th v-else-if="!row.component" scope="row" :class="row.tamanho && 'font-normal'">
                    <!-- Tamanho: recua até o texto do pai, com o mesmo "L" do
                         conteúdo de kit. -->
                    <div class="flex items-start gap-3" :style="row.tamanho ? { paddingLeft: '3.25rem' } : null">
                        <span
                            v-if="row.tamanho"
                            aria-hidden="true"
                            class="-mr-1 mt-1 h-3 w-3 shrink-0 rounded-bl-[6px] border-b border-l border-line"
                        />
                        <ProjectItemThumb :src="row.product.photo_url" />

                        <!-- O gatilho precisa PARECER gatilho: caixa, hover e a
                             contagem sempre visível anunciam o conteúdo. -->
                        <button
                            v-if="row.expandable"
                            type="button"
                            class="-ml-1 flex items-start gap-2 rounded-control px-1 py-0.5 text-left transition-colors hover:bg-surface-muted"
                            :aria-expanded="!isCollapsed(row.key)"
                            @click="toggleKit(row.key)"
                        >
                            <UiIcon
                                name="chevronRight"
                                :size="16"
                                aria-hidden="true"
                                :class="[
                                    'mt-0.5 shrink-0 text-ink-muted transition-transform motion-reduce:transition-none',
                                    !isCollapsed(row.key) && 'rotate-90',
                                ]"
                            />
                            <span class="min-w-0">
                                <span class="block font-medium text-ink">{{ row.product.name }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                    <UiCode :value="row.product.omie_code" />
                                    <!-- Sempre visível, não só recolhido: é o que
                                         anuncia que a linha tem conteúdo dentro. -->
                                    <span class="text-xs text-ink-muted">
                                        {{ isCollapsed(row.key) ? 'mostrar' : 'recolher' }}
                                        {{ plural(row.product.components.length, 'item', 'itens') }}
                                    </span>
                                </span>
                            </span>
                        </button>

                        <span v-else class="min-w-0">
                            <span class="block font-medium text-ink">{{ row.product.name }}</span>
                            <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                <UiCode :value="row.product.omie_code" />
                                <!-- O mesmo produto também está dentro de um kit
                                     acima: esta linha é a quantidade PRÓPRIA dele
                                     no pedido, não o conteúdo do kit. -->
                                <UiBadge v-if="isLooseComponent(row.product)" variant="neutral">
                                    Item avulso
                                </UiBadge>
                            </span>
                            <!-- Variação (parent code): o pedido diz só "15
                                 camisetas"; quanto produzir de cada tamanho é
                                 a soma do que as pessoas escolheram. -->
                            <span
                                v-if="row.product.variant_choices?.length"
                                class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs"
                            >
                                <span class="font-medium text-ink-muted">Escolhidos:</span>
                                <UiBadge
                                    v-for="escolha in row.product.variant_choices"
                                    :key="escolha.label"
                                    variant="info"
                                >
                                    {{ escolha.label }} <span class="tabular-nums">{{ escolha.total }}</span>
                                </UiBadge>
                            </span>
                        </span>
                    </div>
                </th>

                <!-- Subitem: a indentação é o dado, e cresce um degrau por
                     nível da estrutura (kit dentro de kit). O conector marca a
                     hierarquia para quem não enxerga o recuo (leitor de tela
                     lê "contém"), e um sub-kit abre com o mesmo gesto do kit
                     de cima.

                     O recuo parte de 3,25rem, que é onde o TEXTO do kit
                     começa: a miniatura (2,5rem) mais o intervalo (0,75rem).
                     Contando do zero, como antes, o filho nascia 30px à
                     ESQUERDA do pai — lia como item solto embaixo do kit, e
                     não como conteúdo dele. -->
                <th v-else scope="row" class="font-normal">
                    <span
                        class="flex items-start gap-2"
                        :style="{ paddingLeft: `${3.25 + (row.depth - 1) * 1.5}rem` }"
                    >
                        <!-- O "L" do galho, desenhado: o caractere └ herdava a
                             entrelinha da fonte e nunca encostava no nome. -->
                        <span
                            aria-hidden="true"
                            class="mt-1 h-3 w-3 shrink-0 rounded-bl-[6px] border-b border-l border-line"
                        />

                        <button
                            v-if="row.expandable"
                            type="button"
                            class="-ml-1 flex min-w-0 items-start gap-2 rounded-control px-1 py-0.5 text-left transition-colors hover:bg-surface-muted"
                            :aria-expanded="!isCollapsed(row.key)"
                            @click="toggleKit(row.key)"
                        >
                            <UiIcon
                                name="chevronRight"
                                :size="16"
                                aria-hidden="true"
                                :class="[
                                    'mt-0.5 shrink-0 text-ink-muted transition-transform motion-reduce:transition-none',
                                    !isCollapsed(row.key) && 'rotate-90',
                                ]"
                            />
                            <span class="min-w-0">
                                <span class="sr-only">Contém: </span>
                                <span class="block text-ink">{{ row.component.name }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                    <UiCode :value="row.component.omie_code" />
                                    <span class="text-xs text-ink-muted">
                                        {{ isCollapsed(row.key) ? 'mostrar' : 'recolher' }}
                                        {{ plural(row.component.components.length, 'item', 'itens') }}
                                    </span>
                                </span>
                            </span>
                        </button>

                        <span v-else class="min-w-0">
                            <span class="sr-only">Contém: </span>
                            <span class="block text-ink">{{ row.component.name }}</span>
                            <UiCode class="mt-0.5" :value="row.component.omie_code" />
                        </span>
                    </span>
                </th>

                <td class="tabular-nums">
                    <template v-if="!row.component">{{ formatQuantity(row.product.quantity) }}</template>
                    <!-- Quantidade do componente é POR KIT, não do pedido:
                         sem o rótulo, 2 ao lado de um kit de 400 unidades lê
                         como "faltam 398". -->
                    <span v-else class="text-ink-muted">
                        {{ formatQuantity(row.component.quantity) }}
                        <span class="text-xs">por kit</span>
                    </span>
                </td>

                <!-- Estoque Projeto: o saldo que a WeLog controla e que cai a
                     cada resgate. Antes do kit chegar não existe saldo, e
                     escrever "0" ali seria confundir "ainda não chegou" com
                     "acabou" — dois estados que pedem ações opostas. -->
                <td>
                    <template v-if="!row.component">
                        <!--
                            "Não Disponível" enquanto o resgate não abre.
                            Antes da liberação o saldo existe no banco mas não
                            está à disposição de ninguém; mostrar o número ali
                            levaria a operação a contar com unidades que ainda
                            não podem sair, e a confundir com "acabou" quando
                            aparecesse zero.
                        -->
                        <span v-if="!project.released" class="text-ink-muted">Não Disponível</span>
                        <span
                            v-else
                            :class="row.product.stock.project > 0 ? 'font-medium text-ink' : 'font-medium text-warning'"
                        >
                            {{ formatQuantity(row.product.stock.project) }}
                            <span class="text-xs font-normal text-ink-muted">
                                de {{ formatQuantity(row.product.stock.required) }}
                            </span>
                        </span>
                    </template>
                    <span v-else class="text-ink-muted">-</span>
                </td>

                <!-- Estoque Omie: referência. É o saldo do PRODUTO, somado
                     entre todos os projetos que usam o mesmo kit. -->
                <td v-if="permissions.critical">
                    <template v-if="!row.component">
                        <span v-if="!row.product.stock.synced_at" class="text-ink-muted">Não consultado</span>
                        <span v-else class="text-ink">{{ formatQuantity(row.product.stock.available) }}</span>
                    </template>
                    <span v-else class="tabular-nums text-ink-muted">
                        {{ formatQuantity(row.component.stock_qty) }}
                    </span>
                </td>

                <td v-if="permissions.critical">
                    <div v-if="!row.component" class="flex items-center justify-end gap-1">
                        <!-- As fotos são do item principal: o pai da grade ou o
                             item isolado. Tamanho não tem foto própria. -->
                        <UiButton
                            v-if="!row.tamanho"
                            variant="ghost"
                            size="sm"
                            icon="image"
                            @click="abrirGaleria(row)"
                        >
                            Fotos
                            <span v-if="row.product.galeria?.fotos.length" class="tabular-nums text-ink-muted">
                                ({{ row.product.galeria.fotos.length }})
                            </span>
                        </UiButton>

                        <!-- Tirar do Projeto: só Admin WeGift. No pai da grade
                             saem todos os tamanhos; em cada tamanho, só ele. -->
                        <UiMenu
                            v-if="permissions.admin"
                            :items="itemMenu(row)"
                            :label="`Ações de ${row.product.name}`"
                        />
                    </div>
                </td>
            </tr>
        </UiTable>

        <UiEmpty v-else message="Nenhum item vinculado ao pedido deste Projeto." />

        <!-- Itens tirados à mão: ficam lembrados (a Omie não os traz de volta)
             e podem voltar como estavam. -->
        <div v-if="permissions.admin && project.removed_items?.length" class="mt-6">
            <p class="text-sm font-medium text-ink">Itens removidos</p>
            <p class="mt-0.5 text-xs text-ink-muted">
                Não voltam com as alterações do pedido na Omie. Restaurar devolve o item com a quantidade que tinha.
            </p>
            <ul class="mt-3 divide-y divide-line overflow-hidden rounded-card border border-line bg-surface">
                <li
                    v-for="item in project.removed_items"
                    :key="item.codigo"
                    class="flex items-center justify-between gap-3 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm text-ink">{{ item.name }}</p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-ink-muted">
                            <UiCode :value="item.omie_code" />
                            <span class="tabular-nums">{{ formatQuantity(item.quantity) }} unid.</span>
                            <span v-if="item.removed_by">· removido por {{ item.removed_by }}</span>
                        </p>
                    </div>
                    <UiButton
                        variant="secondary"
                        size="sm"
                        icon="refresh"
                        :loading="pending(`restaurar-${item.codigo}`)"
                        @click="restaurarItem(item)"
                    >
                        Restaurar
                    </UiButton>
                </li>
            </ul>
        </div>
    </section>

    <!-- Zona de risco: exclusão definitiva, só Admin WeGift (N1 não vê). -->
    <section
        v-if="permissions.admin"
        class="mt-8 flex flex-wrap items-center justify-between gap-3 rounded-control border border-danger-line bg-surface px-4 py-4"
    >
        <div class="min-w-0">
            <p class="text-sm font-semibold text-ink">Excluir projeto</p>
            <p class="mt-0.5 text-sm text-ink-muted">
                Apaga o Projeto com usuários autorizados, resgates e rastreios. A empresa e as contas das pessoas ficam.
            </p>
        </div>
        <UiButton variant="danger-soft" :loading="destroyForm.processing" @click="destroyProject">
            Excluir projeto
        </UiButton>
    </section>

    <UiDrawer
        v-if="permissions.critical"
        v-model:open="stageDrawer"
        title="Alterar etapa"
        description="Use quando o Trello não estiver disponível. O ajuste vale só para o WeLog. O card no Trello não é movido."
    >
        <form :id="stageFormId" class="space-y-4" @submit.prevent="submitStage">
            <UiFormHeading icon="refresh" title="Etapa do Projeto" />

            <UiField label="Etapa" required :error="stageForm.errors.pipeline_status">
                <UiSelect v-model="stageForm.pipeline_status" :options="stageOptions" />
            </UiField>

            <UiAlert
                v-if="stageForm.pipeline_status === STAGE_IN_STOCK"
                variant="info"
                title="Etapa que destrava a liberação"
            >
                Com o Projeto em {{ pipelineLabels[STAGE_IN_STOCK] }}, o botão Liberar Resgates fica
                disponível, desde que o saldo sincronizado do estoque da Omie cubra todos os itens do
                pedido. Sem saldo, a liberação continua bloqueada.
            </UiAlert>

            <UiAlert
                v-else-if="project.released && stageForm.pipeline_status !== project.pipeline_status"
                variant="warning"
                title="Projeto já liberado"
            >
                Mudar a etapa não desfaz a liberação nem afeta os resgates já abertos.
            </UiAlert>
        </form>

        <template #footer>
            <UiButton type="submit" :form="stageFormId" :disabled="!stageChanged" :loading="stageForm.processing">
                Salvar etapa
            </UiButton>
            <UiButton variant="ghost" :disabled="stageForm.processing" @click="stageDrawer = false">
                Cancelar
            </UiButton>
        </template>
    </UiDrawer>

    <UiDrawer
        v-if="permissions.critical"
        v-model:open="detailsDrawer"
        title="Editar dados do Projeto"
        description="Depois da primeira edição manual, a sincronização com a Omie deixa de sobrescrever o nome e o prazo."
    >
        <form :id="detailsFormId" class="space-y-4" @submit.prevent="submitDetails">
            <UiFormHeading icon="projects" title="Dados do Projeto" />

            <UiField label="Nome do Projeto" required :error="detailsForm.errors.name">
                <UiInput v-model="detailsForm.name" autocomplete="off" />
            </UiField>

            <!-- Atalho: o cliente costuma chamar a campanha pelo nº do pedido dele. -->
            <UiButton
                v-if="project.client_order_number"
                variant="secondary"
                size="sm"
                @click="useClientOrderNumber"
            >
                Usar nº do pedido do cliente ({{ project.client_order_number }})
            </UiButton>

            <UiField label="Prazo de Entrega" :error="detailsForm.errors.delivery_deadline">
                <UiInput v-model="detailsForm.delivery_deadline" type="date" />
            </UiField>
        </form>

        <template #footer>
            <UiButton type="submit" :form="detailsFormId" :loading="detailsForm.processing">
                Salvar dados
            </UiButton>
            <UiButton variant="ghost" :disabled="detailsForm.processing" @click="detailsDrawer = false">
                Cancelar
            </UiButton>
        </template>
    </UiDrawer>

    <UiDrawer
        v-if="permissions.critical"
        v-model:open="galeriaDrawer"
        :title="galeriaAberta?.name ?? 'Fotos do item'"
        :description="galeriaAberta?.omie_code"
    >
        <div v-if="galeriaAberta" class="space-y-5">
            <!-- Consequência antes da ação: as fotos são do produto, não deste
                 Projeto, e quem envia precisa saber disso ANTES de enviar. -->
            <UiAlert variant="info">
                Valem para o catálogo inteiro: todos os Projetos que usam este item e o portal.
            </UiAlert>

            <ProductGalleryManager :galeria="galeriaAberta" />
        </div>

        <template #footer>
            <UiButton variant="secondary" @click="galeriaDrawer = false">Fechar</UiButton>
        </template>
    </UiDrawer>

    <!-- Tela de resgate da campanha: os textos e, embaixo, a vitrine como o
         colaborador vai abri-la. -->
    <UiModal
        v-if="permissions.critical"
        v-model:open="telaDeResgateOpen"
        :title="`Tela de resgate · ${project.name}`"
        description="Como o colaborador lê esta campanha: a saudação, a chamada da escolha e cada item."
        size="xl"
    >
        <PortalScreenStudio v-if="telaDeResgateOpen" :project="project" />
    </UiModal>

    <UiDrawer
        v-if="permissions.manage_customers"
        v-model:open="customerDrawer"
        title="Adicionar Colaborador"
        description="Para cadastrar muita gente de uma vez, use o botão Enviar planilha na página."
    >
        <form :id="individualFormId" class="space-y-6" @submit.prevent="addCustomer">
            <section class="space-y-4">
                <UiFormHeading icon="users" title="Dados da pessoa" />

                <UiFormSection :columns="2">
                    <UiField
                        class="sm:col-span-2"
                        label="Nome"
                        required
                        :error="individualForm.errors.name"
                    >
                        <UiInput v-model="individualForm.name" autocomplete="off" />
                    </UiField>

                    <UiField
                        class="sm:col-span-2"
                        label="E-mail"
                        required
                        :error="individualForm.errors.email"
                        hint="É por ele que a pessoa recebe o convite e o aviso de liberação."
                    >
                        <UiInput
                            v-model="individualForm.email"
                            type="email"
                            inputmode="email"
                            autocomplete="off"
                        />
                    </UiField>

                    <UiField label="CPF" :error="individualForm.errors.cpf">
                        <UiInput
                            :model-value="individualForm.cpf"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="opcional"
                            maxlength="14"
                            @update:model-value="individualForm.cpf = maskCpf($event)"
                        />
                    </UiField>

                    <UiField label="Telefone" :error="individualForm.errors.phone">
                        <UiInput
                            :model-value="individualForm.phone"
                            type="tel"
                            inputmode="tel"
                            autocomplete="off"
                            placeholder="opcional"
                            maxlength="15"
                            @update:model-value="individualForm.phone = maskPhone($event)"
                        />
                    </UiField>
                </UiFormSection>
            </section>

            <!-- Item único: nada a escolher. O único item do pedido vai
                 pré-selecionado em `product_ids`. -->
            <section v-if="!singleItem" class="space-y-4">
                <UiFormHeading icon="catalog" title="Itens do pedido" />

                <ProjectItemsChoice
                    v-model:product-ids="individualForm.product_ids"
                    v-model:mode="individualForm.mode"
                    :options="itemPickerOptions"
                    :entradas="entradasDoPedido"
                    :errors="individualForm.errors"
                />
            </section>

            <UiAlert v-if="individualForm.errors.entitlement" variant="danger">
                {{ individualForm.errors.entitlement }}
            </UiAlert>
        </form>

        <template #footer>
            <UiButton type="submit" :form="individualFormId" :loading="individualForm.processing">
                Cadastrar Colaborador
            </UiButton>
            <UiButton variant="ghost" :disabled="individualForm.processing" @click="customerDrawer = false">
                Cancelar
            </UiButton>
        </template>
    </UiDrawer>

    <ProjectRequestDrawer
        v-if="canRequestKits"
        v-model:open="requestDrawer"
        :project-id="project.id"
        :products="project.products"
        :ask-cpf="project.company.import_columns.includes('cpf')"
    />

    <ProjectAdminDrawer
        v-if="canManageAdmins"
        v-model:open="adminDrawer"
        :project-id="project.id"
        :project-name="project.name"
        :company-name="project.company.name"
        :can-grant-redemption="permissions.grant_admin_redemption"
        :item-options="itemPickerOptions"
        :entradas="entradasDoPedido"
    />

    <AdminRedemptionDrawer
        v-if="permissions.grant_admin_redemption"
        v-model:open="resgateDoAdminAberto"
        :project-id="project.id"
        :admin="adminDoResgate"
        :item-options="itemPickerOptions"
        :entradas="entradasDoPedido"
    />
</template>
