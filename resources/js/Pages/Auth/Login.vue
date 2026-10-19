<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthCard from '../../Components/AuthCard.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiButton from '../../Components/UiButton.vue';
import UiField from '../../Components/UiField.vue';
import UiInput from '../../Components/UiInput.vue';
import UiPasswordInput from '../../Components/UiPasswordInput.vue';

defineOptions({ layout: null });

const page = usePage();
const status = computed(() => page.props.flash?.status ?? null);

const form = useForm({ email: '', password: '' });

// O controller manda duas mensagens diferentes no mesmo bag `email`:
// a de conta sem senha pede uma AÇÃO (pedir um novo link) e por isso sobe para
// o topo do cartão; a de acesso recusado fica nos dois campos, porque o que
// costuma estar errado é a senha, não o e-mail.
const CREDENCIAIS = 'Credenciais inválidas.';

const senhaNaoCriada = computed(() => (form.errors.email ?? '').includes('não definiu sua senha'));
const erroEmail = computed(() => (senhaNaoCriada.value ? null : (form.errors.email ?? null)));
const erroSenha = computed(() => form.errors.password ?? (erroEmail.value === CREDENCIAIS ? CREDENCIAIS : null));

const senhaVisivel = ref(false);

function submit() {
    form.post(route('login'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Entrar" />

    <AuthCard title="Entrar" subtitle="Acesse com o e-mail que recebeu o convite.">
        <template v-if="status || senhaNaoCriada" #alert>
            <div class="space-y-3">
                <UiAlert v-if="status" variant="info">{{ status }}</UiAlert>

                <UiAlert v-if="senhaNaoCriada" variant="warning">
                    Você ainda não criou sua senha.

                    <template #actions>
                        <UiButton variant="secondary" size="lg" :href="route('password.request')">
                            Receber link de acesso
                        </UiButton>
                    </template>
                </UiAlert>
            </div>
        </template>

        <form class="space-y-4" @submit.prevent="submit">
            <UiField label="E-mail" :error="erroEmail" required>
                <UiInput
                    v-model="form.email"
                    type="email"
                    size="lg"
                    :invalid="!!erroEmail"
                    autocomplete="email"
                    autofocus
                />
            </UiField>

            <UiField label="Senha" :error="erroSenha" required>
                <UiPasswordInput
                    v-model="form.password"
                    v-model:visible="senhaVisivel"
                    :invalid="!!erroSenha"
                    autocomplete="current-password"
                />
            </UiField>

            <UiButton type="submit" size="lg" block :loading="form.processing">Entrar</UiButton>
        </form>

        <template #footer>
            <Link :href="route('password.request')">Esqueci minha senha</Link>
        </template>
    </AuthCard>
</template>
