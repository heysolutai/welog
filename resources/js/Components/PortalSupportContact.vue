<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';

/**
 * "Precisa corrigir alguma informação?" — o canal de correção pedido no UAT.
 *
 * O resgate é irreversível e o portal não tem tela de edição: quem errou o
 * CPF ou o endereço precisa falar com um humano. Os canais vêm da prop
 * compartilhada `support` (config do servidor); sem nada configurado o bloco
 * some inteiro — melhor nenhum convite do que um convite sem destino.
 *
 * Links externos de verdade (wa.me / mailto) usam <a> comum, nunca UiButton
 * com `href`: ali o href vira Link do Inertia, que tentaria visitar a URL
 * por XHR dentro do app.
 */
const page = usePage();
const support = computed(() => page.props.support ?? {});

const hasWhatsapp = computed(() => !!support.value.whatsapp_link);
const hasEmail = computed(() => !!support.value.email);
// Com a faixa de atendimento do Cliente no topo, este bloco só repetiria o
// mesmo e-mail no fim da página.
const visible = computed(() => !support.value.banner && (hasWhatsapp.value || hasEmail.value));

// Mesmo desenho do UiButton "secondary" em tamanho de toque do portal (44px).
const linkClasses =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-control border border-line-strong '
    + 'bg-surface px-4 text-base font-medium text-ink shadow-xs transition-colors hover:bg-surface-muted';
</script>

<template>
    <section
        v-if="visible"
        aria-label="Contato para corrigir informações"
        class="rounded-card bg-surface shadow-card p-5"
    >
        <p class="text-base font-semibold text-ink">Precisa corrigir alguma informação?</p>
        <p class="mt-1 text-sm text-ink-muted">
            Fale com a WeGift: a gente ajusta os dados do seu pedido para você.
        </p>

        <div class="mt-3 flex flex-wrap gap-2">
            <a
                v-if="hasWhatsapp"
                :href="support.whatsapp_link"
                target="_blank"
                rel="noopener"
                :class="linkClasses"
            >
                WhatsApp
                <span v-if="support.whatsapp" class="font-normal text-ink-muted">{{ support.whatsapp }}</span>
                <UiIcon name="whatsapp" :size="16" class="text-ink-muted" />
                <span class="sr-only">(abre em outra janela)</span>
            </a>

            <a v-if="hasEmail" :href="`mailto:${support.email}`" :class="linkClasses">
                <UiIcon name="emails" :size="16" class="text-ink-muted" />
                {{ support.email }}
            </a>
        </div>
    </section>
</template>
