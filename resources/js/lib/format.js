// Rótulos e mapeamentos de status compartilhados entre as telas.
// Nenhum enum cru chega à interface: todo status passa por um mapa daqui.

export const pipelineLabels = {
    design: 'Design',
    production: 'Produção',
    assembly: 'Montagem',
    /*
     * "Estoque Disponível", e o que ela destrava passou para os SUB-STATUS
     * (Pendente de Liberação · Resgates Liberados).
     *
     * O rótulo antigo — "Estoque: pronto para resgate/envio" — tentava contar
     * a história toda numa linha e ainda assim não dizia o que importa: se o
     * resgate já foi aberto. Dizia "pronto para" nos dois casos.
     */
    in_stock: 'Estoque Disponível',
    shipping: 'Envio',
    done: 'Concluído',
};

/**
 * Versão curta para marcadores apertados (timeline, esteira de chevrons),
 * onde o rótulo completo de "Estoque" quebraria em três linhas. Badges,
 * selects e listas continuam com o `pipelineLabels` completo.
 */
export const pipelineShortLabels = {
    ...pipelineLabels,
    in_stock: 'Estoque',
};

/**
 * Ordem da esteira — a timeline do Projeto e o fluxo do painel leem daqui.
 * "Em Estoque" vem ANTES de "Envio": o kit precisa existir para sair.
 * Espelho de `Project::PIPELINE_STATUSES`.
 */
export const pipelineStages = [
    'design', 'production', 'assembly', 'in_stock', 'shipping', 'done',
];

/**
 * Cada etapa com desenho próprio, para a esteira ser reconhecida pela forma e
 * não só pela cor. O mapa vivia copiado em três telas — bastava uma esquecer
 * de acompanhar um estágio novo para a mesma etapa ter dois ícones.
 */
export const pipelineIcons = {
    design: 'stageDesign',
    production: 'stageProduction',
    assembly: 'stageAssembly',
    in_stock: 'stageStock',
    shipping: 'stageShipping',
    done: 'stageDone',
};

/** A trava que ABRE o resgate — o kit chegou fisicamente ao estoque. */
export const STAGE_IN_STOCK = 'in_stock';

/**
 * Estágios em que o kit existe e o resgate pode estar aberto: Em Estoque e
 * Envio (kits saindo; quem ainda não resgatou continua podendo). Espelho de
 * `Project::STOCKED_STATUSES`.
 */
export const STOCKED_STAGES = ['in_stock', 'shipping'];

export const redemptionStatusLabels = {
    confirmed: 'Confirmado',
    shipped: 'Em transporte',
    delivered: 'Entregue',
    canceled: 'Cancelado',
};

export const importRowStatusLabels = {
    pending: 'Recebido',
    blocked_structural: 'Dados incompletos',
    blocked_product: 'Produto não cadastrado',
    order_created: 'Pedido na Omie',
    invoiced: 'NF emitida',
    // Coletado pela Flash e a caminho (não há mais envio de XML: o status
    // nasce do primeiro evento de transporte no rastreio).
    dispatched: 'Em transporte',
    delivered: 'Entregue',
};

/**
 * Nome legível de cada tipo de webhook.
 *
 * O tópico é o nome técnico que a origem manda ("VendaProduto.Incluida"); na
 * tela ele vira o que aconteceu, no português de quem opera. A origem (Omie,
 * Trello…) já aparece ao lado, por isso não se repete aqui.
 */
const webhookTopicLabels = {
    'VendaProduto.Incluida': 'Venda Criada',
    'VendaProduto.Alterada': 'Venda Alterada',
    'VendaProduto.EtapaAlterada': 'Venda Etapa Alterada',
    'VendaProduto.Faturada': 'Venda Faturada',
    'VendaProduto.Cancelada': 'Venda Cancelada',
    'VendaProduto.Excluida': 'Venda Excluída',
    'RecebimentoProduto.Incluido': 'Recebimento Criado',
    'RecebimentoProduto.Alterado': 'Recebimento Alterado',
    'RecebimentoProduto.Concluido': 'Recebimento Concluído',
    'OrdemServico.Incluida': 'Ordem de Serviço Criada',
    'OrdemServico.Alterada': 'Ordem de Serviço Alterada',
    'OrdemServico.EtapaAlterada': 'Ordem de Serviço Etapa Alterada',
    'OrdemServico.Faturada': 'Ordem de Serviço Faturada',
    'Produto.Incluido': 'Produto Criado',
    'Produto.Alterado': 'Produto Alterado',
    'Produto.Excluido': 'Produto Excluído',
    'Produto.AjusteEstoque': 'Estoque Ajustado',
    'Produto.Cadastro': 'Produto Cadastrado',
    'NFe.NotaAutorizada': 'NF Autorizada',
    'NFe.NotaCancelada': 'NF Cancelada',
    'Resgate.Lote': 'Resgates Recebidos',
    'card.moved': 'Card Movido',
};

