<script setup>
import UiIcon from './UiIcon.vue';

/**
 * Miniatura de item do pedido (foto do catálogo ou ícone neutro).
 *
 * Todo item ganha thumbnail na tabela de itens e no seletor de autorização —
 * não só o kit. O fallback é um ícone, nunca um buraco: célula vazia ao lado
 * de célula com foto lê como dado faltando, não como "este item não tem foto".
 */
defineProps({
    src: { type: String, default: null },
    /** Nome do item para leitores de tela; vazio marca a imagem como decorativa. */
    alt: { type: String, default: '' },
    size: { type: String, default: 'md' }, // sm (32px) | md (40px)
});
</script>

<template>
    <span
        :class="[
            'flex shrink-0 items-center justify-center overflow-hidden rounded-control border border-line bg-surface-muted',
            size === 'sm' ? 'size-8' : 'size-10',
        ]"
    >
        <img v-if="src" :src="src" :alt="alt" class="size-full object-cover" loading="lazy" />
        <UiIcon v-else name="image" :size="size === 'sm' ? 14 : 16" class="text-ink-subtle" />
    </span>
</template>
