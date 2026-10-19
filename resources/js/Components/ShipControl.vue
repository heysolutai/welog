<script setup>
/**
 * Célula de expedição do resgate: um botão só (§6.4). O formulário mora no
 * drawer — dentro da linha ele custava até três controles por resgate.
 *
 * O que o drawer pergunta sai do CADASTRO da transportadora, não de uma lista
 * escrita aqui: quem entrega código pede o código, quem entrega link pede a
 * URL pronta. Os Correios são a exceção com integração — com a prepostagem
 * ligada (`integrations.correios`), o código nasce da API; ela vem desligada
 * por padrão, e por isso o campo continua aberto para digitação.
 *
 * Usado pela lista de Resgates e pela aba Resgates do Projeto: o resgate chega
 * com `city` na primeira e com `address` na segunda.
 */
import { computed, ref, useId } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiAlert from './UiAlert.vue';
import UiButton from './UiButton.vue';
import UiDrawer from './UiDrawer.vue';
import UiField from './UiField.vue';
import UiInput from './UiInput.vue';
import UiPanel from './UiPanel.vue';
import { useCarriers } from '../composables/useCarriers';
import { useConfirm } from '../composables/useConfirm';
import { useRowAction } from '../composables/useRowAction';

const props = defineProps({
    redemption: { type: Object, required: true },
    canShip: { type: Boolean, default: false },
});

const { activeCarriers, bySlug } = useCarriers();

const formId = useId();
const radioName = useId();

const open = ref(false);
const form = useForm({ carrier: '', tracking_code: '', tracking_url: '' });

const selecionada = computed(() => bySlug.value[form.carrier] ?? null);

const usaLink = computed(() => selecionada.value?.tracking_field === 'link');

/**
 * Correios fora da prepostagem é o único caso em que expedir sem rastreio é
 * legítimo: o robô descobre o código depois. Nas demais, sem o campo o resgate
 * fica sem como ser acompanhado — e não existe outra tela para informá-lo.
 */
const opcional = computed(() => selecionada.value?.integrated === true && !usaLink.value);

// Campo em branco vira `null` (a validação é `nullable`) em vez de string
// vazia, e o campo que esta transportadora não usa nem chega a ser enviado.
form.transform((data) => ({
    carrier: data.carrier,
    tracking_code: usaLink.value ? null : data.tracking_code.trim() || null,
    tracking_url: usaLink.value ? data.tracking_url.trim() || null : null,
}));

/**
 * Rascunho por transportadora. Trocar de opção não pode carregar o código de
 * uma para a outra — "BR123..." digitado em MRB seria gravado como rastreio dos
 * Correios —, nem descartar o que já foi digitado se o operador voltar à opção
 * anterior. Objeto simples de propósito: nada no template lê este cache, só os
 * campos do form, que já são reativos.
 */
let trackingDrafts = {};

const carrier = computed({
    get: () => form.carrier,
    set: (value) => {
        if (value === form.carrier) return;

        trackingDrafts[form.carrier] = { code: form.tracking_code, url: form.tracking_url };
        form.carrier = value;
        form.tracking_code = trackingDrafts[value]?.code ?? '';
        form.tracking_url = trackingDrafts[value]?.url ?? '';
        // O erro apontava para o valor anterior do campo, que acabou de sair.
        form.clearErrors('tracking_code', 'tracking_url');
    },
});

// Guarda de interface: o serviço recusa a mesma coisa, mas o operador descobre
// aqui, antes de enviar.
const missingTracking = computed(() => {
    if (selecionada.value === null || opcional.value) return false;

    return (usaLink.value ? form.tracking_url : form.tracking_code).trim() === '';
});

/** Sem transportadora selecionada não há o que enviar — nem opção a escolher. */
const bloqueado = computed(() => selecionada.value === null || missingTracking.value);

const trackingHint = computed(() => {
    if (usaLink.value) return 'Cole o endereço de acompanhamento informado pela transportadora.';

    return opcional.value
        ? 'Opcional: em branco, o sistema tenta obter o código automaticamente na expedição.'
        : 'Informe o código fornecido pela transportadora.';
});

const destination = computed(() => props.redemption.city ?? props.redemption.address ?? null);

/**
 * O que a célula mostra. Onde o N1 vê botão, o N2 vê texto de estado; o
 * travessão fica reservado a quem realmente não tem ação (§9.8).
 */
const state = computed(() => {
    const { status, carrier: slug } = props.redemption;

    if (status === 'confirmed') {
        return props.canShip ? { action: 'ship' } : { text: 'Aguardando expedição' };
    }

    if (status === 'shipped') {
        // Sem integração, ninguém avisa que chegou: a entrega é marcada na mão.
        const manual = bySlug.value[slug]?.integrated !== true;

        return props.canShip && manual ? { action: 'deliver' } : { text: 'Em transporte' };
    }

    return { note: status === 'delivered' ? 'Entrega concluída' : 'Sem ação disponível' };
});

function openDrawer() {
    // Sem isto o rascunho (e o erro) de uma abertura vaza para a seguinte.
    trackingDrafts = {};
    form.reset();
    form.clearErrors();
    // A primeira da lista já vem marcada: abrir sem nenhuma opção selecionada
    // deixaria o botão de expedir recusando por um campo que parece vazio de
    // propósito.
    form.carrier = activeCarriers.value[0]?.slug ?? '';
    open.value = true;
}

