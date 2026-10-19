<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import UiAlert from '../../../Components/UiAlert.vue';
import UiButton from '../../../Components/UiButton.vue';
import UiCard from '../../../Components/UiCard.vue';
import UiDescriptionList from '../../../Components/UiDescriptionList.vue';
import UiField from '../../../Components/UiField.vue';
import UiInput from '../../../Components/UiInput.vue';
import UiPanel from '../../../Components/UiPanel.vue';
import UiRefreshButton from '../../../Components/UiRefreshButton.vue';
import UiSelect from '../../../Components/UiSelect.vue';
import UiTabs from '../../../Components/UiTabs.vue';
import UiTextarea from '../../../Components/UiTextarea.vue';
import { useConfirm } from '../../../composables/useConfirm';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    // Alcance aberto: geral, um cliente, ou uma campanha dele.
    escopo: { type: Object, default: () => ({ nivel: 'geral', rotulo: 'Geral (todos os clientes)' }) },
    empresas: { type: Array, default: () => [] },
    projetos: { type: Array, default: () => [] },
});

// ── Alcance ─────────────────────────────────────────────────────────────
// A mesma pergunta ("o que esta mensagem diz?") em três recortes. O de cima é
// o piso de todo envio; os de baixo sobrepõem só onde foram escritos.
const empresaEscolhida = ref(String(props.escopo.company_id ?? 0));
const projetoEscolhido = ref(String(props.escopo.project_id ?? 0));

const opcoesDeEmpresa = computed(() => [
    { value: '0', label: 'Geral (todos os clientes)' },
    ...props.empresas.map((e) => ({ value: String(e.id), label: e.nome })),
]);

const opcoesDeProjeto = computed(() => [
    { value: '0', label: 'Todos os Projetos do cliente' },
    ...props.projetos.map((p) => ({ value: String(p.id), label: p.nome })),
]);

function irParaEscopo(query) {
    router.get(route('admin.email-templates.index'), query, { preserveScroll: true });
}

function trocarEmpresa(valor) {
    empresaEscolhida.value = valor;
    // Projeto é de um cliente só: trocar de cliente sem largar o Projeto
    // abriria a campanha de outra empresa.
    projetoEscolhido.value = '0';
    irParaEscopo(valor === '0' ? {} : { empresa: valor });
}

function trocarProjeto(valor) {
    projetoEscolhido.value = valor;
    irParaEscopo(valor === '0' ? { empresa: empresaEscolhida.value } : { projeto: valor });
}

/** Query do alcance, para as escritas caírem no nível certo. */
const escopoQuery = computed(() => {
    if (props.escopo.project_id) return { projeto: props.escopo.project_id };
    if (props.escopo.company_id) return { empresa: props.escopo.company_id };

    return {};
});

// ── Público ─────────────────────────────────────────────────────────────
// A quem cada mensagem se dirige. Não é escolha: é fato do evento — o aviso de
// etapa fala com quem administra o cliente, o de presente liberado fala com
// quem ganha o presente. O filtro existe para não misturar as duas vozes.
const PUBLICOS = [
    { key: 'todos', label: 'Todos' },
    { key: 'collaborator', label: 'Colaborador' },
    { key: 'client_admin', label: 'Admin Cliente' },
];

const AUDIENCE_LABELS = {
    collaborator: 'Colaborador',
    client_admin: 'Admin Cliente',
};

const publico = ref('todos');

const visiveis = computed(() => props.templates.filter(
    (t) => publico.value === 'todos' || t.audience === publico.value,
));

const abasDePublico = computed(() => PUBLICOS.map((p) => ({
    ...p,
    count: p.key === 'todos'
        ? props.templates.length
        : props.templates.filter((t) => t.audience === p.key).length,
})));

/** Valores de exemplo da prévia. Nada disto vai para o envio real. */
const SAMPLE = {
    userName: 'Maria Silva',
    companyName: 'Suvinil',
    productName: 'Kit Boas-vindas',
    address: 'Rua XV de Novembro, 100, Curitiba/PR',
    eventTitle: 'Objeto em trânsito',
    eventDescription: 'Objeto encaminhado para a unidade de distribuição',
    trackingCode: 'BR123456789BR',
    trackingUrl: 'https://rastreamento.correios.com.br/app/index.php?objeto=BR123456789BR',
    rastreio: 'BR123456789BR · https://rastreamento.correios.com.br/app/index.php?objeto=BR123456789BR',
    carrier: 'Correios',
};

// Mesmo casamento do `EmailTemplate::substitute` do backend.
const TOKEN = /\{\{\s*(\w+)\s*\}\}/g;

