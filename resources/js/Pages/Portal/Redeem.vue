<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import PortalSupportContact from '../../Components/PortalSupportContact.vue';
import PortalVariantPicker from '../../Components/PortalVariantPicker.vue';
import ProductPhotoCarousel from '../../Components/ProductPhotoCarousel.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCard from '../../Components/UiCard.vue';
import UiField from '../../Components/UiField.vue';
import UiFormHeading from '../../Components/UiFormHeading.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiInput from '../../Components/UiInput.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiSelect from '../../Components/UiSelect.vue';
import UiStepper from '../../Components/UiStepper.vue';
import UiTextarea from '../../Components/UiTextarea.vue';
import { useCepLookup } from '../../composables/useCepLookup';
import { isCompleteCep } from '../../lib/cep';
import { UFS, maskCep, maskCpf } from '../../lib/format';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    entitlement: { type: Object, required: true },
    defaultName: { type: String, default: '' },
    // Cliente que desmarcou o CPF nas Customizações não coleta CPF: o campo some.
    askCpf: { type: Boolean, default: true },
});

const form = useForm({
    recipient_name: props.defaultName,
    // Vai com máscara mesmo: o backend aceita e guarda só os dígitos.
    cpf: '',
    zip: '',
    street: '',
    number: '',
    complement: '',
    district: '',
    city: '',
    state: '',
    notes: '',
    // Variação escolhida (cor/tamanho); fica nula em presente sem variação.
    variant_sku_id: null,
});

/**
 * Passos numa rota só: o POST só sai na conferência. O resgate é a única ação
 * irreversível do sistema e não existe rota de correção de endereço — a
 * conferência é a única proteção possível sem tocar no backend.
 *
 * Presente com variação (cor/tamanho) ganha um passo antes do endereço: a
 * escolha, como na página de produto de uma loja.
 */
const temVariacoes = computed(() => (props.entitlement.variants ?? []).length > 0);
const ESCOLHA = 1;
const ENTREGA = computed(() => (temVariacoes.value ? 2 : 1));
const CONFERENCIA = computed(() => ENTREGA.value + 1);

const step = ref(1);
const panel = ref(null);
const cor = ref(null);

const variacao = computed(
    () => props.entitlement.variants?.find((v) => v.id === form.variant_sku_id) ?? null,
);

// As fotos são as do presente e ficam paradas ao escolher cor ou tamanho: as
// variações não têm galeria própria.
const fotos = computed(
    () => props.entitlement.product.images ?? [props.entitlement.product.image_url].filter(Boolean),
);

const rotuloDaVariacao = computed(() => [variacao.value?.color, variacao.value?.size].filter(Boolean).join(' · '));

/*
 * O que vai junto do nome do presente: a cor/tamanho escolhidos aqui, ou o
 * tamanho já escolhido na vitrine (presente com tamanhos da grade da Omie).
 */
const detalheDoItem = computed(() =>
    rotuloDaVariacao.value || (props.entitlement.product.tamanho ? `Tamanho ${props.entitlement.product.tamanho}` : ''),
);

// A trilha de etapas do padrão de cadastro, adaptada ao portal: em coluna
// única ela vira um card compacto acima do conteúdo, não uma lateral.
const steps = computed(() => [
    ...(temVariacoes.value
        ? [{ title: 'Cor e tamanho', hint: rotuloDaVariacao.value || 'Escolha a sua', done: step.value > ESCOLHA }]
        : []),
    { title: 'Dados de entrega', hint: 'Quem recebe e onde', done: step.value === CONFERENCIA.value },
    { title: 'Conferência', hint: 'Confira antes de confirmar', done: false },
]);

// Bloco manual: abre sozinho quando a busca falha e por escolha de quem digita.
const manual = ref(false);
const advancing = ref(false);
// Nova tentativa de busca, pedida no aviso de serviço fora do ar.
const retrying = ref(false);

