<script setup>
import { computed, useSlots } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    back: { type: Object, default: null }, // { label, href }
    tone: { type: String, default: 'app' }, // app | portal
    sticky: { type: Boolean, default: true }, // ignorado em tone="portal"
    // Marca à esquerda do título, no mesmo desenho da tela do Projeto.
    logo: { type: String, default: null },
});

const slots = useSlots();
const portal = computed(() => props.tone === 'portal');

/**
 * Sticky só a partir de `lg`: abaixo disso o topo é da barra do layout, e dois
 * cabeçalhos grudados comeriam metade da tela do celular (§8.2).
 *
 * As margens negativas fazem o fundo cobrir toda a largura do `<main>` — sem
 * elas o conteúdo aparece pelas laterais ao rolar por baixo do cabeçalho.
 */
const classes = computed(() => [
    'mb-6',
    !portal.value && [
        '-mx-4 bg-surface px-4 pt-6 pb-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8',
        props.sticky && 'lg:sticky lg:top-0 lg:z-20',
        // Com abas, quem fecha o bloco é a divisória da própria fila de abas.
        !slots.tabs && 'border-b border-line',
    ],
]);
</script>

<template>
    <header :class="classes">
        <Link
            v-if="back"
            :href="back.href"
            :class="[
                'inline-flex items-center gap-1 font-medium text-ink-muted transition-colors hover:text-ink',
                portal ? 'min-h-12 text-base' : 'min-h-9 text-sm',
            ]"
        >
            <UiIcon name="chevron-left" :size="portal ? 18 : 16" />
            {{ back.label }}
        </Link>

        <!-- No portal a ação é um ícone discreto: fica no canto, sem quebrar
             para baixo da logo no celular. -->
        <div :class="['flex items-start justify-between gap-x-4 gap-y-3', portal ? 'flex-nowrap' : 'flex-wrap']">
            <div class="flex min-w-0 items-center gap-4">
                <!--
                    A logo é IRMÃ do bloco de texto, como na tela do Projeto:
                    quadrado de medida fixa (nada de esticar pela altura do
                    texto), `object-contain` com respiro para a marca não ser
                    cortada, e título e subtítulo numa coluna só ao lado dela.
                    Sem logo, não sobra moldura vazia.
                -->
                <span
                    v-if="logo"
                    class="relative size-24 shrink-0 overflow-hidden rounded-card border border-line bg-surface sm:size-30"
                >
                    <img :src="logo" alt="" class="absolute inset-0 size-full object-contain p-2.5" />
                </span>

                <div class="min-w-0">
                    <!-- Nível 1 da hierarquia. Precisa DOMINAR: era 24px/600,
                         próximo demais do título de seção para o olho separar. -->
                    <h1 class="text-title font-bold text-ink">{{ title }}</h1>
                    <p
                        v-if="subtitle"
                        :class="['mt-1.5 text-ink-muted', portal ? 'text-base' : 'text-sm']"
                    >
                        {{ subtitle }}
                    </p>
                </div>
            </div>

            <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.meta" class="mt-2 text-xs text-ink-muted">
            <slot name="meta" />
        </div>

        <!-- As abas encostam na borda inferior do cabeçalho. -->
        <div v-if="$slots.tabs" :class="['mt-4', !portal && '-mb-4']">
            <slot name="tabs" />
        </div>
    </header>
</template>
