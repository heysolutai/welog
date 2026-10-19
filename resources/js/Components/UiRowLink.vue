<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Nome de uma linha que abre a página do registro.
 *
 * Já teve uma lupa ao lado, para anunciar "isto abre" sem depender do hover.
 * Saiu porque as listas passaram a ter uma coluna de ação explícita ("Ver
 * detalhes"): com ela, o ícone repetia o mesmo recado uma vez por linha e
 * virava ruído — numa tabela de 25 linhas, 25 lupas competindo com o dado.
 *
 * Onde não houver botão de ação, o hover e o `cursor: pointer` continuam
 * sendo a pista; se alguma lista ficar sem nenhuma das duas, o lugar de
 * resolver é lá, com uma ação de verdade, não com um ícone decorativo.
 */
defineProps({
    href: { type: String, required: true },
    // Segunda linha (empresa, e-mail): fica fora do link para o alvo de
    // clique ser o nome, não o parágrafo inteiro.
    hint: { type: String, default: null },
});
</script>

<template>
    <!--
        `max-w` aqui, e não na célula: em tabela com layout automático o
        navegador IGNORA max-width em `td`/`th` e alarga a coluna até o
        conteúdo caber. Era por isso que uma razão social comprida
        ("WEBOX SOLUCOES EM PRESENTES PERSONALIZADOS LTDA") empurrava as
        colunas da direita para fora da tela — o `truncate` abaixo nunca
        chegava a valer, porque a célula sempre dava a largura pedida.

        Num filho de largura limitada o truncate volta a funcionar, e a
        tabela para de rolar.
    -->
    <span class="block min-w-0 max-w-[22rem]">
        <!-- `prefetch`: a página começa a vir no hover, e o clique abre na hora. -->
        <Link
            :href="href"
            prefetch
            class="block max-w-full truncate font-medium text-ink transition-colors hover:text-brand-700"
        >
            <slot />
        </Link>

        <!-- `title`: cortado na tela, inteiro no hover. -->
        <span v-if="hint" :title="hint" class="block truncate text-xs font-normal text-ink-muted">{{ hint }}</span>
    </span>
</template>