/** Tópico que ainda não está no mapa: mesmo padrão curto, "Entidade Ação". */
const webhookEntityLabels = {
    VendaProduto: 'Venda',
    RecebimentoProduto: 'Recebimento',
    OrdemServico: 'Ordem de Serviço',
    NFe: 'NF',
    ClienteFornecedor: 'Cliente',
};

const separarPalavras = (texto) => String(texto).replace(/([a-z])([A-Z])/g, '$1 $2');

export function webhookTopicLabel(topic) {
    if (!topic) return 'Sem Tipo';
    if (webhookTopicLabels[topic]) return webhookTopicLabels[topic];

    const [entidade, acao] = String(topic).split('.');
    const nome = webhookEntityLabels[entidade] ?? separarPalavras(entidade);

    return acao ? `${nome} ${separarPalavras(acao)}` : nome;
}

export const importStatusLabels = {
    processing: 'Processando',
    completed: 'Concluída',
    completed_with_blocks: 'Concluída com bloqueios',
    failed: 'Falhou',
};

/*
 * Situação = os mesmos SUB-STATUS que a tela do Projeto mostra ao lado da
 * etapa. Antes a lista dizia "Pronto para liberar" e a tela dizia outra
 * coisa para o mesmo estado, e quem via as duas achava que eram assuntos
 * diferentes.
 */
export const projectReleaseLabels = {
    released: 'Resgates Liberados',
    ready: 'Pendente de Liberação',
    producing: 'Em ciclo produtivo',
};

/*
 * Os rótulos de transportadora saíram daqui: viraram cadastro
 * (Cadastros › Transportadoras) e chegam ao front como prop compartilhada.
 * Quem precisa deles usa `useCarriers()`, que também sabe montar o link de
 * rastreio a partir do código. Um mapa fixo aqui significava editar o código
 * para contratar a quarta transportadora — e esquecer de editar o servidor
 * junto, que era o que fazia ela aparecer na tela e ser recusada ao expedir.
 */

/**
 * Deriva a chave de projectRelease a partir das flags do projeto.
 *
 * Quem manda é o estágio, não mais o sinal de estoque da Omie: o saldo pode
 * existir e o Projeto ainda estar em Montagem — nesse caso não há kit pronto
 * para liberar.
 */
export function releaseState({ released, pipeline_status }) {
    if (released) return 'released';
    return STOCKED_STAGES.includes(pipeline_status) ? 'ready' : 'producing';
}

// --- Datas: dia de calendário x instante -------------------------------------
// Campos como `delivery_deadline` são cast 'date' no model e o app roda em UTC,
// então chegam como "2026-12-12T00:00:00.000000Z": isso é um DIA do calendário,
// não um instante. Passar direto por `new Date()` desloca para 11/12 21h em
// qualquer fuso a oeste de Greenwich (UTC-3 aqui) e o operador vê o prazo um dia
// adiantado — inclusive em "atrasado/vence em N dias". Campos `datetime`
// (created_at, shipped_at, delivered_at, occurred_at...) continuam sendo
// instantes de verdade e seguem convertidos para o fuso local.

/** "2026-12-12" — nenhum componente de hora. */
const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;
/** "2026-12-12T00:00:00.000000Z" — meia-noite UTC exata: data pura serializada. */
const UTC_MIDNIGHT = /^(\d{4})-(\d{2})-(\d{2})[T ]00:00:00(?:\.0+)?(?:Z|\+00:?00)$/;

/**
 * Converte o valor recebido do backend em Date, tratando data pura como dia
 * LOCAL. Nunca devolve o objeto original (o chamador pode mutar com setHours).
 *
 * @param {string|number|Date|null|undefined} value
 * @param {boolean} exactInstant quando true, só strings sem qualquer componente
 *   de hora contam como data pura — quem pediu o horário quer o instante real.
 * @returns {Date|null} data válida ou null.
 */
function parseDateValue(value, exactInstant = false) {
    if (!value) return null;
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : new Date(value.getTime());
    }
    if (typeof value === 'string') {
        const parts = DATE_ONLY.exec(value) ?? (exactInstant ? null : UTC_MIDNIGHT.exec(value));
        if (parts) {
            const [year, month, day] = parts.slice(1).map(Number);
            const local = new Date(year, month - 1, day);
            local.setFullYear(year, month - 1, day); // anos < 100 não viram 19xx
            return Number.isNaN(local.getTime()) ? null : local;
        }
    }
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/**
 * Estado do prazo de entrega. Cor NUNCA sozinha: o texto carrega o significado.
 * @returns {{ label: string, tone: 'default'|'warning'|'danger' }|null}
 */
