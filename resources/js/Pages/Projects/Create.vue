<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import UiAlert from '../../Components/UiAlert.vue';
import UiAutocomplete from '../../Components/UiAutocomplete.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCheckboxGroup from '../../Components/UiCheckboxGroup.vue';
import UiDrawer from '../../Components/UiDrawer.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiField from '../../Components/UiField.vue';
import UiFormHeading from '../../Components/UiFormHeading.vue';
import UiHintCard from '../../Components/UiHintCard.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiInput from '../../Components/UiInput.vue';
import UiPanel from '../../Components/UiPanel.vue';
import UiStepper from '../../Components/UiStepper.vue';
import { useCepLookup } from '../../composables/useCepLookup';
import { formatDate, formatDocument, maskDocument } from '../../lib/format';

const props = defineProps({
    products: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    company_name: '',
    company_cnpj: '',
    quantity: 1,
    delivery_deadline: '',
    product_ids: [],
    // A ficha do cliente vai junto: endereço é para onde a nota e a cobrança
    // vão, e empresa criada aqui nascia só com nome e documento.
    company: {
        zip: '',
        street: '',
        number: '',
        complement: '',
        district: '',
        city: '',
        state: '',
    },
    // Quem opera o cliente do outro lado. Vazio quando a empresa já tem um.
    admin: { name: '', email: '' },
});

// O código Omie vai como `hint` para a busca do grupo achar tanto por nome
// quanto por código. Pai de grade avisa que o tamanho sai do portal: os
// tamanhos não aparecem na lista.
const productOptions = computed(() =>
    props.products.map((product) => ({
        value: product.id,
        label: product.name,
        hint: product.grade ? `${product.omie_code} · tamanho escolhido no portal` : product.omie_code,
    })),
);

const hasCatalog = computed(() => props.products.length > 0);

// --- Sugestão de empresa (nome ou CNPJ) ---------------------------------
// Os dois campos consultam a mesma rota: quem lembra o nome digita o nome,
// quem tem a nota na mão digita o CNPJ. Escolher preenche ambos.
//
// `known` guarda toda empresa que já passou pela busca (por documento) para a
// tela dizer, antes do envio, se vai vincular a uma existente ou criar uma
// nova — e o que falta na ficha de quem já existe.
const known = ref({});

async function fetchCompanies(term, { signal } = {}) {
    const response = await fetch(route('companies.lookup', { q: term }), {
        headers: { Accept: 'application/json' },
        signal,
    });
    if (!response.ok) return [];

    const companies = await response.json();
    companies.forEach((company) => (known.value[company.cnpj] = company));

    return companies;
}

function applyCompany(company) {
    known.value[company.cnpj] = company;
    // Nome provisório é o CNPJ no lugar da razão social: copiá-lo para o campo
    // de nome seria dar por preenchido justamente o que falta.
    form.company_name = company.nome_provisorio ? '' : company.name;
    form.company_cnpj = formatDocument(company.cnpj);
    aplicarEndereco(company);
    form.clearErrors('company_name', 'company_cnpj');
}

/** Traz para o formulário o endereço que a empresa já tem, sem apagar o digitado. */
function aplicarEndereco(company) {
    ['zip', 'street', 'number', 'complement', 'district', 'city', 'state'].forEach((campo) => {
        if (company[campo]) form.company[campo] = company[campo];
    });
}

const documentDigits = computed(() => (form.company_cnpj ?? '').replace(/\D/g, ''));
const documentComplete = computed(() => [11, 14].includes(documentDigits.value.length));
const existingCompany = computed(() => known.value[documentDigits.value] ?? null);

// ── Ficha do cliente ─────────────────────────────────────────────────────
// Empresa vinda do pedido da Omie chega com documento e pouco mais. Criar o
// Projeto em cima dela deixava um cliente pela metade, e ninguém voltava para
// completar: a ficha abre aqui, preenchida com o que existe.
const fichaAberta = ref(false);

