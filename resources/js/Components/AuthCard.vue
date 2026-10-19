<script setup>
import UiIcon from './UiIcon.vue';

/**
 * Moldura das telas de entrada (login, esqueci a senha, criar senha).
 *
 * Painel dividido: à esquerda a marca sobre a cor cheia, à direita o
 * formulário sobre branco. O arranjo anterior era um cartão estreito no meio
 * de uma página vazia — correto e sem presença nenhuma, que é o que faz uma
 * entrada parecer um formulário administrativo dos anos 2000.
 *
 * Abaixo de `lg` o painel esquerdo SAI. Não vira uma faixa em cima: em tela
 * de celular ele empurraria o formulário para baixo da dobra, e quem abre o
 * link do convite no telefone veria um banner, não um campo de e-mail.
 *
 * Os pontos de quebra olham a largura da PRÓPRIA moldura (container query),
 * não a da janela. Na tela de verdade dá no mesmo; na prévia das
 * Customizações, a moldura dentro do celular de mentira se comporta como
 * celular, mesmo com a janela do computador em volta.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    // Prévia: ocupa a altura do quadro que a contém, não a da janela.
    embedded: { type: Boolean, default: false },
});

// O que a plataforma faz, em três linhas. Fica no painel porque a tela de
// entrada é o único lugar em que alguém de fora lê sobre o produto.
const PILARES = [
    { icon: 'projects', text: 'Acompanhe cada projeto do design à entrega' },
    { icon: 'gift', text: 'Seus colaboradores escolhem e resgatam o kit' },
    { icon: 'truck', text: 'Rastreio do envio até a porta de quem recebe' },
];
</script>

<template>
    <div :class="['@container flex bg-canvas', embedded ? 'h-full' : 'min-h-dvh']">
        <!-- Painel da marca -->
        <div
            class="relative hidden w-[45%] max-w-2xl flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-600 via-brand-700 to-brand-900 p-12 @5xl:flex"
        >
            <!-- Círculos de luz sangrando para fora, como nos cartões de
                 indicador: é o que dá profundidade a uma chapa de cor. -->
            <span aria-hidden="true" class="pointer-events-none absolute -top-24 -right-20 size-96 rounded-full bg-ink-inverse/10" />
            <span aria-hidden="true" class="pointer-events-none absolute -bottom-32 -left-24 size-80 rounded-full bg-ink-inverse/5" />

            <div class="relative">
                <span class="flex flex-col leading-none">
                    <span class="text-3xl font-bold tracking-tight text-ink-inverse">
                        WeLog
                    </span>
                    <span class="mt-1.5 text-xs font-medium tracking-wide text-brand-100">
                        por WeGift
                    </span>
                </span>
            </div>

            <div class="relative">
                <p class="text-display font-bold tracking-tight text-ink-inverse">
                    Presentes corporativos,<br >do pedido à entrega.
                </p>

                <ul class="mt-8 space-y-4">
                    <li v-for="pilar in PILARES" :key="pilar.text" class="flex items-center gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-control bg-ink-inverse/15 text-ink-inverse">
                            <UiIcon :name="pilar.icon" :size="18" aria-hidden="true" />
                        </span>
                        <span class="text-sm text-brand-50">{{ pilar.text }}</span>
                    </li>
                </ul>
            </div>

            <p class="relative text-xs text-brand-100">
                © {{ new Date().getFullYear() }} WeGift
            </p>
        </div>

        <!-- Formulário -->
        <div class="bg-mesh flex min-w-0 flex-1 items-center justify-center px-4 py-10 @5xl:bg-none @5xl:bg-surface">
            <div class="w-full max-w-sm">
                <!-- O selo só aparece onde a marca NÃO está: com o painel
                     visível, repeti-la aqui seria dizer duas vezes a mesma
                     coisa a meio metro de distância. -->
                <div class="mb-8 text-center @5xl:text-left">
                    <span
                        aria-hidden="true"
                        class="mx-auto mb-5 flex size-14 items-center justify-center rounded-card bg-gradient-to-br from-brand-500 to-brand-700 text-xl font-bold text-ink-inverse shadow-brand @5xl:hidden"
                    >
                        W
                    </span>
                    <h1 class="text-title font-bold text-ink">{{ title }}</h1>
                    <p v-if="subtitle || $slots.subtitle" class="mt-2 text-sm text-ink-muted">
                        <slot name="subtitle">{{ subtitle }}</slot>
                    </p>
                </div>

                <!-- Sem cartão dentro do painel branco: caixa sobre caixa é o
                     que sobrava do arranjo antigo. No celular, onde o fundo
                     volta a ser o canvas, a elevação reaparece. -->
                <div class="rounded-card bg-surface p-6 shadow-overlay @min-[40rem]:p-8 @5xl:bg-transparent @5xl:p-0 @5xl:shadow-none">
                    <!-- Erro de token e aviso de "senha ainda não criada" entram aqui,
                         acima do formulário — nunca embaixo de um campo (§9.3). -->
                    <div v-if="$slots.alert" class="mb-5">
                        <slot name="alert" />
                    </div>

                    <slot />
                </div>

                <!-- O alvo de 44px do rodapé é garantido aqui: a tela escreve só o link. -->
                <div
                    v-if="$slots.footer"
                    class="mt-6 flex justify-center text-center text-sm text-ink-muted @5xl:justify-start [&_a]:inline-flex [&_a]:min-h-11 [&_a]:items-center [&_a]:font-medium [&_a]:text-brand-700 [&_a]:hover:underline"
                >
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </div>
</template>
