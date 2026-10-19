<script setup>
/**
 * Campos da empresa cliente. Criar e editar compartilham exatamente os mesmos
 * 14 campos — antes o formulário existia duas vezes no template da lista e as
 * cópias já haviam divergido (a de edição, usada para completar o cadastro que
 * veio incompleto da Omie, era a que estava sem os exemplos de formato).
 *
 * Quem cria o `useForm` é a página; aqui só se preenche.
 */
import { computed, h, useId } from 'vue';
import UiAlert from './UiAlert.vue';
import UiField from './UiField.vue';
import UiFormSection from './UiFormSection.vue';
import UiIcon from './UiIcon.vue';
import UiInput from './UiInput.vue';
import UiSelect from './UiSelect.vue';
import UiTextarea from './UiTextarea.vue';
import { useCepLookup } from '../composables/useCepLookup';
import { UFS, maskCep, maskDocument, maskPhone } from '../lib/format';

const props = defineProps({
    form: { type: Object, required: true },
    /** `missing_fields` da empresa em edição. Vazio na criação. */
    missing: { type: Array, default: () => [] },
});

// `Company::missingFields()` devolve RÓTULOS ("CEP", "cidade"), não nomes de
// campo — este mapa é o que liga o alerta do topo ao campo que precisa do ⚠.
const FIELD_BY_LABEL = {
    'e-mail de contato': 'contact_email',
    telefone: 'contact_phone',
    CEP: 'zip',
    endereço: 'street',
    cidade: 'city',
    UF: 'state',
};

const missingKeys = computed(
    () => new Set(props.missing.map((label) => FIELD_BY_LABEL[label]).filter(Boolean)),
);

const isMissing = (key) => missingKeys.value.has(key);

const missingList = computed(() => {
    const labels = props.missing;

    if (labels.length < 2) return labels[0] ?? null;

    return `${labels.slice(0, -1).join(', ')} e ${labels[labels.length - 1]}`;
});

const { hint: cepHint, error: cepError, loading: cepLoading, lookup: lookupCep } = useCepLookup(props.form);

const ufOptions = UFS.map((uf) => ({ value: uf, label: uf }));

// O mesmo marcador em seis campos: componente local em vez de seis cópias.
const MissingHint = () =>
    h('span', { class: 'inline-flex items-center gap-1 text-warning' }, [
        h(UiIcon, { name: 'alert', size: 14 }),
        'Faltando no cadastro',
    ]);

/**
 * O backend usa a chave `cnpj` tanto para a validação do campo quanto para a
 * recusa de duplicidade ("Já existe uma empresa com este CNPJ."). Uma mensagem,
 * um lugar: ela é renderizada só no alerta do rodapé do formulário, e o campo
 * aponta para lá por `aria-describedby`.
 */
const cnpjErrorId = useId();
</script>

