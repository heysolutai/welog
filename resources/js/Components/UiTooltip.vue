<script setup>
import { ref, useId } from 'vue';

/**
 * Explicação curta que aparece ao aproximar o ponteiro do gatilho.
 *
 * Serve para o que é BOM saber e não é obrigatório ler. O que a pessoa precisa
 * para decidir continua na tela: um aviso escondido atrás do ponteiro não
 * existe para quem usa toque, e não é onde se coloca instrução crítica.
 *
 * Abre também no FOCO do teclado, não só no hover — senão a explicação some
 * para quem navega por Tab. E fecha no Esc, porque um balão grudado sobre o
 * conteúdo, sem saída, é pior que não ter balão.
 *
 * `aria-describedby` no lugar de `title`: o nativo demora cerca de um segundo
 * para aparecer, não recebe estilo e alguns leitores de tela o anunciam junto
 * do rótulo, duplicando a leitura do botão.
 */
defineProps({
    text: { type: String, required: true },
    /** top | bottom — de que lado do gatilho o balão nasce. */
    placement: { type: String, default: 'top' },
    /**
     * center | start — onde o balão se apoia no eixo horizontal.
     *
     * Centralizado, um gatilho encostado na borda esquerda de um modal joga
     * metade do balão para fora, e o modal corta. `start` alinha o balão pelo
     * começo do gatilho e ele cresce só para dentro.
     */
    align: { type: String, default: 'center' },
});

const id = useId();
const aberto = ref(false);
</script>

<template>
    <!--
        `inline-flex` e não `block`: o invólucro não pode mudar o tamanho nem a
        posição de quem ele embrulha — o gatilho aqui é um botão que já tem
        lugar definido no cabeçalho.
    -->
    <span
        class="relative inline-flex"
        @mouseenter="aberto = true"
        @mouseleave="aberto = false"
        @focusin="aberto = true"
        @focusout="aberto = false"
        @keydown.esc="aberto = false"
    >
        <span :aria-describedby="id" class="contents">
            <slot />
        </span>

        <Transition
            enter-active-class="transition-opacity duration-150 motion-reduce:transition-none"
            leave-active-class="transition-opacity duration-100 motion-reduce:transition-none"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <span
                v-if="aberto"
                :id="id"
                role="tooltip"
                :class="[
                    'pointer-events-none absolute z-30 w-64 rounded-control bg-ink px-3 py-2 text-xs leading-snug font-normal text-ink-inverse shadow-card',
                    placement === 'bottom' ? 'top-full mt-2' : 'bottom-full mb-2',
                    align === 'start' ? 'left-0' : 'left-1/2 -translate-x-1/2',
                ]"
            >
                {{ text }}
            </span>
        </Transition>
    </span>
</template>