// Escrito no script para não virar interpolação do próprio template.
const tag = (name) => `{{ ${name} }}`;

const activeKey = ref(props.templates[0]?.key ?? '');

const active = computed(
    () => visiveis.value.find((t) => t.key === activeKey.value) ?? visiveis.value[0] ?? null,
);

// Filtrar por público pode tirar da lista o modelo aberto: sem isto o editor
// continuaria mostrando uma mensagem que a aba selecionada não contém.
watch(visiveis, (lista) => {
    if (lista.length && !lista.some((t) => t.key === activeKey.value)) {
        activeKey.value = lista[0].key;
    }
});

const variables = computed(() => active.value?.variables ?? []);
const known = computed(() => new Set(variables.value.map((v) => v.name)));

const tabs = computed(() => visiveis.value.map((t) => ({ key: t.key, label: t.name })));

const form = useForm({ subject: '', body: '' });
const restoreForm = useForm({});

/**
 * Reidratação a partir das props. Os formulários eram montados uma única vez
 * no setup e nunca voltavam a olhar para os dados: "Restaurar padrão" gravava
 * o padrão no banco e deixava o texto antigo na tela — bastava clicar em
 * Salvar para regravar exatamente o que se acabou de descartar.
 *
 * Só reidrata quando o texto do servidor mudou de fato. Uma recusa de
 * validação devolve as props intactas, e recarregar ali apagaria o que a
 * pessoa acabou de escrever — junto com a chance de corrigir o erro.
 */
let loaded = { key: null, subject: null, body: null };

function hydrate() {
    const template = active.value;

    if (!template) return;
    if (
        loaded.key === template.key
        && loaded.subject === template.subject
        && loaded.body === template.body
    ) {
        return;
    }

    loaded = { key: template.key, subject: template.subject, body: template.body };

    form.defaults({ subject: template.subject, body: template.body });
    form.reset();
    form.clearErrors();
}

watch([() => props.templates, activeKey], hydrate, { immediate: true });

// --- variáveis inexistentes -------------------------------------------------

const tokensOf = (text) => [...String(text ?? '').matchAll(TOKEN)].map((match) => match[1]);

/** Distância de Levenshtein — só para sugerir a variável certa. */
function distance(a, b) {
    const row = Array.from({ length: b.length + 1 }, (_, i) => i);

    for (let i = 1; i <= a.length; i += 1) {
        let diagonal = row[0];
        row[0] = i;

        for (let j = 1; j <= b.length; j += 1) {
            const previous = row[j];
            row[j] = Math.min(row[j] + 1, row[j - 1] + 1, diagonal + (a[i - 1] === b[j - 1] ? 0 : 1));
            diagonal = previous;
        }
    }

    return row[b.length];
}

function nearest(name) {
    const limit = Math.max(2, Math.floor(name.length / 3));
    let best = null;

    variables.value.forEach((variable) => {
        const gap = distance(name.toLowerCase(), variable.name.toLowerCase());

        if (gap <= limit && (best === null || gap < best.gap)) best = { name: variable.name, gap };
    });

    return best?.name ?? null;
}

/**
 * Token que não existe no modelo sai como string vazia no envio: "Olá,
 * {{ userNome }}" chega como "Olá, !" para centenas de pessoas. Nada entre o
 * erro de digitação e a caixa de entrada — por isso ele bloqueia o Salvar.
 */
const unknownTokens = computed(() => {
    const names = [...new Set([...tokensOf(form.subject), ...tokensOf(form.body)])];

    return names
        .filter((name) => !known.value.has(name))
        .map((name) => ({ name, suggestion: nearest(name) }));
});

// --- prévia -----------------------------------------------------------------

function substitute(text) {
    return String(text ?? '').replace(TOKEN, (match, name) => {
        if (!known.value.has(name)) return ''; // é o que o envio faz hoje
        return SAMPLE[name] ?? match;
    });
}

const HEADINGS = [
    { prefix: '## ', class: 'text-sm font-semibold text-ink' },
    { prefix: '# ', class: 'text-base font-semibold text-ink' },
];

/** Quebra a linha em trechos normais e em negrito (`**assim**`). */
const toParts = (line) =>
    line
        .split(/\*\*(.+?)\*\*/g)
        .map((text, index) => ({ text, bold: index % 2 === 1 }))
        .filter((part) => part.text !== '');

