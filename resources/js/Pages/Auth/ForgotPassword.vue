<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthCard from '../../Components/AuthCard.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiButton from '../../Components/UiButton.vue';
import UiField from '../../Components/UiField.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiInput from '../../Components/UiInput.vue';

defineOptions({ layout: null });

const page = usePage();
// A resposta do envio é sempre neutra (não revela se o e-mail existe) e chega
// como flash: é ela, e só ela, que marca a tela como enviada.
const status = computed(() => page.props.flash?.status ?? null);

const form = useForm({ email: '' });

function submit() {
    form.post(route('password.email'));
}
</script>

<template>
    <Head title="Receber link de acesso" />

    <AuthCard title="Receber link de acesso" subtitle="Enviamos um link para você criar ou trocar sua senha.">
        <!-- Enviado: o formulário sai de cena. Deixá-lo preenchido embaixo do
             aviso é o que faz a pessoa reenviar sem parar. -->
        <UiAlert v-if="status" variant="success" title="Pronto">
            <p>{{ status }}</p>

            <ul class="mt-2 list-disc space-y-1 pl-4">
                <li>O link vale por 1 hora.</li>
                <li>Não achou? Veja o spam ou a caixa de promoções.</li>
            </ul>

            <template #actions>
                <UiButton variant="secondary" size="lg" :loading="form.processing" @click="submit">
                    Enviar de novo
                </UiButton>
            </template>
        </UiAlert>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <UiField label="E-mail" :error="form.errors.email" required>
                <UiInput
                    v-model="form.email"
                    type="email"
                    size="lg"
                    :invalid="!!form.errors.email"
                    autocomplete="email"
                    autofocus
                />
            </UiField>

            <UiButton type="submit" size="lg" block :loading="form.processing">Enviar link</UiButton>
        </form>

        <template #footer>
            <Link :href="route('login')">
                <UiIcon name="chevron-left" :size="16" class="mr-1" />
                Voltar para entrar
            </Link>
        </template>
    </AuthCard>
</template>
