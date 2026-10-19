<script setup>
import { computed, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ProjectItemsChoice from './ProjectItemsChoice.vue';
import UiAlert from './UiAlert.vue';
import UiButton from './UiButton.vue';
import UiDrawer from './UiDrawer.vue';

/**
 * Permitir o resgate de um Admin do Projeto que já existe.
 *
 * O cadastro do Admin já tem a opção; este painel é para quem entrou sem ela.
 * A pergunta é a mesma do cadastro de Colaborador (quais itens e, com dois ou
 * mais, se recebe todos ou escolhe um), e a resposta vale para o "Meu
 * presente" do Admin.
 */
const open = defineModel('open', { type: Boolean, default: false });

const props = defineProps({
    projectId: { type: Number, required: true },
    // { id, name, email }: o Admin que vai resgatar.
    admin: { type: Object, default: null },
    itemOptions: { type: Array, required: true },
    entradas: { type: Array, required: true },
});

const formId = useId();

// Um item só no pedido: não há o que escolher, ele vai marcado.
const itemUnico = computed(() => props.entradas.length === 1);

const blank = () => ({
    product_ids: itemUnico.value ? [...props.entradas[0].values] : [],
    mode: 'all',
});

const form = useForm(blank());

watch(open, (value) => {
    if (value) form.defaults(blank()).reset().clearErrors();
});

function submit() {
    form.post(route('projects.admins.redemption.grant', [props.projectId, props.admin.id]), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <UiDrawer
        v-model:open="open"
        title="Permitir resgate"
        :description="admin ? `${admin.name} continua Admin e passa a ter presente, em Meu presente.` : null"
    >
        <form :id="formId" class="space-y-6" @submit.prevent="submit">
            <UiAlert v-if="form.errors.resgate" variant="danger">{{ form.errors.resgate }}</UiAlert>

            <p v-if="itemUnico" class="text-sm text-ink">
                O pedido tem um item só: {{ entradas[0].label }}.
            </p>

            <ProjectItemsChoice
                v-else
                v-model:product-ids="form.product_ids"
                v-model:mode="form.mode"
                :options="itemOptions"
                :entradas="entradas"
                :errors="form.errors"
                label="Quais itens ele pode receber?"
            />
        </form>

        <template #footer>
            <UiButton type="submit" :form="formId" :loading="form.processing">Permitir resgate</UiButton>
            <UiButton variant="ghost" :disabled="form.processing" @click="open = false">Cancelar</UiButton>
        </template>
    </UiDrawer>
</template>