/** Markdown mínimo: `#`, `##`, `**negrito**`, parágrafos e quebras. */
function toBlocks(text) {
    return String(text ?? '')
        .split(/\n{2,}/)
        .map((chunk) => chunk.trim())
        .filter(Boolean)
        .map((chunk) => {
            // {botao}: onde o botão do e-mail aparece.
            if (chunk === '{botao}') return { button: true, lines: [] };

            const heading = HEADINGS.find((item) => chunk.startsWith(item.prefix));
            const content = heading ? chunk.slice(heading.prefix.length) : chunk;

            return {
                class: heading?.class ?? 'text-sm text-ink',
                lines: content.split('\n').map(toParts),
            };
        });
}

const previewBlocks = computed(() => toBlocks(substitute(form.body)));

const previewHeader = computed(() => [
    { key: 'de', label: 'De', value: 'WeLog' },
    { key: 'para', label: 'Para', value: SAMPLE.userName },
    { key: 'assunto', label: 'Assunto', value: substitute(form.subject) },
]);

// --- inserção na posição do cursor ------------------------------------------

const subjectRef = ref(null);
const bodyRef = ref(null);

// UiInput e UiTextarea envolvem o controle num <div>; o cursor mora no
// elemento real, não no componente.
function controlOf(field) {
    const holder = field === 'subject' ? subjectRef.value : bodyRef.value;

    return holder?.$el?.querySelector(field === 'subject' ? 'input' : 'textarea') ?? null;
}

function insert(field, name) {
    const token = tag(name);
    const text = String(form[field] ?? '');
    const control = controlOf(field);

    if (!control) {
        form[field] = text + token;

        return;
    }

    const start = control.selectionStart ?? text.length;
    const end = control.selectionEnd ?? start;

    form[field] = text.slice(0, start) + token + text.slice(end);

    nextTick(() => {
        control.focus();
        control.setSelectionRange(start + token.length, start + token.length);
    });
}

// --- ações ------------------------------------------------------------------

const confirm = useConfirm();

function confirmDiscard() {
    return confirm({
        title: `Descartar as alterações em "${active.value?.name}"?`,
        description: 'O texto volta ao que está publicado hoje. Nenhum e-mail muda por causa disto.',
        confirmLabel: 'Descartar alterações',
        variant: 'danger',
    });
}

async function selectTemplate(key) {
    if (key === activeKey.value) return;
    if (form.isDirty && !(await confirmDiscard())) return;

    activeKey.value = key;
}

async function discard() {
    if (!form.isDirty) return;
    if (await confirmDiscard()) form.reset();
}

function save() {
    if (!active.value || unknownTokens.value.length) return;

    form.put(
        route('admin.email-templates.update', { key: active.value.key, ...escopoQuery.value }),
        { preserveScroll: true },
    );
}

function restoreTemplate(key) {
    restoreForm.delete(route('admin.email-templates.restore', { key, ...escopoQuery.value }), {
        preserveScroll: true,
        // O padrão pode ser idêntico ao que já estava gravado: forçar a
        // releitura garante que o editor nunca fique com o texto descartado.
        onSuccess: () => {
            loaded = { key: null, subject: null, body: null };
            hydrate();
        },
    });
}

async function restore() {
    const template = active.value;

    // No nível geral não há de onde herdar: o texto volta ao de fábrica. Nos
    // outros, a personalização é apagada e o nível de cima reassume.
    const geral = props.escopo.nivel === 'geral';

    await confirm({
        title: geral
            ? `Voltar "${template.name}" ao padrão?`
            : `Remover a personalização de "${template.name}"?`,
        description: geral
            ? 'O assunto e a mensagem atuais são substituídos pelo texto padrão do sistema. Vale para todos os envios a partir de agora.'
            : `Este alcance deixa de ter texto próprio e volta a usar o ${template.inherited_from}. Vale para os envios a partir de agora.`,
        warning: form.isDirty ? 'As alterações não salvas também serão perdidas.' : null,
        confirmLabel: geral ? 'Voltar ao padrão' : 'Remover personalização',
        variant: 'danger',
        errorBag: 'restore',
        action: () => restoreTemplate(template.key),
    });
}

// --- guarda de saída --------------------------------------------------------

// A confirmação é assíncrona e o evento `before` é síncrono: a visita é
// cancelada e refeita depois do "sim".
let bypass = false;

const stopGuard = router.on('before', (event) => {
    const visit = event.detail.visit;
    const method = (visit?.method ?? 'get').toLowerCase();

    // Salvar e restaurar são escritas desta própria tela, não saída dela.
    // Prefetch (passar o mouse por um link) também não é saída.
    if (bypass || visit?.prefetch || method !== 'get' || !form.isDirty) return;

    confirmDiscard().then((ok) => {
        if (!ok) return;

        bypass = true;
        router.visit(visit.url, {
            replace: visit.replace,
            preserveScroll: visit.preserveScroll,
            preserveState: visit.preserveState,
            onFinish: () => (bypass = false),
        });
    });

    return false;
});

