<script setup>
import { computed, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiAlert from './UiAlert.vue';
import UiButton from './UiButton.vue';
import UiDrawer from './UiDrawer.vue';
import UiField from './UiField.vue';
import UiFormHeading from './UiFormHeading.vue';
import UiInput from './UiInput.vue';
import UiCheckbox from './UiCheckbox.vue';
import ProjectItemsChoice from './ProjectItemsChoice.vue';

/**
 * Cadastro do Admin DESTE Projeto.
 *
 * Tinha um campo "tipo de admin" para escolher entre Geral (a empresa
 * inteira) e De um Projeto. Ele saiu por dois motivos que se somam: ninguém
 * cadastra o próprio nível — Admin Cliente é criado pela WeGift, na ficha da
 * Empresa — e, partindo de um Projeto, "Geral" era a opção que ignorava a
 * tela em que a pessoa estava.
 *
 * Sem ela, a empresa e o Projeto vêm do contexto e não sobra o que perguntar
 * além de nome e e-mail.
 *
 * O Admin pode também resgatar, como Colaborador da empresa que é. Quem libera
 * é o Admin Cliente ou a WeGift (`canGrantRedemption`): o Admin Projeto
 * cadastra outros Admins, mas não vê a chave.
 */
const open = defineModel('open', { type: Boolean, default: false });

const props = defineProps({
    projectId: { type: Number, required: true },
    projectName: { type: String, required: true },
    /** Empresa do Projeto: sai do formulário, mas não da tela. */
    companyName: { type: String, default: null },
    canGrantRedemption: { type: Boolean, default: false },
    // Os itens do pedido, como no cadastro de Colaborador.
    itemOptions: { type: Array, default: () => [] },
    entradas: { type: Array, default: () => [] },
});

const formId = useId();

// Um item só no pedido: não há o que escolher, ele vai marcado.
const itemUnico = computed(() => props.entradas.length === 1);

const blank = () => ({
    name: '',
    email: '',
    project_id: props.projectId,
    resgata: false,
    product_ids: itemUnico.value ? [...props.entradas[0].values] : [],
    mode: 'all',
});

const form = useForm(blank());

watch(open, (value) => {
    if (!value) return;
    form.defaults(blank()).reset().clearErrors();
});

/**
 * Erros que NENHUM campo desta tela mostra.
 *
 * `project_id` vem do contexto e não tem campo: recusado pelo servidor, a
 * mensagem não teria onde aparecer e o botão pareceria quebrado — foi assim
 * que "cadastrar admin não faz nada" chegou como bug uma vez.
 */
const errosSemCampo = computed(() =>
    Object.entries(form.errors)
        .filter(([campo, mensagem]) => !['name', 'email', 'product_ids', 'product_id', 'mode'].includes(campo) && mensagem)
        .map(([, mensagem]) => mensagem),
);

function submit() {
    // Sem a chave, os itens não vão: o servidor nem os olha.
    form.transform((dados) => (dados.resgata ? dados : { ...dados, product_ids: undefined, mode: undefined }))
        .post(route('company.admins.store'), {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });
}
</script>

<template>
    <UiDrawer
        v-model:open="open"
        title="Adicionar Admin do Projeto"
        description="A pessoa recebe por e-mail o convite para criar a senha de acesso."
    >
        <form :id="formId" class="space-y-6" @submit.prevent="submit">
            <UiAlert v-for="(mensagem, i) in errosSemCampo" :key="i" variant="danger">
                {{ mensagem }}
            </UiAlert>

            <section class="space-y-4">
                <UiFormHeading icon="users" title="Dados da pessoa" />

                <UiField label="Nome" required :error="form.errors.name">
                    <UiInput v-model="form.name" autocomplete="off" />
                </UiField>

                <UiField
                    label="E-mail"
                    required
                    :error="form.errors.email"
                    hint="É o login da pessoa. Não pode repetir uma conta existente."
                >
                    <UiInput v-model="form.email" type="email" inputmode="email" autocomplete="off" />
                </UiField>
            </section>

            <section class="space-y-4">
                <UiFormHeading icon="companies" title="Alcance do admin" />

                <!-- Confirmação, não escolha: o alcance já foi decidido pela
                     tela de onde o cadastro partiu. -->
                <UiField
                    label="Projeto"
                    :error="form.errors.project_id"
                    hint="A pessoa enxerga apenas este Projeto e cadastra Colaboradores e outros Admins dele."
                >
                    <p class="flex h-control-md items-center rounded-control border border-line bg-surface-muted px-3 text-sm font-medium text-ink">
                        {{ projectName }}
                    </p>
                </UiField>

                <UiField v-if="companyName" label="Empresa">
                    <p class="flex h-control-md items-center rounded-control border border-line bg-surface-muted px-3 text-sm font-medium text-ink">
                        {{ companyName }}
                    </p>
                </UiField>
            </section>

            <!-- Também resgata: a pessoa é Colaboradora da empresa, e o
                 presente dela fica em "Meu presente", no cabeçalho. -->
            <section v-if="canGrantRedemption" class="space-y-4">
                <UiFormHeading icon="gift" title="Presente" />

                <UiCheckbox
                    v-model="form.resgata"
                    label="Também pode resgatar"
                    description="Recebe o presente como Colaborador e resgata em Meu presente, no topo da tela."
                />

                <ProjectItemsChoice
                    v-if="form.resgata && !itemUnico"
                    v-model:product-ids="form.product_ids"
                    v-model:mode="form.mode"
                    :options="itemOptions"
                    :entradas="entradas"
                    :errors="form.errors"
                    label="Quais itens ele pode receber?"
                />
            </section>
        </form>

        <template #footer>
            <UiButton type="submit" :form="formId" :loading="form.processing">
                Cadastrar Admin
            </UiButton>
            <UiButton variant="ghost" :disabled="form.processing" @click="open = false">
                Cancelar
            </UiButton>
        </template>
    </UiDrawer>
</template>