function ship() {
    if (bloqueado.value) return;

    form.post(route('redemptions.ship', props.redemption.id), {
        preserveScroll: true,
        // Recusa do backend cai no bag `ship`: o drawer continua aberto.
        onSuccess: () => (open.value = false),
    });
}

const confirm = useConfirm();
const { run, pending } = useRowAction();

async function markDelivered() {
    await confirm({
        title: `Marcar como entregue o resgate de ${props.redemption.user}?`,
        description: 'O Usuário recebe o e-mail "Pedido entregue!". Esta ação não pode ser desfeita.',
        confirmLabel: 'Marcar entregue',
        errorBag: 'deliver',
        action: () =>
            run(props.redemption.id, route('redemptions.delivered', props.redemption.id), {
                errorKey: 'deliver',
            }),
    });
}
</script>

<template>
    <div class="flex items-center gap-2">
        <!-- Contorno discreto, não o laranja cheio: numa lista, um botão de
             destaque por linha vira uma coluna de alarmes competindo entre si.
             O destaque é da ação em massa. -->
        <UiButton v-if="state.action === 'ship'" variant="secondary" size="sm" icon="truck" @click="openDrawer">
            Expedir
        </UiButton>

        <UiButton
            v-else-if="state.action === 'deliver'"
            variant="secondary"
            size="sm"
            icon="check"
            :loading="pending(redemption.id)"
            @click="markDelivered"
        >
            Entregue
        </UiButton>

        <span v-else-if="state.text" class="text-sm text-ink-muted">{{ state.text }}</span>

        <span v-else class="text-sm">
            <span class="text-ink-subtle" aria-hidden="true">-</span>
            <span class="sr-only">{{ state.note }}</span>
        </span>

        <UiDrawer v-model:open="open" title="Expedir resgate">
            <div class="space-y-5">
                <!-- Contexto: o que exatamente está sendo expedido. -->
                <UiPanel tone="muted">
                    <p class="text-sm font-medium text-ink">
                        {{ redemption.user }}
                        <template v-if="destination">
                            <span class="text-ink-subtle" aria-hidden="true">·</span>
                            <span class="font-normal text-ink-muted">{{ destination }}</span>
                        </template>
                    </p>
                    <p class="mt-0.5 text-sm text-ink-muted">{{ redemption.product }}</p>
                </UiPanel>

                <form :id="formId" class="space-y-5" @submit.prevent="ship">
                    <UiField as="group" label="Transportadora" required :error="form.errors.carrier">
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <label
                                v-for="option in activeCarriers"
                                :key="option.slug"
                                class="relative flex cursor-pointer"
                            >
                                <!-- `sr-only` (não `hidden`): o rádio continua
                                     alcançável por teclado e o anel de foco
                                     aparece na caixa, via `peer`. -->
                                <input
                                    v-model="carrier"
                                    type="radio"
                                    :name="radioName"
                                    :value="option.slug"
                                    class="peer sr-only"
                                />
                                <span
                                    :class="[
                                        'flex min-h-12 w-full items-center justify-center rounded-control border px-2 text-center text-sm font-medium transition-colors',
                                        'peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus',
                                        carrier === option.slug
                                            ? 'border-brand-600 bg-brand-50 text-brand-700'
                                            : 'border-line bg-surface text-ink hover:bg-surface-muted',
                                    ]"
                                >
                                    {{ option.name }}
                                </span>
                            </label>
                        </div>

                        <!-- Sem transportadora ativa não há como expedir, e o
                             caminho para resolver é o cadastro. -->
                        <p v-if="!activeCarriers.length" class="text-sm text-ink-muted">
                            Nenhuma transportadora ativa. Cadastre uma em Cadastros › Transportadoras.
                        </p>
                    </UiField>

                    <!-- Um campo OU o outro, conforme a transportadora: quem
                         entrega link não tem código, e pedir os dois convida a
                         colar a URL no campo do código — que é como ela ia
                         parar no e-mail do presenteado escrita como código. -->
                    <UiField
                        v-if="usaLink"
                        label="Link de rastreio"
                        required
                        :error="form.errors.tracking_url"
                        :hint="trackingHint"
                    >
                        <UiInput
                            v-model="form.tracking_url"
                            type="url"
                            maxlength="500"
                            autocomplete="off"
                            placeholder="https://rastreio.exemplo.com.br/AB123456"
                        />
                    </UiField>

                    <UiField
                        v-else
                        label="Código de rastreio"
                        :required="!opcional"
                        :error="form.errors.tracking_code"
                        :hint="trackingHint"
                    >
                        <UiInput
                            v-model="form.tracking_code"
                            width="md"
                            maxlength="50"
                            autocomplete="off"
                            placeholder="BR123456789BR"
                        />
                    </UiField>

                    <UiAlert v-if="form.errors.ship" variant="danger">{{ form.errors.ship }}</UiAlert>
                </form>
            </div>

            <template #footer>
                <UiButton
                    type="submit"
                    :form="formId"
                    :disabled="bloqueado"
                    :loading="form.processing"
                >
                    Expedir e avisar
                </UiButton>

                <UiButton variant="ghost" :disabled="form.processing" @click="open = false">
                    Cancelar
                </UiButton>

                <!-- P2: a ação primária nunca some; quando não dá para agir, o
                     motivo fica escrito ao lado dela. -->
                <p v-if="missingTracking" class="text-sm text-ink-muted">
                    Informe o {{ usaLink ? 'link' : 'código' }} de rastreio para expedir.
                </p>
            </template>
        </UiDrawer>
    </div>
</template>
