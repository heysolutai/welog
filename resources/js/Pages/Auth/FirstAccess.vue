<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import FirstAccessScreen from '../../Components/FirstAccessScreen.vue';

defineOptions({ layout: null });

const props = defineProps({
    token: { type: String, required: true },
    company: { type: Object, required: true },
});

const form = useForm({ email: '' });

/*
 * Para onde o link saiu. O servidor só responde sem erro quando ele saiu de
 * verdade: e-mail não cadastrado, já com senha ou de campanha não liberada
 * volta como erro no campo.
 */
const enviadoPara = ref(null);
const reenviadoAs = ref(null);

function enviar() {
    const reenvio = enviadoPara.value !== null;

    form.post(route('primeiro-acesso.send', props.token), {
        preserveScroll: true,
        // A confirmação vive nesta tela: recriar a página a apagaria.
        preserveState: true,
        onSuccess: () => {
            enviadoPara.value = form.email.trim().toLowerCase();
            reenviadoAs.value = reenvio
                ? new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
                : null;
        },
    });
}

function trocarEmail() {
    enviadoPara.value = null;
    reenviadoAs.value = null;
    // Não `reset()`: depois de um envio que deu certo, o Inertia guarda o
    // e-mail enviado como o valor inicial, e o campo voltaria preenchido.
    form.defaults({ email: '' }).reset();
    form.clearErrors();
}
</script>

<template>
    <Head title="Primeiro acesso" />

    <FirstAccessScreen
        :company-name="company.name"
        :dica="company.dica"
        :form="form"
        :enviado-para="enviadoPara"
        :reenviado-as="reenviadoAs"
        @submit="enviar"
        @trocar-email="trocarEmail"
    />
</template>
