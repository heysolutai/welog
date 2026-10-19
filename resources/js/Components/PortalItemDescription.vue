<script setup>
/**
 * A descrição de um presente no cartão da vitrine, em até 3 linhas.
 *
 * Texto maior que isso é cortado para os cartões ficarem do mesmo tamanho.
 * Para ler tudo, a mesma lupa da foto: o cursor vira lupa sobre o texto, e
 * clicar abre a ficha do presente com a descrição inteira.
 */
defineProps({
    text: { type: String, required: true },
    // O nome do presente, para quem usa leitor de tela.
    title: { type: String, required: true },
});

defineEmits(['abrir']);
</script>

<template>
    <button
        type="button"
        class="mt-1.5 block w-full cursor-zoom-in rounded-control text-sm text-ink-muted transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
        :aria-label="`Ver ${title} com a descrição inteira`"
        @click="$emit('abrir')"
    >
        <span class="line-clamp-3 whitespace-pre-line">{{ text }}</span>
    </button>
</template>
