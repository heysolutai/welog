<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import UiAlert from '../../Components/UiAlert.vue';
import UiButton from '../../Components/UiButton.vue';
import UiField from '../../Components/UiField.vue';
import UiFormHeading from '../../Components/UiFormHeading.vue';
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

// O token vale só para este e-mail, então o campo é texto e o valor viaja
// oculto no formulário. Consequência: todo erro do bag `email` é problema de
// LINK (expirado, já usado, e-mail desconhecido) — vai para o topo do cartão,
// com a saída no rodapé, nunca embaixo de um campo que ninguém pode corrigir.
const erroDeLink = computed(() => form.errors.email ?? null);

const senhaVisivel = ref(false);

function submit() {
    form.post(route('password.set'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Criar sua senha" />

    <!-- Mesma moldura do AuthCard, mas no padrão "telas de cadastro": cartão
         rounded-card com UiFormHeading dentro, em vez do título só no topo. -->
    <div class="bg-mesh flex min-h-dvh items-center justify-center bg-canvas px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-6 text-center">
                <span
                    aria-hidden="true"
                    class="mx-auto mb-4 flex size-12 items-center justify-center rounded-control bg-brand-600 text-lg font-bold text-ink-inverse"
                >
                    W
                </span>
                <h1 class="text-title font-bold text-ink">Que bom ter você aqui!</h1>
                <p class="mt-1.5 text-sm text-ink-muted">Falta só um passo para você entrar.</p>
            </div>

            <div class="rounded-card bg-surface shadow-card p-6 sm:p-8">
                <!-- Erro de token e link incompleto entram aqui, acima do
                     formulário — nunca embaixo de um campo (§9.3). -->
                <div v-if="erroDeLink || !form.email" class="mb-5">
                    <UiAlert v-if="erroDeLink" variant="danger">{{ erroDeLink }}</UiAlert>

                    <UiAlert v-else variant="warning">
                        Este link de convite está incompleto. Peça um novo abaixo, leva 1 minuto.
                    </UiAlert>
                </div>

                <UiFormHeading icon="check" title="Crie sua senha" />

                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <div v-if="form.email">
                        <p class="text-xs font-semibold tracking-wider text-ink-muted uppercase">
                            Definindo a senha de
                        </p>
                        <p class="mt-1 text-sm break-words text-ink">{{ form.email }}</p>
                    </div>

                    <UiField label="Sua senha" :error="form.errors.password" hint="Mínimo de 8 caracteres" required>
                        <UiPasswordInput
                            v-model="form.password"
                            v-model:visible="senhaVisivel"
                            :invalid="!!form.errors.password"
                            autocomplete="new-password"
                            autofocus
                        />
                    </UiField>

                    <!-- Os dois campos mostram e escondem juntos: revelar um e
                         digitar o outro às cegas é onde o erro acontece. -->
                    <UiField label="Confirmar senha" :error="form.errors.password" required>
                        <UiPasswordInput
                            v-model="form.password_confirmation"
                            v-model:visible="senhaVisivel"
                            :invalid="!!form.errors.password"
                            autocomplete="new-password"
                        />
                    </UiField>

                    <div class="border-t border-line pt-5">
                        <UiButton type="submit" size="lg" block :loading="form.processing">
                            Criar senha e entrar
                        </UiButton>
                    </div>
                </form>
            </div>

            <div class="mt-4 flex justify-center text-center text-sm text-ink-muted">
                <p>
                    O link expirou?
                    <Link
                        :href="route('password.request')"
                        class="inline-flex min-h-11 items-center px-2 font-medium text-brand-700 hover:underline"
                    >
                        Receba um novo por e-mail
                    </Link>
                </p>
            </div>
        </div>
    </div>
</template>