// Desestruturado de propósito: acessado como `cep.error` no template, o ref
// não é desembrulhado e a mensagem sairia como "[object Object]".
const { error: cepErro, hint: cepDica, lookup: buscarCep } = useCepLookup(form.company);

const ENDERECO = ['zip', 'street', 'number', 'district', 'city', 'state'];

const enderecoOk = computed(() => ENDERECO.every((campo) => String(form.company[campo] ?? '').trim() !== ''));

/** Empresa sem responsável precisa de um: é quem opera o cliente. */
const precisaAdmin = computed(() => existingCompany.value === null || existingCompany.value.has_admin !== true);

const adminOk = computed(
    () => ! precisaAdmin.value || (form.admin.name.trim() !== '' && form.admin.email.trim() !== ''),
);

const fichaOk = computed(() => form.company_name.trim() !== '' && enderecoOk.value && adminOk.value);

/**
 * Abre a ficha sozinha quando o documento fica completo e o cadastro não está
 * de pé — empresa nova, ou existente com endereço faltando ou sem admin.
 *
 * A consulta acontece antes de decidir: sem ela, uma empresa que existe seria
 * tratada como nova só porque ninguém tinha digitado o nome dela ainda.
 */
const documentoResolvido = ref('');

watch(documentComplete, async (completo) => {
    if (! completo) return;

    const digits = documentDigits.value;
    if (documentoResolvido.value === digits) return;
    documentoResolvido.value = digits;

    await fetchCompanies(digits);

    const empresa = known.value[digits];
    if (empresa) applyCompany(empresa);

    if (! fichaOk.value) fichaAberta.value = true;
});

// Situação da empresa, para a pessoa saber o que vai acontecer ao salvar.
const companyOutcome = computed(() => {
    if (! documentComplete.value) return null;

    const empresa = existingCompany.value;

    if (empresa === null) {
        return {
            variant: 'info',
            title: 'Empresa nova',
            text: 'Este CNPJ não está na base. A empresa é cadastrada junto com o Projeto, com a ficha preenchida abaixo.',
        };
    }

    if (empresa.pendencias?.length) {
        return {
            variant: 'warning',
            title: 'Empresa cadastrada pela metade',
            text: `${empresa.rotulo ?? empresa.name} já existe, mas falta ${empresa.pendencias.join(', ')}. Complete a ficha para seguir.`,
        };
    }

    return {
        variant: 'success',
        title: 'Empresa já cadastrada',
        text: `O Projeto será vinculado a ${empresa.rotulo ?? empresa.name}.`,
    };
});

const resumoEndereco = computed(() => {
    const c = form.company;
    if (! enderecoOk.value) return null;

    const logradouro = [c.street, c.number].filter(Boolean).join(', ');

    return [logradouro, c.complement, c.district, `${c.city}/${c.state}`].filter(Boolean).join(', ');
});

// ── Etapas ──────────────────────────────────────────────────────────────
// O formulário é uma página só; a lateral mostra onde a pessoa está. O
// "Resumo" é uma visão de conferência antes do envio, porque criar Projeto
// também pode cadastrar empresa e abre card no Trello.
const review = ref(false);

const companyDone = computed(() => documentComplete.value && fichaOk.value);
const projectDone = computed(() => form.name.trim() !== '' && Number(form.quantity) >= 1);
const kitsDone = computed(() => form.product_ids.length > 0);
const allDone = computed(() => companyDone.value && projectDone.value && kitsDone.value);

const steps = computed(() => [
    { n: 1, title: 'Ficha do cliente', hint: 'Empresa, endereço e responsável', done: companyDone.value },
    { n: 2, title: 'Dados do projeto', hint: 'Informações principais', done: projectDone.value },
    { n: 3, title: 'Kits e produtos', hint: 'Selecione os itens do pedido', done: kitsDone.value },
    { n: 4, title: 'Resumo', hint: 'Confira e finalize', done: false },
]);

const currentStep = computed(() => {
    if (review.value) return 4;
    if (! companyDone.value) return 1;
    if (! projectDone.value) return 2;

    return 3;
});