const {
    state: cepState,
    loading: cepLoading,
    found: cepFound,
    failed: cepFailed,
    error: cepError,
    hint: cepHint,
    lookup,
} = useCepLookup(form);

/**
 * Serviço fora do ar não é erro de quem digitou: pintar o campo de vermelho
 * mandaria conferir números que estão certos. O desfecho "indisponível" vira
 * aviso ao lado do bloco manual, com o caminho para concluir mesmo assim.
 */
const cepFieldError = computed(() => (cepState.value === 'not_found' ? cepError.value : null));

const ufOptions = UFS.map((uf) => ({ value: uf, label: uf }));

// Completo não é válido: dígito verificador é conta do backend. O passo 2 só
// abre com os 11 dígitos porque avançar sem CPF garantiria a viagem de volta.
const cpfComplete = computed(() => form.cpf.replace(/\D/g, '').length === 11);

const canAdvance = computed(
    () => (!props.askCpf || cpfComplete.value) && isCompleteCep(form.zip) && !!form.number,
);
const hasAddress = computed(() => !!(form.street && form.district && form.city && form.state));

const componentsLine = computed(() =>
    props.entitlement.product.components.map((c) => `${c.quantity}× ${c.name}`).join(' · '),
);

const complementLine = computed(() => [form.complement, form.district].filter(Boolean).join(', '));

// Embaixo da rua achada pelo CEP: bairro, cidade/UF.
const enderecoAchado = computed(() => [form.district, [form.city, form.state].filter(Boolean).join('/')].filter(Boolean).join(', '));

watch(cepFailed, (failed) => {
    if (failed) manual.value = true;
});

/**
 * `applyCepToForm` não sobrescreve campo preenchido — proteção pensada para o
 * operador, que ajusta o logradouro à mão. No portal ninguém digita esses
 * campos no caminho feliz: trocar o CEP tem de trazer o endereço novo inteiro.
 */
watch(
    () => form.zip,
    () => {
        if (manual.value) return;

        form.street = '';
        form.district = '';
        form.city = '';
        form.state = '';
    },
);

// Trocar de passo é troca de conteúdo, não de página: sem mover o foco, o
// teclado e o leitor de tela continuariam no passo anterior.
watch(step, () => nextTick(() => panel.value?.focus()));

async function advance() {
    if (!canAdvance.value || advancing.value) return;

    // Só há o que buscar quando falta endereço. Com os campos preenchidos — à
    // mão ou por uma busca anterior — consultar de novo não muda nada e, com o
    // serviço fora do ar, era o que prendia a pessoa no botão a cada toque.
    if (!hasAddress.value) {
        advancing.value = true;

        try {
            // Tocar em "Continuar" tira o foco do CEP e dispara a busca no
            // mesmo instante: sem esperar por ela, o passo 2 mostraria o
            // endereço vazio. Quem espera é a consulta que já está no ar.
            await lookup();
        } finally {
            advancing.value = false;
        }
    }

    // CEP de cidade sem logradouro (ou serviço fora do ar) não completa o
    // endereço; o bloco manual assume, com os campos exigidos pelo navegador.
    if (!hasAddress.value) {
        manual.value = true;

        return;
    }

    step.value = CONFERENCIA.value;
}

// Enquanto a nova tentativa está no ar o aviso continua na tela: se ele
// sumisse, o foco do teclado cairia no vazio e a pessoa perderia o botão.
async function retryCep() {
    retrying.value = true;

    try {
        await lookup({ force: true });
    } finally {
        retrying.value = false;
    }
}

function submit() {
    form.post(route('portal.redeem', props.entitlement.id), {
        // A conferência não tem campos: o erro volta para o passo que o tem.
        // Variação esgotada no meio do caminho volta para a escolha.
        onError: (errors) => {
            if (temVariacoes.value && (errors.variant_sku_id || /esgot|cor e o tamanho|opção/i.test(errors.redeem ?? ''))) {
                form.variant_sku_id = null;
                step.value = ESCOLHA;
            } else if (Object.keys(errors).some((key) => key !== 'redeem')) {
                step.value = ENTREGA.value;
            }
        },
    });
}
</script>

