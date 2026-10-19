<script setup>
import { Head } from '@inertiajs/vue3';
import MessageStudio from '../../Components/MessageStudio.vue';
import PageHeader from '../../Components/PageHeader.vue';

/**
 * Comunicações, do lado do Cliente: o Estúdio de mensagens em tela cheia.
 *
 * É a mesma ferramenta que a WeGift usa pelas Customizações — o Admin Cliente
 * ajusta a voz dos e-mails do resgate quando quiser, sem pedir a ninguém. O
 * menu só aparece quando os e-mails de resgate do Cliente estão ligados.
 */
defineProps({
    company: { type: Object, required: true },
});
</script>

<template>
    <Head title="Comunicações" />

    <PageHeader
        title="Comunicações"
        :subtitle="`O que os Colaboradores da ${company.name} recebem em cada etapa do resgate.`"
    />

    <div class="rounded-card bg-surface p-5 shadow-card sm:p-6">
        <MessageStudio :company-id="company.id" />
    </div>
</template>