<template>
    <div class="space-y-6">
        <UiAlert v-if="missingList" variant="warning">
            Falta {{ missingList }}. Sem isso a expedição não consegue faturar.
        </UiAlert>

        <UiFormSection title="Identificação">
            <UiField label="Razão social" :error="form.errors.name" required>
                <UiInput
                    v-model="form.name"
                    autocomplete="organization"
                    :invalid="!!form.errors.name"
                />
            </UiField>

            <!-- "Company Name" (UAT): o nome que o Usuário lê nos e-mails.
                 Só a WeGift define; a sincronização com a Omie nunca escreve
                 aqui (ao contrário da razão social, que ela sobrescreve). -->
            <UiField
                label="Nome de exibição"
                :error="form.errors.display_name"
                hint="Aparece nos e-mails ao Usuário; vazio usa a razão social."
            >
                <UiInput v-model="form.display_name" :invalid="!!form.errors.display_name" />
            </UiField>

            <UiField label="Nome fantasia" :error="form.errors.trade_name">
                <UiInput v-model="form.trade_name" :invalid="!!form.errors.trade_name" />
            </UiField>

            <UiField label="CNPJ" required>
                <UiInput
                    :model-value="form.cnpj"
                    placeholder="00.000.000/0000-00"
                    @update:model-value="form.cnpj = maskDocument($event)"
                    inputmode="numeric"
                    maxlength="18"
                    :invalid="!!form.errors.cnpj"
                    :aria-describedby="form.errors.cnpj ? cnpjErrorId : null"
                />
            </UiField>
        </UiFormSection>

        <UiFormSection
            title="Contato"
            description="Opcional, mas é por aqui que a WeGift fala com a empresa."
        >
            <UiField label="Nome do contato" :error="form.errors.contact_name">
                <UiInput
                    v-model="form.contact_name"
                    autocomplete="name"
                    :invalid="!!form.errors.contact_name"
                />
            </UiField>

            <UiField label="E-mail de contato" :error="form.errors.contact_email">
                <UiInput
                    v-model="form.contact_email"
                    type="email"
                    autocomplete="email"
                    inputmode="email"
                    :invalid="!!form.errors.contact_email"
                />
                <template v-if="isMissing('contact_email')" #hint>
                    <MissingHint />
                </template>
            </UiField>

            <UiField label="Telefone" :error="form.errors.contact_phone">
                <UiInput
                    :model-value="form.contact_phone"
                    type="tel"
                    @update:model-value="form.contact_phone = maskPhone($event)"
                    autocomplete="tel"
                    inputmode="tel"
                    placeholder="(41) 99999-0000"
                    :invalid="!!form.errors.contact_phone"
                />
                <template v-if="isMissing('contact_phone')" #hint>
                    <MissingHint />
                </template>
            </UiField>
        </UiFormSection>

        <UiFormSection
            title="Endereço"
            description="Opcional, mas a expedição precisa dele para faturar."
        >
            <UiField label="CEP" :error="form.errors.zip || cepError">
                <UiInput
                    :model-value="form.zip"
                    width="sm"
                    @update:model-value="form.zip = maskCep($event)"
                    maxlength="9"
                    inputmode="numeric"
                    autocomplete="postal-code"
                    placeholder="00000-000"
                    :loading="cepLoading"
                    :invalid="!!form.errors.zip || !!cepError"
                    @blur="lookupCep()"
                />
                <template v-if="cepHint || isMissing('zip')" #hint>
                    <span v-if="cepHint">{{ cepHint }}</span>
                    <MissingHint v-else />
                </template>
            </UiField>

            <UiField label="Logradouro" :error="form.errors.street">
                <UiInput
                    v-model="form.street"
                    autocomplete="address-line1"
                    :invalid="!!form.errors.street"
                />
                <template v-if="isMissing('street')" #hint>
                    <MissingHint />
                </template>
            </UiField>

            <div class="grid gap-4 sm:grid-cols-2">
                <UiField label="Número" :error="form.errors.number">
                    <UiInput
                        v-model="form.number"
                        inputmode="numeric"
                        :invalid="!!form.errors.number"
                    />
                </UiField>

                <UiField label="Complemento" :error="form.errors.complement">
                    <UiInput
                        v-model="form.complement"
                        autocomplete="address-line2"
                        :invalid="!!form.errors.complement"
                    />
                </UiField>
            </div>

            <UiField label="Bairro" :error="form.errors.district">
                <UiInput
                    v-model="form.district"
                    autocomplete="address-level3"
                    :invalid="!!form.errors.district"
                />
            </UiField>

            <div class="grid gap-4 sm:grid-cols-2">
                <UiField label="Cidade" :error="form.errors.city">
                    <UiInput
                        v-model="form.city"
                        autocomplete="address-level2"
                        :invalid="!!form.errors.city"
                    />
                    <template v-if="isMissing('city')" #hint>
                        <MissingHint />
                    </template>
                </UiField>

                <UiField label="UF" :error="form.errors.state">
                    <!-- Lista fechada: "Paraná" digitado à mão virava "Pa", passava
                         na validação `size:2` e seguia para a etiqueta de entrega. -->
                    <UiSelect
                        v-model="form.state"
                        :options="ufOptions"
                        placeholder="UF"
                        autocomplete="address-level1"
                        :invalid="!!form.errors.state"
                    />
                    <template v-if="isMissing('state')" #hint>
                        <MissingHint />
                    </template>
                </UiField>
            </div>
        </UiFormSection>

        <details class="rounded-control border border-line px-3 py-2">
            <summary class="cursor-pointer py-1 text-sm font-medium text-ink">
                Observações internas
            </summary>

            <!-- O <summary> é o rótulo visível; o campo repete o texto só para
                 o leitor de tela. -->
            <UiField
                :error="form.errors.notes"
                hint="Visível apenas para a equipe WeGift."
                class="mt-2 mb-1"
            >
                <UiTextarea
                    v-model="form.notes"
                    aria-label="Observações internas"
                    :rows="3"
                    :maxlength="2000"
                    counter
                    :invalid="!!form.errors.notes"
                />
            </UiField>
        </details>

        <UiAlert v-if="form.errors.cnpj" :id="cnpjErrorId" variant="danger">
            {{ form.errors.cnpj }}
        </UiAlert>
    </div>
</template>