<template>
    <Head :title="temVariacoes && step === ESCOLHA ? entitlement.product.name : 'Endereço de entrega'" />

    <!-- Do segundo passo em diante, voltar é estado local, não navegação —
         por isso é <button> e não o `back` do PageHeader. -->
    <button
        v-if="step > 1"
        type="button"
        class="inline-flex min-h-12 items-center gap-1 text-base font-medium text-ink-muted transition-colors hover:text-ink"
        @click="step = step - 1"
    >
        <UiIcon name="chevron-left" :size="18" />
        {{ step === CONFERENCIA ? 'Corrigir endereço' : 'Trocar cor ou tamanho' }}
    </button>

    <PageHeader
        tone="portal"
        :title="
            temVariacoes && step === ESCOLHA
                ? entitlement.product.name
                : step === ENTREGA
                    ? 'Onde você quer receber?'
                    : 'Confira antes de confirmar'
        "
        :subtitle="
            temVariacoes && step === ESCOLHA
                ? `Presente de ${entitlement.company}`
                : step === ENTREGA
                    ? [entitlement.product.name, detalheDoItem].filter(Boolean).join(' · ')
                    : null
        "
        :back="step === 1 ? { label: 'Voltar ao presente', href: route('portal.catalog') } : null"
    >
        <!-- `router.reload()` preserva o estado local: o que já foi digitado
             não se perde ao atualizar. -->
        <template #actions>
            <UiRefreshButton quiet />
        </template>
    </PageHeader>

    <!-- Etapas reais no lugar da barra genérica: em coluna única o card de
         etapas fica acima do conteúdo, compacto, não numa lateral. -->
    <UiStepper class="-mt-2 mb-6" :steps="steps" :current="step" />

    <UiAlert v-if="form.errors.redeem" variant="danger" class="mb-4">{{ form.errors.redeem }}</UiAlert>

    <div ref="panel" tabindex="-1">
        <!-- Passo da escolha: a página de produto de uma loja. Foto grande que
             acompanha a cor, e a escolha logo abaixo. -->
        <section
            v-if="temVariacoes && step === ESCOLHA"
            class="rounded-card bg-surface shadow-card p-5 sm:p-6"
            aria-label="Cor e tamanho"
        >
            <div class="grid gap-6 sm:grid-cols-2 sm:items-start">
                <ProductPhotoCarousel
                    :images="fotos"
                    :alt="`Foto de ${entitlement.product.name}`"
                    frame-class="h-64 sm:h-80"
                    :icon-size="72"
                    eager
                />

                <div>
                    <p v-if="entitlement.product.description" class="mb-5 text-base whitespace-pre-line text-ink-muted">
                        {{ entitlement.product.description }}
                    </p>

                    <PortalVariantPicker
                        v-model="form.variant_sku_id"
                        v-model:color="cor"
                        :variants="entitlement.variants"
                    />

                    <p v-if="form.errors.variant_sku_id" class="mt-3 text-sm text-danger">
                        {{ form.errors.variant_sku_id }}
                    </p>
                </div>
            </div>

            <div class="mt-6 border-t border-line pt-5">
                <UiButton size="lg" block :disabled="!variacao" @click="step = ENTREGA">
                    Continuar
                </UiButton>
                <p v-if="!variacao" class="mt-2 text-sm text-ink-muted">
                    Escolha {{ entitlement.variants.some((v) => v.color) && entitlement.variants.some((v) => v.size) ? 'a cor e o tamanho' : entitlement.variants.some((v) => v.size) ? 'o tamanho' : 'a cor' }} para continuar.
                </p>
            </div>
        </section>

        <form
            v-else-if="step === ENTREGA"
            class="space-y-6 rounded-card bg-surface shadow-card p-5 sm:p-6"
            @submit.prevent="advance"
        >
            <section aria-labelledby="secao-quem-recebe">
                <UiFormHeading id="secao-quem-recebe" icon="users" title="Quem recebe" />

                <div class="mt-4 space-y-4">
                    <UiField label="Quem vai receber" required :error="form.errors.recipient_name">
                        <UiInput v-model="form.recipient_name" size="lg" autocomplete="name" />
                    </UiField>

                    <UiField
                        v-if="askCpf"
                        label="CPF"
                        required
                        :error="form.errors.cpf"
                        hint="Precisamos do CPF de quem recebe para a transportadora"
                    >
                        <UiInput
                            :model-value="form.cpf"
                            size="lg"
                            @update:model-value="form.cpf = maskCpf($event)"
                            width="md"
                            inputmode="numeric"
                            placeholder="000.000.000-00"
                            maxlength="14"
                        />
                    </UiField>
                </div>
            </section>

            <section aria-labelledby="secao-endereco" class="space-y-4">
                <UiFormHeading id="secao-endereco" icon="truck" title="Endereço" />

                <div>
                    <UiField
                        label="CEP"
                        required
                        :error="form.errors.zip || cepFieldError"
                        :hint="cepLoading ? cepHint : null"
                    >
                        <UiInput
                            :model-value="form.zip"
                            size="lg"
                            @update:model-value="form.zip = maskCep($event)"
                            width="md"
                            inputmode="numeric"
                            autocomplete="postal-code"
                            placeholder="00000-000"
                            maxlength="9"
                            :loading="cepLoading"
                            @blur="lookup()"
                        />
                    </UiField>

                    <!--
                        Endereço achado pelo CEP: a rua em destaque e o resto
                        embaixo, como um endereço se lê, num fundo neutro e
                        leve. Era uma caixa com borda e fundo verdes cheios,
                        que gritava "sucesso" para uma informação que só pede
                        conferência.
                    -->
                    <div
                        v-if="cepFound && !manual"
                        role="status"
                        class="mt-3 flex items-start justify-between gap-3 rounded-card bg-surface-muted/70 px-4 py-3"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                aria-hidden="true"
                                class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-success-soft text-success"
                            >
                                <UiIcon name="check" :size="14" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-base font-medium text-ink">{{ form.street || cepHint }}</p>
                                <p v-if="form.street" class="text-sm text-ink-muted">{{ enderecoAchado }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="-my-1 inline-flex min-h-11 shrink-0 items-center rounded-control px-1 text-sm font-medium text-brand-700 hover:underline"
                            @click="manual = true"
                        >
                            Alterar
                        </button>
                    </div>

                    <!-- Busca fora do ar: o caminho para concluir continua aberto
                         logo abaixo, e a nova tentativa é escolha de quem digita —
                         sozinha, a tela não insiste num serviço que não responde. -->
                    <UiAlert
                        v-if="cepState === 'unavailable' || retrying"
                        variant="warning"
                        class="mt-3"
                    >
                        Não conseguimos buscar seu CEP agora. Preencha o endereço abaixo: o resgate
                        segue normalmente.

                        <template #actions>
                            <button
                                type="button"
                                class="inline-flex min-h-11 items-center rounded-control text-base font-medium text-brand-700 underline underline-offset-2 disabled:no-underline disabled:opacity-60"
                                :disabled="retrying"
                                @click="retryCep"
                            >
                                {{ retrying ? 'Buscando…' : 'Tentar buscar de novo' }}
                            </button>
                        </template>
                    </UiAlert>
                </div>

                <div v-if="manual" class="space-y-4">
                    <UiField label="Rua ou avenida" required :error="form.errors.street">
                        <UiInput v-model="form.street" size="lg" autocomplete="address-line1" />
                    </UiField>

                    <UiField label="Bairro" required :error="form.errors.district">
                        <UiInput v-model="form.district" size="lg" autocomplete="address-level3" />
                    </UiField>

                    <div class="grid grid-cols-3 gap-3">
                        <UiField class="col-span-2" label="Cidade" required :error="form.errors.city">
                            <UiInput v-model="form.city" size="lg" autocomplete="address-level2" />
                        </UiField>

                        <UiField label="UF" required :error="form.errors.state">
                            <UiSelect
                                v-model="form.state"
                                size="lg"
                                placeholder="UF"
                                :options="ufOptions"
                                autocomplete="address-level1"
                            />
                        </UiField>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <UiField label="Número" required :error="form.errors.number">
                        <UiInput v-model="form.number" size="lg" inputmode="numeric" />
                    </UiField>

                    <UiField class="col-span-2" label="Complemento" :error="form.errors.complement">
                        <UiInput
                            v-model="form.complement"
                            size="lg"
                            autocomplete="address-line2"
                            placeholder="apto 42"
                        />
                    </UiField>
                </div>

                <UiField
                    label="Observações (opcional)"
                    :error="form.errors.notes"
                    hint="Alguma informação extra para a entrega? Ex.: portaria, bloco, horário"
                >
                    <UiTextarea v-model="form.notes" :rows="3" :maxlength="500" counter />
                </UiField>
            </section>

            <div class="border-t border-line pt-5">
                <UiButton type="submit" size="lg" block :disabled="!canAdvance" :loading="advancing">
                    Continuar
                </UiButton>
                <p v-if="!canAdvance" class="mt-2 text-sm text-ink-muted">
                    {{ askCpf ? 'Informe o CPF, o CEP e o número para continuar.' : 'Informe o CEP e o número para continuar.' }}
                </p>
            </div>
        </form>

        <div v-else class="space-y-4">
            <UiCard padding="none">
                <div class="border-b border-line p-5">
                    <UiFormHeading icon="gift" title="Presente" />
                    <div class="mt-3 pl-10">
                        <p class="text-base font-medium text-ink">{{ entitlement.product.name }}</p>
                        <p v-if="detalheDoItem" class="text-base text-ink">{{ detalheDoItem }}</p>
                        <p class="text-sm text-ink-muted">Presente de {{ entitlement.company }}</p>
                        <p v-if="componentsLine" class="mt-1 text-sm text-ink">{{ componentsLine }}</p>
                    </div>
                </div>

                <div class="p-5">
                    <UiFormHeading icon="truck" title="Vai para" />
                    <div class="mt-3 pl-10">
                        <p class="text-base text-ink">{{ form.recipient_name }}</p>
                        <p v-if="askCpf" class="text-base text-ink">CPF {{ form.cpf }}</p>
                        <p class="text-base text-ink">{{ form.street }}, {{ form.number }}</p>
                        <p v-if="complementLine" class="text-base text-ink">{{ complementLine }}</p>
                        <p class="text-base text-ink">{{ form.city }}/{{ form.state }}, CEP {{ form.zip }}</p>
                        <p v-if="form.notes" class="mt-2 text-base whitespace-pre-line text-ink">
                            Observações: {{ form.notes }}
                        </p>

                        <button
                            type="button"
                            class="mt-3 inline-flex min-h-11 items-center rounded-control text-base font-medium text-brand-700 underline underline-offset-2"
                            @click="step = ENTREGA"
                        >
                            Corrigir
                        </button>
                    </div>
                </div>
            </UiCard>

            <!-- O tamanho faz parte do presente: escolhido errado, não tem troca. -->
            <UiAlert variant="warning">
                Depois de confirmar, não é possível trocar o presente nem o endereço.
            </UiAlert>

            <div class="border-t border-line pt-5">
                <UiButton size="lg" block :loading="form.processing" @click="submit">
                    Confirmar e finalizar
                </UiButton>
            </div>
        </div>
    </div>

    <PortalSupportContact class="mt-6" />
</template>
