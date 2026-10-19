<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthCard from '../../Components/AuthCard.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiButton from '../../Components/UiButton.vue';
import UiField from '../../Components/UiField.vue';
import UiPasswordInput from '../../Components/UiPasswordInput.vue';

defineOptions({ layout: null });

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: null },
});

const form = useForm({
    token: props.token,
    email: props.email ?? '',
    password: '',
    password_confirmation: '',
});

// Mesmo raciocínio do primeiro acesso: o e-mail não é editável, então tudo que
// cai no bag `email` é problema de link e aparece no topo do cartão.
const erroDeLink = computed(() => form.errors.email ?? null);

const senhaVisivel = ref(false);

function submit() {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Criar nova senha" />

    <AuthCard title="Criar nova senha">
        <template v-if="erroDeLink || !form.email" #alert>
            <UiAlert v-if="erroDeLink" variant="danger">{{ erroDeLink }}</UiAlert>

            <UiAlert v-else variant="warning">
                Este link está incompleto. Peça um novo abaixo, leva 1 minuto.
            </UiAlert>
        </template>

        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="form.email">
                <p class="text-xs font-semibold tracking-wider text-ink-muted uppercase">Nova senha para</p>
                <p class="mt-1 text-sm break-words text-ink">{{ form.email }}</p>
            </div>

            <UiField label="Nova senha" :error="form.errors.password" hint="Mínimo de 8 caracteres" required>
                <UiPasswordInput
                    v-model="form.password"
                    v-model:visible="senhaVisivel"
                    :invalid="!!form.errors.password"
                    autocomplete="new-password"
                    autofocus
                />
            </UiField>

            <!-- Mesma `senhaVisivel` dos dois campos: revelar um e continuar
                 digitando o outro às cegas é onde o erro de digitação mora. -->
            <UiField label="Confirmar senha" :error="form.errors.password" required>
                <UiPasswordInput
                    v-model="form.password_confirmation"
                    v-model:visible="senhaVisivel"
                    :invalid="!!form.errors.password"
                    autocomplete="new-password"
                />
            </UiField>

            <!-- Aqui o backend não autentica: redireciona para o login. Dito
                 antes do clique, senão a pessoa acha que a tela travou. -->
            <p class="text-xs text-ink-muted">Depois de salvar, você entra com a nova senha.</p>

            <UiButton type="submit" size="lg" block :loading="form.processing">Salvar senha</UiButton>
        </form>

        <template #footer>
            <p>
                O link expirou?
                <Link :href="route('password.request')">Receba um novo por e-mail</Link>
            </p>
        </template>
    </AuthCard>
</template>