function warnOnUnload(event) {
    if (form.isDirty) event.preventDefault();
}

onMounted(() => window.addEventListener('beforeunload', warnOnUnload));

onBeforeUnmount(() => {
    stopGuard();
    window.removeEventListener('beforeunload', warnOnUnload);
});

// Abaixo de lg a prévia não cabe ao lado do editor: vira a segunda aba.
const view = ref('editar');

const viewTabs = [
    { key: 'editar', label: 'Editar' },
    { key: 'previa', label: 'Prévia' },
];
</script>

<template>
    <Head title="Modelos de E-mail" />

    <PageHeader
        title="Modelos de E-mail"
        subtitle="O que cada pessoa recebe em cada etapa. Vale para os envios a partir de agora."
    >
        <template #actions>
            <UiRefreshButton />
        </template>

        <template #tabs>
            <UiTabs
                :model-value="activeKey"
                :tabs="tabs"
                panel-prefix="modelo"
                @update:model-value="selectTemplate"
            />
        </template>
    </PageHeader>

    <!-- Alcance: a mesma mensagem em três recortes. O de cima é o piso de todo
         envio; os de baixo sobrepõem só onde foram escritos. -->
    <UiPanel tone="muted" class="mb-4">
        <div class="flex flex-wrap items-end gap-4">
            <UiField label="Personalizar para" class="w-72">
                <UiSelect
                    :model-value="empresaEscolhida"
                    :options="opcoesDeEmpresa"
                    @update:model-value="trocarEmpresa"
                />
            </UiField>

            <!-- Projeto só existe dentro de um cliente. -->
            <UiField v-if="empresaEscolhida !== '0'" label="Campanha" class="w-72">
                <UiSelect
                    :model-value="projetoEscolhido"
                    :options="opcoesDeProjeto"
                    @update:model-value="trocarProjeto"
                />
            </UiField>

            <p class="text-sm text-ink-muted">
                <template v-if="escopo.nivel === 'geral'">
                    Este é o texto base. Vale para todo cliente que não tiver o seu.
                </template>
                <template v-else-if="escopo.nivel === 'empresa'">
                    Vale para todos os Projetos de {{ escopo.rotulo }}, exceto os que tiverem texto próprio.
                </template>
                <template v-else>
                    Vale só para a campanha {{ escopo.rotulo }}.
                </template>
            </p>
        </div>
    </UiPanel>

    <!-- Público: a quem a mensagem se dirige. Não é escolha, é fato do evento;
         o filtro existe para não misturar as duas vozes na mesma lista. -->
    <UiTabs
        v-model="publico"
        :tabs="abasDePublico"
        variant="segmented"
        panel-prefix="publico"
        class="mb-4"
    />

    <!-- Estado desta mensagem NESTE alcance: texto próprio ou herdado. Sem
         isto, o editor mostra um texto e não diz se ele já foi escrito aqui ou
         se está só repetindo o nível de cima. -->
    <div v-if="active" class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        <span v-if="active.audience" class="text-ink-muted">
            Esta mensagem vai para
            <span class="font-medium text-ink">{{ AUDIENCE_LABELS[active.audience] }}</span>.
        </span>

        <template v-if="escopo.nivel !== 'geral'">
            <span v-if="active.customized" class="font-medium text-success">Texto próprio deste alcance.</span>
            <span v-else class="text-ink-muted">
                Ainda sem texto próprio: hoje vale o {{ active.inherited_from }}. Salvar cria a personalização.
            </span>
        </template>
    </div>

    <section
        v-if="active"
        :id="`modelo-${activeKey}`"
        role="tabpanel"
        tabindex="0"
        :aria-labelledby="`modelo-aba-${activeKey}`"
    >
        <form @submit.prevent="save">
            <UiTabs
                v-model="view"
                :tabs="viewTabs"
                variant="segmented"
                panel-prefix="vista"
                class="mb-4 lg:hidden"
            />

            <div class="grid gap-5 lg:grid-cols-2">
                <div
                    id="vista-editar"
                    role="tabpanel"
                    tabindex="0"
                    aria-labelledby="vista-aba-editar"
                    :class="['space-y-4', view === 'editar' ? '' : 'hidden lg:block']"
                >
                    <UiField label="Assunto" :error="form.errors.subject" required>
                        <UiInput
                            ref="subjectRef"
                            v-model="form.subject"
                            maxlength="255"
                            :invalid="!!form.errors.subject"
                        />
                    </UiField>

                    <div>
                        <p class="text-xs font-medium text-ink-muted">Inserir no assunto</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            <button
                                v-for="variable in variables"
                                :key="variable.name"
                                type="button"
                                class="h-control-sm rounded-control border border-line bg-surface-muted px-2 font-mono text-xs text-ink transition-colors hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700"
                                :aria-label="`Inserir ${tag(variable.name)} no assunto: ${variable.description}`"
                                @mousedown.prevent
                                @click="insert('subject', variable.name)"
                            >
                                {{ tag(variable.name) }}
                            </button>
                        </div>
                    </div>

                    <UiField
                        label="Mensagem"
                        :error="form.errors.body"
                        hint="Aceita # títulos, **negrito** e as variáveis abaixo."
                        required
                    >
                        <UiTextarea
                            ref="bodyRef"
                            v-model="form.body"
                            mono
                            :rows="12"
                            :maxlength="5000"
                            counter
                            :invalid="!!form.errors.body"
                        />
                    </UiField>

                    <div>
                        <p class="text-xs font-medium text-ink-muted">
                            Inserir na mensagem (entra onde o cursor está)
                        </p>
                        <ul class="mt-1.5 grid gap-1.5 sm:grid-cols-2">
                            <li v-for="variable in variables" :key="variable.name">
                                <button
                                    type="button"
                                    class="min-h-control-sm flex w-full flex-col items-start justify-center gap-0.5 rounded-control border border-line bg-surface px-2 py-1.5 text-left transition-colors hover:border-brand-200 hover:bg-brand-50"
                                    @mousedown.prevent
                                    @click="insert('body', variable.name)"
                                >
                                    <span class="font-mono text-xs text-ink">{{ tag(variable.name) }}</span>
                                    <span class="text-xs text-ink-muted">{{ variable.description }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <UiAlert
                        v-if="unknownTokens.length"
                        variant="danger"
                        title="Variável que não existe neste modelo"
                    >
                        <ul class="space-y-1">
                            <li v-for="item in unknownTokens" :key="item.name">
                                <span class="font-mono">{{ tag(item.name) }}</span>
                                não existe neste modelo, sairia em branco no e-mail.
                                <template v-if="item.suggestion">
                                    Você quis dizer
                                    <span class="font-mono">{{ tag(item.suggestion) }}</span>?
                                </template>
                            </li>
                        </ul>
                    </UiAlert>
                </div>

                <div
                    id="vista-previa"
                    role="tabpanel"
                    tabindex="0"
                    aria-labelledby="vista-aba-previa"
                    :class="view === 'previa' ? '' : 'hidden lg:block'"
                >
                    <UiCard title="Prévia · dados de exemplo" subtitle="Os valores são fictícios.">
                        <UiDescriptionList :items="previewHeader" layout="stacked" />

                        <div class="mt-4 space-y-3 border-t border-line pt-4">
                            <template v-for="(block, index) in previewBlocks" :key="index">
                            <p v-if="block.button" class="flex justify-center py-1">
                                <span class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-ink-inverse">
                                    Botão do e-mail
                                </span>
                            </p>
                            <p v-else :class="block.class">
                                <template v-for="(line, lineIndex) in block.lines" :key="lineIndex">
                                    <br v-if="lineIndex > 0" />
                                    <template v-for="(part, partIndex) in line" :key="partIndex">
                                        <strong v-if="part.bold">{{ part.text }}</strong>
                                        <template v-else>{{ part.text }}</template>
                                    </template>
                                </template>
                            </p>
                            </template>

                            <p v-if="!previewBlocks.length" class="text-sm text-ink-muted">
                                A mensagem está vazia.
                            </p>
                        </div>
                    </UiCard>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                <UiButton
                    type="submit"
                    :loading="form.processing"
                    :disabled="unknownTokens.length > 0"
                >
                    Salvar e usar nos próximos envios
                </UiButton>

                <UiButton variant="ghost" :disabled="!form.isDirty" @click="discard">
                    Descartar
                </UiButton>

                <p v-if="unknownTokens.length" class="text-xs text-ink-muted">
                    Corrija a variável acima para poder salvar.
                </p>

                <!-- Sem texto próprio não há o que remover, e no nível geral o
                     botão só faz sentido se alguém mexeu no texto de fábrica. -->
                <UiButton
                    v-if="escopo.nivel === 'geral' ? active.differs_from_factory : active.customized"
                    variant="danger-soft"
                    class="ml-auto"
                    :loading="restoreForm.processing"
                    @click="restore"
                >
                    {{ escopo.nivel === 'geral' ? 'Voltar ao padrão' : 'Remover personalização' }}
                </UiButton>
            </div>
        </form>
    </section>
</template>
