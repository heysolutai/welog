<script setup>
/**
 * Botão "Atualizar" (pedido do UAT em todas as abas).
 *
 * Existe porque a plataforma é SPA Inertia: mudanças feitas por webhook/job
 * (etapa vinda do Trello, estoque vindo da Omie) alteram o banco sem nenhum
 * sinal para a página aberta — foi exatamente a falha "virou Em Estoque e o
 * botão de liberar não apareceu". `router.reload()` refaz o GET da própria
 * página e renova todas as props, sem perder filtros (preserva querystring).
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import UiButton from './UiButton.vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    // Restringe o reload a props específicas (ex.: ['project']) — menos
    // payload; vazio recarrega tudo.
    only: { type: Array, default: () => [] },
    size: { type: String, default: 'sm' },
    // Portal: só o ícone, num círculo discreto. O botão com contorno e
    // sombra disputava com o presente, e quem resgata quase nunca precisa
    // dele: é ajuda para quando algo demorou, não a ação da tela.
    quiet: { type: Boolean, default: false },
});

const loading = ref(false);

function refresh() {
    router.reload({
        ...(props.only.length ? { only: props.only } : {}),
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}
</script>

<template>
    <button
        v-if="quiet"
        type="button"
        class="flex size-10 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-wait"
        aria-label="Atualizar"
        title="Atualizar"
        :disabled="loading"
        @click="refresh"
    >
        <UiIcon name="refresh" :size="18" :class="loading && 'animate-spin'" />
    </button>

    <UiButton
        v-else
        variant="secondary"
        :size="size"
        icon="refresh"
        :loading="loading"
        label="Atualizar"
        @click="refresh"
    >
        Atualizar
    </UiButton>
</template>
