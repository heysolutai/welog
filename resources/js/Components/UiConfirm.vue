<script setup>
import { computed } from 'vue';
import UiAlert from './UiAlert.vue';
import UiButton from './UiButton.vue';
import UiModal from './UiModal.vue';
import { acceptConfirm, cancelConfirm, confirmState } from '../composables/useConfirm';

/**
 * Host único da confirmação de ação crítica. Um por layout — quem abre é o
 * `useConfirm()`, de qualquer tela.
 *
 * O foco inicial é o Cancelar (`data-autofocus`): a tecla Enter reflexa não
 * pode liberar 128 e-mails.
 *
 * O diálogo é sempre dispensável, inclusive esperando resposta: enquanto o
 * botão de confirmar está em `loading` (e portanto desabilitado), o de sair é
 * a única coisa que impede a tela de ficar presa. Ele muda de "Cancelar" para
 * "Fechar" nessa hora porque a escrita já foi enviada — sair não a desfaz.
 */
const dismissLabel = computed(() =>
    confirmState.pending ? 'Fechar' : confirmState.cancelLabel,
);
</script>

<template>
    <UiModal
        :open="confirmState.open"
        :title="confirmState.title"
        :description="confirmState.description"
        size="sm"
        layer="dialog"
        @close="cancelConfirm"
    >
        <!-- O `v-if` no invólucro, e não só nos filhos: uma <div> vazia ainda é
             conteúdo para o UiModal, que desenhava a faixa branca do corpo. -->
        <div v-if="confirmState.warning || confirmState.error || confirmState.pending" class="space-y-3">
            <UiAlert v-if="confirmState.warning" variant="warning">
                {{ confirmState.warning }}
            </UiAlert>

            <!-- Recusa do backend: o diálogo continua aberto com o motivo dentro. -->
            <UiAlert v-if="confirmState.error" variant="danger">
                {{ confirmState.error }}
            </UiAlert>

            <p v-if="confirmState.pending" role="status" class="text-sm text-ink-muted">
                Enviando… aguarde a resposta do sistema. Fechar agora não desfaz o envio.
            </p>
        </div>

        <template #footer>
            <UiButton
                :variant="confirmState.variant === 'danger' ? 'danger' : 'primary'"
                :loading="confirmState.pending"
                @click="acceptConfirm"
            >
                {{ confirmState.confirmLabel }}
            </UiButton>

            <UiButton variant="ghost" data-autofocus @click="cancelConfirm">
                {{ dismissLabel }}
            </UiButton>
        </template>
    </UiModal>
</template>