export function deadlineState(date) {
    const target = parseDateValue(date);
    if (!target) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    target.setHours(0, 0, 0, 0);
    const days = Math.round((target - today) / 86400000);
    if (days < 0) return { label: `Atrasado ${Math.abs(days)} dia${Math.abs(days) > 1 ? 's' : ''}`, tone: 'danger' };
    if (days === 0) return { label: 'Vence hoje', tone: 'warning' };
    if (days === 1) return { label: 'Vence amanhã', tone: 'warning' };
    return { label: `Em ${days} dias`, tone: 'default' };
}

/* `toLocaleDateString` monta um Intl.DateTimeFormat a cada chamada. Uma lista
   de resgates com 100 linhas e duas colunas de data faz isso 200 vezes por
   render. Construídos uma vez aqui, e com o formato explícito em vez do padrão
   implícito da locale. */
const dateFormat = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
const timeFormat = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' });

export function formatDate(value, withTime = false) {
    const d = parseDateValue(value, withTime);
    if (!d) return '-';
    const date = dateFormat.format(d);
    return withTime ? `${date} ${timeFormat.format(d)}` : date;
}

/* Estoque vem da Omie como decimal(10,2) e quase sempre é inteiro: "120" lê
   melhor que "120,00". A casa decimal só aparece quando existe de fato. */
const quantityFormat = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 });

export function formatQuantity(value) {
    const number = Number(value);
    return Number.isFinite(number) ? quantityFormat.format(number) : '-';
}

export function formatCnpj(value) {
    if (!value) return '';
    return value.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
}

export function formatCpf(value) {
    if (!value) return '';
    return String(value).replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
}

/** CPF ou CNPJ conforme o tamanho — a base de empresas mistura os dois. */
export function formatDocument(value) {
    const digits = String(value ?? '').replace(/\D/g, '');
    if (digits.length === 11) return formatCpf(digits);
    if (digits.length === 14) return formatCnpj(digits);
    return String(value ?? '');
}

export function formatPhone(value) {
    const digits = String(value ?? '').replace(/\D/g, '');
    if (digits.length === 11) return digits.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
    if (digits.length === 10) return digits.replace(/^(\d{2})(\d{4})(\d{4})$/, '($1) $2-$3');
    return String(value ?? '');
}

export function formatCep(value) {
    const digits = String(value ?? '').replace(/\D/g, '');
    return digits.length === 8 ? digits.replace(/^(\d{5})(\d{3})$/, '$1-$2') : String(value ?? '');
}

// --- Máscaras de DIGITAÇÃO (progressivas) ------------------------------------
// Padrão do sistema: todo campo de documento/telefone/CEP mascara enquanto a
// pessoa digita. Uso: <UiInput :model-value="form.cnpj" @update:model-value="form.cnpj = maskCnpj($event)" />
// ou v-model normal + watch. São puras e idempotentes: aceitam valor já mascarado.

export function maskCpf(value) {
    const d = String(value ?? '').replace(/\D/g, '').slice(0, 11);
    return d
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
}

export function maskCnpj(value) {
    const d = String(value ?? '').replace(/\D/g, '').slice(0, 14);
    return d
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/^(\d{2})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3/$4')
        .replace(/^(\d{2})\.(\d{3})\.(\d{3})\/(\d{4})(\d)/, '$1.$2.$3/$4-$5');
}

/** CPF até 11 dígitos; do 12º em diante assume CNPJ. Para campos que aceitam os dois. */
export function maskDocument(value) {
    const d = String(value ?? '').replace(/\D/g, '');
    return d.length > 11 ? maskCnpj(d) : maskCpf(d);
}

export function maskPhone(value) {
    const d = String(value ?? '').replace(/\D/g, '').slice(0, 11);
    if (d.length <= 10) {
        return d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/^\((\d{2})\) (\d{4})(\d)/, '($1) $2-$3');
    }
    return d.replace(/^(\d{2})(\d)/, '($1) $2').replace(/^\((\d{2})\) (\d{5})(\d)/, '($1) $2-$3');
}

export function maskCep(value) {
    const d = String(value ?? '').replace(/\D/g, '').slice(0, 8);
    return d.replace(/^(\d{5})(\d)/, '$1-$2');
}

/** Primeiro nome — saudação do portal. */
export function firstName(name) {
    return String(name ?? '')
        .trim()
        .split(/\s+/)[0] ?? '';
}

export const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA',
    'PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
