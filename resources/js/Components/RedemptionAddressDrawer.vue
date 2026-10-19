<script setup>
import { computed, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiAlert from './UiAlert.vue';
import UiButton from './UiButton.vue';
import UiDrawer from './UiDrawer.vue';
import UiField from './UiField.vue';
import UiFormHeading from './UiFormHeading.vue';
import UiFormSection from './UiFormSection.vue';
import UiInput from './UiInput.vue';
import UiSelect from './UiSelect.vue';
import { useCepLookup } from '../composables/useCepLookup';
import { useConfirm } from '../composables/useConfirm';
import { UFS, maskCep } from '../lib/format';

/**
 * Corrigir o endereço de entrega de um resgate (só a WeGift).
 *
 * A WeGift decide em qualquer momento. Antes de expedir, salva direto. Depois,
 * o pacote já saiu com a etiqueta do endereço antigo: a troca só faz sentido
 * se ela já combinou com a transportadora, e a confirmação diz isso antes de
 * gravar.
 */
const open = defineModel('open', { type: Boolean, default: false });

const props = defineProps({
    // Linha da lista de Resgates: { id, pessoa, kit, status, endereco: { campos } }.
    redemption: { type: Object, default: null },
});

const formId = useId();
const confirm = useConfirm();
const ufOptions = UFS.map((uf) => ({ value: uf, label: uf }));

const vazio = () => ({
    recipient_name: '', zip: '', street: '', number: '', complement: '', district: '', city: '', state: '',
});

const atual = () => ({ ...vazio(), ...(props.redemption?.endereco?.campos ?? {}) });

const form = useForm(vazio());

const { loading: cepLoading, error: cepError, hint: cepHint, lookup } = useCepLookup(form);

// Já expedido (ou entregue): o pacote saiu com o endereço antigo.
const jaSaiu = computed(() => props.redemption && props.redemption.status !== 'confirmed');

// O CEP com que o painel abriu: trocá-lo é trocar de endereço, e a rua, o
// bairro e a cidade antigos não podem ficar presos esperando a busca.
let cepOriginal = '';

watch(open, (aberto) => {
    if (!aberto) return;

    const campos = atual();
    cepOriginal = campos.zip;
    form.defaults(campos).reset().clearErrors();
});

watch(
    () => form.zip,
    (cep) => {
        if (!open.value || cep === cepOriginal) return;

        form.street = '';
        form.district = '';
        form.city = '';
        form.state = '';
        cepOriginal = cep;
    },
);

function gravar() {
    return form.patch(route('redemptions.address', props.redemption.id), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

async function submit() {
    if (!jaSaiu.value) {
        gravar();

        return;
    }

    await confirm({
        title: 'Este resgate já foi expedido',
        description: 'O pacote saiu com o endereço anterior. Altere só se a troca já foi combinada com a transportadora.',
        confirmLabel: 'Alterar mesmo assim',
        action: gravar,
    });
}
</script>

<template>
    <UiDrawer
        v-model:open="open"
        title="Alterar endereço"
        :description="redemption ? `Resgate de ${redemption.pessoa}${redemption.kit ? ` · ${redemption.kit}` : ''}` : null"
    >
        <form :id="formId" class="space-y-6" @submit.prevent="submit">
            <UiAlert v-if="form.errors.address" variant="danger">{{ form.errors.address }}</UiAlert>

            <section class="space-y-4">
                <UiFormHeading icon="truck" title="Entrega" />

                <UiField label="Destinatário" required :error="form.errors.recipient_name">
                    <UiInput v-model="form.recipient_name" autocomplete="off" />
                </UiField>

                <UiField
                    label="CEP"
                    required
                    :error="form.errors.zip || cepError"
                    :hint="cepLoading ? 'buscando…' : cepHint"
                >
                    <UiInput
                        :model-value="form.zip"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="00000-000"
                        maxlength="9"
                        width="sm"
                        :loading="cepLoading"
                        @update:model-value="form.zip = maskCep($event)"
                        @blur="lookup()"
                    />
                </UiField>

                <UiField label="Rua ou avenida" required :error="form.errors.street">
                    <UiInput v-model="form.street" autocomplete="off" />
                </UiField>

                <UiFormSection :columns="2">
                    <UiField label="Número" required :error="form.errors.number">
                        <UiInput v-model="form.number" autocomplete="off" />
                    </UiField>

                    <UiField label="Complemento" :error="form.errors.complement">
                        <UiInput v-model="form.complement" autocomplete="off" placeholder="apto 42" />
                    </UiField>

                    <UiField label="Bairro" required :error="form.errors.district">
                        <UiInput v-model="form.district" autocomplete="off" />
                    </UiField>

                    <UiField label="Cidade" required :error="form.errors.city">
                        <UiInput v-model="form.city" autocomplete="off" />
                    </UiField>
                </UiFormSection>

                <UiField label="UF" required :error="form.errors.state">
                    <UiSelect v-model="form.state" placeholder="UF" :options="ufOptions" width="sm" />
                </UiField>
            </section>
        </form>

        <template #footer>
            <UiButton type="submit" :form="formId" :loading="form.processing">Salvar endereço</UiButton>
            <UiButton variant="ghost" :disabled="form.processing" @click="open = false">Cancelar</UiButton>
        </template>
    </UiDrawer>
</template>
