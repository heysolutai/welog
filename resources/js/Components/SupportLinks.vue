<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';

/**
 * Canal de contato com a WeGift (UAT): WhatsApp e e-mail de suporte, vindos da
 * prop compartilhada `support` (ver SupportContact / HandleInertiaRequests).
 *
 * Âncoras de verdade, nunca <Link>: wa.me e mailto são destinos externos que o
 * Inertia não pode interceptar. Sem nenhum canal configurado o bloco some
 * inteiro — um título "Fale com a WeGift" sem link embaixo só frustra.
 */
const props = defineProps({
    // `nav` veste as cores da sidebar escura; `default`, as de superfície clara.
    tone: { type: String, default: 'default' }, // default | nav
});

const page = usePage();
const support = computed(() => page.props.support ?? {});
const hasAny = computed(() => Boolean(support.value.whatsapp_link || support.value.email));

const classes = computed(() => (props.tone === 'nav'
    ? {
        heading: 'text-nav-ink-subtle',
        link: 'text-nav-ink-muted hover:bg-nav-raised/60 hover:text-nav-ink',
        icon: 'text-nav-ink-muted',
    }
    : {
        heading: 'text-ink-subtle',
        link: 'text-ink-muted hover:bg-surface-muted hover:text-ink',
        icon: 'text-ink-subtle',
    }));
</script>

<template>
    <div v-if="hasAny">
        <p :class="['px-3 pb-1 text-[10px] font-semibold tracking-wide uppercase', classes.heading]">
            Fale com a WeGift
        </p>

        <ul class="space-y-0.5">
            <li v-if="support.whatsapp_link">
                <a
                    :href="support.whatsapp_link"
                    target="_blank"
                    rel="noopener noreferrer"
                    :class="['flex min-h-9 items-center gap-2.5 rounded-control px-3 text-xs font-medium transition-colors', classes.link]"
                >
                    <UiIcon name="whatsapp" :size="15" :class="classes.icon" />
                    <span class="truncate">WhatsApp {{ support.whatsapp }}</span>
                    <span class="sr-only">(abre em nova aba)</span>
                </a>
            </li>
            <li v-if="support.email">
                <a
                    :href="`mailto:${support.email}`"
                    :class="['flex min-h-9 items-center gap-2.5 rounded-control px-3 text-xs font-medium transition-colors', classes.link]"
                >
                    <UiIcon name="emails" :size="15" :class="classes.icon" />
                    <span class="truncate">{{ support.email }}</span>
                </a>
            </li>
        </ul>
    </div>
</template>
