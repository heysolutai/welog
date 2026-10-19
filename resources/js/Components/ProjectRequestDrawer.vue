<script setup>
import { computed, useId, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import UiAlert from './UiAlert.vue';
import UiButton from './UiButton.vue';
import UiDrawer from './UiDrawer.vue';
import UiField from './UiField.vue';
import UiFormHeading from './UiFormHeading.vue';
import UiFormSection from './UiFormSection.vue';
import UiInput from './UiInput.vue';
import UiSelect from './UiSelect.vue';
import UiTextarea from './UiTextarea.vue';
import { useCepLookup } from '../composables/useCepLookup';
import { UFS, maskCep, maskCpf } from '../lib/format';

/**
 * Solicitação de kits pelo admin (inclusão UAT): N unidades de um item do
 * pedido para um destinatário, fora do limite "um por pessoa" do portal.
 *
 * Aparece no Projeto liberado para quem pode pedir (Admin Cliente) e para a
 * WeGift crítica operando em nome do cliente. A regra de saldo vive no
 * backend: a recusa chega no bag `request` e fica visível dentro do drawer.
 */
const open = defineModel('open', { type: Boolean, default: false });

const props = defineProps({
    projectId: { type: Number, required: true },
    // Itens DO PEDIDO: [{ id, name }]
    products: { type: Array, default: () => [] },
    // Cliente que desmarcou o CPF nas Customizações: o campo fica, opcional.
    askCpf: { type: Boolean, default: true },
});

const formId = useId();

/**
 * CPF em branco só para a WeGift, que abre a solicitação com o que chegou por
 * telefone ou e-mail — o número costuma vir depois, junto da nota. Exigir ali
 * fazia a operação inventar um CPF para o formulário passar, e CPF inventado
 * vai para a NF. Quem administra o cliente tem o dado do colaborador na mão.
 */
const page = usePage();

const operacao = computed(() => page.props.auth?.user?.can_critical === true);

const cpfOpcional = computed(() => operacao.value || !props.askCpf);

const blank = () => ({
    product_id: props.products[0]?.id ?? '',
    quantity: 1,
    recipient_name: '',
    cpf: '',
    zip: '',
    street: '',
    number: '',
    complement: '',
    district: '',
    city: '',
    state: '',
    notes: '',
});

const form = useForm(blank());

const productOptions = () => props.products.map((p) => ({ value: p.id, label: p.name }));
const ufOptions = UFS.map((uf) => ({ value: uf, label: uf }));

const { loading: cepLoading, error: cepError, hint: cepHint, lookup } = useCepLookup(form);

// Painel reaberto é sessão nova: dados e erros do envio anterior não voltam.
watch(open, (value) => {
    if (value) form.defaults(blank()).reset().clearErrors();
});

function submit() {
    form.post(route('projects.requests.store', props.projectId), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            // A solicitação vira um resgate na aba Resgates: recarregar só a
            // prop do Projeto traz a linha nova sem refazer a página inteira.
            router.reload({ only: ['project'] });
        },
    });
}
</script>

<template>
    <UiDrawer
        v-model:open="open"
        title="Solicitar kits"
        description="Envia N unidades de um item deste pedido para um destinatário. O envio sai do saldo do Projeto."
    >
        <form :id="formId" class="space-y-6" @submit.prevent="submit">
            <section class="space-y-4">
                <UiFormHeading icon="gift" title="O que enviar" />

                <UiField label="Item do pedido" required :error="form.errors.product_id">
                    <UiSelect v-model="form.product_id" :options="productOptions()" />
                </UiField>

                <UiField label="Quantidade" required :error="form.errors.quantity">
                    <UiInput v-model="form.quantity" type="number" min="1" inputmode="numeric" width="sm" />
                </UiField>
            </section>

            <section class="space-y-4">
                <UiFormHeading icon="users" title="Quem recebe" />

                <UiFormSection :columns="2">
                    <UiField class="sm:col-span-2" label="Nome do destinatário" required :error="form.errors.recipient_name">
                        <UiInput v-model="form.recipient_name" autocomplete="off" />
                    </UiField>

                    <UiField
                        label="CPF"
                        :required="!cpfOpcional"
                        :error="form.errors.cpf"
                        :hint="operacao ? 'Pode ficar para depois: a pendência aparece na lista de Resgates.' : null"
                    >
                        <UiInput
                            :model-value="form.cpf"
                            inputmode="numeric"
                            autocomplete="off"
                            :placeholder="cpfOpcional ? 'opcional' : '000.000.000-00'"
                            maxlength="14"
                            @update:model-value="form.cpf = maskCpf($event)"
                        />
                    </UiField>
                </UiFormSection>
            </section>

            <section class="space-y-4">
                <UiFormHeading icon="truck" title="Endereço de entrega" />

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

            <section class="space-y-4">
                <UiFormHeading icon="info" title="Observações" />

                <UiField label="Observações" :error="form.errors.notes">
                    <UiTextarea v-model="form.notes" :rows="3" :maxlength="500" counter />
                </UiField>
            </section>

            <!-- Recusa de domínio (ex.: sem saldo em estoque): fica no drawer,
                 junto do formulário que a causou. -->
            <UiAlert v-if="form.errors.request" variant="danger">
                {{ form.errors.request }}
            </UiAlert>
        </form>

        <template #footer>
            <UiButton type="submit" :form="formId" :loading="form.processing">
                Enviar solicitação
            </UiButton>
            <UiButton variant="ghost" :disabled="form.processing" @click="open = false">
                Cancelar
            </UiButton>
        </template>
    </UiDrawer>
</template>