const missing = computed(() => {
    const partes = [];
    if (! documentComplete.value) partes.push('o CNPJ da empresa');
    else if (! fichaOk.value) partes.push('a ficha do cliente');
    if (! projectDone.value) partes.push('o nome do projeto');
    if (! kitsDone.value) partes.push('ao menos um kit');

    return partes.join(', ');
});

const selectedProducts = computed(() =>
    props.products.filter((product) => form.product_ids.includes(product.id)),
);

/**
 * Erros da ficha voltam do servidor com chave aninhada (`company.zip`): sem
 * reabrir o drawer, a mensagem ficaria num formulário fechado e o botão
 * pareceria não fazer nada.
 */
const erroNaFicha = computed(() =>
    Object.keys(form.errors).some((campo) => campo.startsWith('company.') || campo.startsWith('admin.')),
);

function submit() {
    form.post(route('projects.store'), {
        // Erro de validação volta para o formulário, onde os campos estão.
        onError: () => {
            review.value = false;
            if (erroNaFicha.value) fichaAberta.value = true;
        },
    });
}
</script>

<template>
    <Head title="Criar Projeto" />

    <!-- Cabeçalho no desenho aprovado: breadcrumb, voltar, título e a dica. -->
    <nav class="text-xs text-ink-muted" aria-label="Você está em">
        <Link :href="route('projects.index')" class="hover:text-ink">Projetos</Link>
        <span aria-hidden="true"> / </span>
        <span class="text-ink">Novo Projeto</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-4 pb-5">
        <div class="flex items-start gap-3">
            <Link
                :href="route('projects.index')"
                class="mt-1 flex size-9 shrink-0 items-center justify-center rounded-full border border-line bg-surface text-ink-muted transition-colors hover:text-ink"
                aria-label="Voltar para Projetos"
            >
                <UiIcon name="chevron-left" :size="18" />
            </Link>
            <div>
                <h1 class="text-title font-bold text-ink">Criar Projeto</h1>
                <p class="mt-1 text-sm text-ink-muted">
                    Use apenas quando o Pedido de Venda não chegou da Omie. Normalmente os Projetos nascem sozinhos.
                </p>
            </div>
        </div>

        <UiHintCard>
            Comece pelo CNPJ: a ficha do cliente abre sozinha quando a empresa é nova ou está incompleta.
        </UiHintCard>
    </div>

    <div class="grid items-start gap-4 lg:grid-cols-[260px_minmax(0,1fr)]">
        <!-- Etapas -->
        <div class="space-y-4">
            <UiStepper :steps="steps" :current="currentStep" />

            <div class="flex items-start gap-2.5 rounded-card bg-surface shadow-card p-4">
                <UiIcon name="bell" :size="16" class="mt-0.5 shrink-0 text-ink-muted" />
                <p class="text-xs text-ink-muted">
                    Precisa de ajuda? Fale com o suporte da WeGift.
                </p>
            </div>
        </div>

        <!-- Formulário -->
        <div class="rounded-card bg-surface shadow-card">
            <form v-show="!review" class="space-y-8 p-6" @submit.prevent="review = allDone">
                <section aria-labelledby="secao-empresa">
                    <UiFormHeading id="secao-empresa" icon="companies" title="Ficha do cliente" />

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <UiField label="CNPJ" required :error="form.errors.company_cnpj">
                            <UiAutocomplete
                                :model-value="form.company_cnpj"
                                :fetch="fetchCompanies"
                                inputmode="numeric"
                                placeholder="00.000.000/0000-00"
                                @update:model-value="form.company_cnpj = maskDocument($event)"
                                @select="applyCompany"
                            >
                                <template #option="{ item }">
                                    <span class="block truncate font-medium tabular-nums">{{ formatDocument(item.cnpj) }}</span>
                                    <span class="block truncate text-xs text-ink-muted">{{ item.rotulo ?? item.name }}</span>
                                </template>
                            </UiAutocomplete>
                        </UiField>

                        <UiField
                            label="Razão social"
                            required
                            :error="form.errors.company_name"
                            hint="Digite parte do nome para ver empresas já cadastradas."
                        >
                            <UiAutocomplete
                                v-model="form.company_name"
                                :fetch="fetchCompanies"
                                placeholder="Digite a razão social"
                                @select="applyCompany"
                            >
                                <template #option="{ item }">
                                    <span class="block truncate font-medium">{{ item.rotulo ?? item.name }}</span>
                                    <span class="block text-xs text-ink-muted tabular-nums">{{ formatDocument(item.cnpj) }}</span>
                                </template>
                            </UiAutocomplete>
                        </UiField>

                        <UiAlert
                            v-if="companyOutcome"
                            class="sm:col-span-2"
                            :variant="companyOutcome.variant"
                            :title="companyOutcome.title"
                        >
                            {{ companyOutcome.text }}
                        </UiAlert>

                        <!-- O que a ficha já tem, e o caminho para o que falta.
                             Endereço e responsável ficam no drawer: dentro da
                             página eles empurravam o Projeto para baixo da
                             dobra, e o formulário principal tem três seções. -->
                        <UiPanel v-if="documentComplete" tone="muted" class="sm:col-span-2">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">Endereço e responsável</p>

                                    <p v-if="resumoEndereco" class="mt-0.5 text-sm text-ink-muted">
                                        {{ resumoEndereco }}
                                    </p>
                                    <p v-else class="mt-0.5 text-sm text-warning">
                                        Endereço ainda não preenchido.
                                    </p>

                                    <p v-if="!precisaAdmin" class="mt-0.5 text-sm text-ink-muted">
                                        Responsável já cadastrado nesta empresa.
                                    </p>
                                    <p v-else-if="adminOk" class="mt-0.5 text-sm text-ink-muted">
                                        Admin Cliente: {{ form.admin.name }} ({{ form.admin.email }})
                                    </p>
                                    <p v-else class="mt-0.5 text-sm text-warning">
                                        Falta o Admin Cliente, que é quem opera este cliente.
                                    </p>
                                </div>

                                <UiButton variant="secondary" size="sm" @click="fichaAberta = true">
                                    {{ fichaOk ? 'Revisar ficha' : 'Completar ficha' }}
                                </UiButton>
                            </div>
                        </UiPanel>
                    </div>
                </section>

                <section aria-labelledby="secao-projeto">
                    <UiFormHeading id="secao-projeto" icon="projects" title="Dados do projeto" />

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <UiField class="sm:col-span-2" label="Nome do projeto" required :error="form.errors.name">
                            <UiInput v-model="form.name" autocomplete="off" placeholder="Ex.: Kits de Natal 2026" />
                        </UiField>

                        <UiField label="Quantidade total de kits" required :error="form.errors.quantity">
                            <UiInput v-model="form.quantity" type="number" min="1" inputmode="numeric" />
                        </UiField>

                        <UiField label="Prazo de entrega" :error="form.errors.delivery_deadline">
                            <UiInput v-model="form.delivery_deadline" type="date" />
                        </UiField>
                    </div>
                </section>

                <section aria-labelledby="secao-kits">
                    <UiFormHeading id="secao-kits" icon="catalog" title="Kits/produtos do pedido" />

                    <!-- `as="group"` é fieldset/legend: os checkboxes deixam de morar
                         dentro de um <label>, que é HTML inválido e engolia o clique. -->
                    <UiField
                        as="group"
                        class="mt-4"
                        label="Selecione um ou mais itens"
                        required
                        :error="form.errors.product_ids"
                    >
                        <UiCheckboxGroup
                            v-if="hasCatalog"
                            v-model="form.product_ids"
                            :options="productOptions"
                            searchable
                            max-height="16rem"
                        >
                            <template #summary="{ count }">
                                {{ count === 0
                                    ? '0 itens selecionados. Selecione um ou mais itens.'
                                    : `${count} ${count === 1 ? 'item selecionado' : 'itens selecionados'}` }}
                            </template>
                        </UiCheckboxGroup>

                        <UiEmpty
                            v-else
                            icon="catalog"
                            message="Nenhum produto no catálogo. Importe o catálogo da Omie em Produtos antes de criar o Projeto."
                        >
                            <template #action>
                                <UiButton variant="secondary" :href="route('products.index')">Ir para Produtos</UiButton>
                            </template>
                        </UiEmpty>
                    </UiField>
                </section>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-5">
                    <p v-if="!allDone && hasCatalog" class="mr-auto text-sm text-ink-muted">
                        Para continuar, preencha {{ missing }}.
                    </p>

                    <UiButton variant="ghost" :href="route('projects.index')">Cancelar</UiButton>
                    <UiButton type="submit" icon-trailing="chevron-right" :disabled="!allDone || !hasCatalog">
                        Continuar
                    </UiButton>
                </div>
            </form>

            <!-- Resumo (etapa 4): conferência antes de criar. -->
            <div v-if="review" class="space-y-6 p-6">
                <UiFormHeading icon="check" title="Resumo: confira e finalize" />

                <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-control border border-line p-4">
                        <dt class="text-xs font-semibold tracking-wide text-ink-muted uppercase">Empresa</dt>
                        <dd class="mt-1 text-sm font-medium text-ink">{{ form.company_name }}</dd>
                        <dd class="text-sm text-ink-muted tabular-nums">{{ formatDocument(form.company_cnpj) }}</dd>
                        <dd v-if="resumoEndereco" class="mt-1 text-sm text-ink-muted">{{ resumoEndereco }}</dd>
                        <dd v-if="precisaAdmin" class="mt-1 text-sm text-ink-muted">
                            Admin Cliente: {{ form.admin.name }} ({{ form.admin.email }})
                        </dd>
                        <dd class="mt-1 text-xs" :class="existingCompany ? 'text-success' : 'text-brand-700'">
                            {{ existingCompany ? 'Empresa já cadastrada' : 'Empresa nova (será cadastrada junto)' }}
                        </dd>
                    </div>

                    <div class="rounded-control border border-line p-4">
                        <dt class="text-xs font-semibold tracking-wide text-ink-muted uppercase">Projeto</dt>
                        <dd class="mt-1 text-sm font-medium text-ink">{{ form.name }}</dd>
                        <dd class="text-sm text-ink-muted">
                            {{ form.quantity }} {{ Number(form.quantity) === 1 ? 'kit' : 'kits' }}
                            <template v-if="form.delivery_deadline"> · prazo {{ formatDate(form.delivery_deadline) }}</template>
                        </dd>
                    </div>

                    <div class="rounded-control border border-line p-4 sm:col-span-2">
                        <dt class="text-xs font-semibold tracking-wide text-ink-muted uppercase">
                            Kits/produtos ({{ selectedProducts.length }})
                        </dt>
                        <dd class="mt-2">
                            <ul class="space-y-1 text-sm text-ink">
                                <li v-for="product in selectedProducts" :key="product.id" class="flex items-center gap-2">
                                    <UiIcon name="check" :size="14" class="text-brand-600" />
                                    {{ product.name }}
                                    <span class="text-xs text-ink-muted">{{ product.omie_code }}</span>
                                </li>
                            </ul>
                        </dd>
                    </div>
                </dl>

                <UiAlert variant="info">
                    Ao criar, o Projeto abre um card no Trello na etapa de Design<template v-if="!existingCompany">, a empresa {{ form.company_name }} é cadastrada</template><template v-if="precisaAdmin"> e {{ form.admin.name }} recebe o convite de acesso</template>.
                </UiAlert>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-5">
                    <UiButton variant="ghost" :disabled="form.processing" @click="review = false">
                        Voltar e editar
                    </UiButton>
                    <UiButton :loading="form.processing" @click="submit">
                        Criar Projeto e abrir card no Trello
                    </UiButton>
                </div>
            </div>
        </div>
    </div>

    <!-- Ficha do cliente: o endereço e o responsável da empresa. -->
    <UiDrawer
        v-model:open="fichaAberta"
        title="Ficha do cliente"
        description="Endereço de cobrança e quem opera este cliente do outro lado."
    >
        <div class="space-y-6">
            <section class="space-y-4">
                <UiFormHeading icon="companies" title="Empresa" />

                <UiField label="Razão social" required :error="form.errors.company_name">
                    <UiInput v-model="form.company_name" data-autofocus autocomplete="off" />
                </UiField>

                <UiField label="CNPJ">
                    <p class="flex h-control-md items-center rounded-control border border-line bg-surface-muted px-3 text-sm font-medium tabular-nums text-ink">
                        {{ formatDocument(form.company_cnpj) }}
                    </p>
                </UiField>
            </section>

            <section class="space-y-4">
                <UiFormHeading icon="truck" title="Endereço" />

                <UiField
                    label="CEP"
                    required
                    :error="form.errors['company.zip'] ?? cepErro"
                    :hint="cepDica"
                >
                    <UiInput
                        v-model="form.company.zip"
                        inputmode="numeric"
                        width="sm"
                        placeholder="00000-000"
                        @blur="buscarCep()"
                    />
                </UiField>

                <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                    <UiField label="Logradouro" required :error="form.errors['company.street']">
                        <UiInput v-model="form.company.street" autocomplete="off" />
                    </UiField>

                    <UiField label="Número" required :error="form.errors['company.number']">
                        <UiInput v-model="form.company.number" autocomplete="off" />
                    </UiField>
                </div>

                <UiField label="Complemento" :error="form.errors['company.complement']">
                    <UiInput v-model="form.company.complement" autocomplete="off" />
                </UiField>

                <UiField label="Bairro" required :error="form.errors['company.district']">
                    <UiInput v-model="form.company.district" autocomplete="off" />
                </UiField>

                <div class="grid gap-4 sm:grid-cols-[1fr_6rem]">
                    <UiField label="Cidade" required :error="form.errors['company.city']">
                        <UiInput v-model="form.company.city" autocomplete="off" />
                    </UiField>

                    <UiField label="UF" required :error="form.errors['company.state']">
                        <UiInput
                            :model-value="form.company.state"
                            maxlength="2"
                            autocomplete="off"
                            @update:model-value="form.company.state = String($event).toUpperCase()"
                        />
                    </UiField>
                </div>
            </section>

            <!-- Só para empresa sem responsável: pedir de novo a quem já tem
                 seria fabricar um segundo Admin Cliente sem ninguém pedir. -->
            <section v-if="precisaAdmin" class="space-y-4">
                <UiFormHeading icon="users" title="Admin Cliente" />

                <UiAlert variant="info">
                    É quem enxerga a empresa inteira e cadastra os Admins de Projeto e os Colaboradores.
                    A pessoa recebe por e-mail o convite para criar a senha.
                </UiAlert>

                <UiField label="Nome" required :error="form.errors['admin.name']">
                    <UiInput v-model="form.admin.name" autocomplete="off" />
                </UiField>

                <UiField
                    label="E-mail"
                    required
                    :error="form.errors['admin.email']"
                    hint="É o login da pessoa. Não pode repetir uma conta existente."
                >
                    <UiInput v-model="form.admin.email" type="email" inputmode="email" autocomplete="off" />
                </UiField>
            </section>

            <UiPanel v-else tone="muted">
                <p class="text-sm text-ink-muted">
                    Esta empresa já tem Admin Cliente. Outros responsáveis são cadastrados na ficha do Cliente.
                </p>
            </UiPanel>
        </div>

        <template #footer>
            <UiButton :disabled="!fichaOk" @click="fichaAberta = false">Salvar ficha</UiButton>
            <UiButton variant="ghost" @click="fichaAberta = false">Fechar</UiButton>

            <p v-if="!fichaOk" class="text-sm text-ink-muted">
                Preencha a razão social, o endereço<template v-if="precisaAdmin"> e o Admin Cliente</template>.
            </p>
        </template>
    </UiDrawer>
</template>
