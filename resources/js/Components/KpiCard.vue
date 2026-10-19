<script setup>
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';

/**
 * Indicador do painel: ícone, rótulo, número grande e uma linha de contexto.
 *
 * A linha de contexto não é enfeite — é o que separa "24 projetos" (dado) de
 * "2 novos hoje" (motivo de olhar). Sem ela o cartão vira placar.
 */
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], required: true },
    icon: { type: String, required: true },
    hint: { type: String, default: null },
    href: { type: String, default: null },
    // brand = rotina · warning = espera ação · danger = está travado
    tone: { type: String, default: 'brand' },
    // 'up' quando a linha de contexto é movimento; null quando é só estado
    trend: { type: String, default: null },
    /**
     * Cartão em CHAPA da marca, para o primeiro indicador da fila.
     *
     * Uma faixa de cartões brancos idênticos não tem entrada: o olho começa
     * pelo canto e lê os quatro com o mesmo peso. Um cartão cheio dá o ponto
     * de partida — é o papel que o cartão roxo cumpre no painel que serviu de
     * referência.
     *
     * Um só, e é isso que o faz funcionar. Dois cartões cheios voltam a ser
     * uma fila sem entrada, agora mais barulhenta.
     */
    filled: { type: Boolean, default: false },
});

const tones = {
    brand: 'bg-brand-50 text-brand-700',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
};

const HINT_TONES = {
    brand: 'text-ink-muted',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
};

// Cartão em rotina não pinta a linha: se tudo colore, nada destaca. Na chapa
// não há o que pintar — o tom já é o fundo, e a linha vive do contraste com
// ele (brand-100 sobre brand-800 dá 8,1:1).
const hintTone = computed(() => (props.filled ? 'text-brand-100' : HINT_TONES[props.tone] ?? HINT_TONES.brand));
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href || undefined"
        :class="[
            'group relative isolate flex min-h-28 items-start gap-3 overflow-hidden rounded-card p-5 text-left shadow-card transition-all',
            filled
                // 600 → 800: o mais vivo que ainda passa 4,5:1 com texto
                // branco, indo ao mais escuro. Parar no 700, como estava,
                // dava um marrom chapado — a variação é o que faz o gradiente
                // parecer luz em vez de mancha.
                ? 'bg-gradient-to-br from-brand-600 to-brand-800'
                : 'bg-surface',
            href && (filled
                ? 'hover:-translate-y-0.5 hover:shadow-card-hover'
                : 'hover:-translate-y-0.5 hover:shadow-card-hover'),
        ]"
    >
        <!-- Brilho de canto: dois círculos claros sangrando para fora. É o
             que separa "cartão colorido" de "cartão com luz", e é de onde vem
             o ar de profundidade da referência. `-z-10` mantém tudo abaixo do
             conteúdo; `overflow-hidden` no pai recorta a sobra. -->
        <template v-if="filled">
            <span
                aria-hidden="true"
                class="pointer-events-none absolute -top-14 -right-8 -z-10 size-36 rounded-full bg-ink-inverse/10"
            />
            <span
                aria-hidden="true"
                class="pointer-events-none absolute -top-4 -right-20 -z-10 size-36 rounded-full bg-ink-inverse/10"
            />
        </template>

        <span
            :class="[
                'flex size-11 shrink-0 items-center justify-center rounded-control',
                filled ? 'bg-ink-inverse/20 text-ink-inverse' : (tones[tone] ?? tones.brand),
            ]"
        >
            <UiIcon :name="icon" :size="20" aria-hidden="true" />
        </span>

        <span class="min-w-0 flex-1">
            <span :class="['block truncate text-sm', filled ? 'text-brand-50' : 'text-ink-muted']">{{ label }}</span>
            <span
                :class="[
                    'mt-0.5 block text-stat font-semibold tracking-tight tabular-nums',
                    filled ? 'text-ink-inverse' : 'text-ink',
                ]"
            >{{ value }}</span>

            <!-- A linha de contexto é o que separa "24 projetos" (placar) de
                 "2 novos hoje" (motivo de olhar). Ganha a cor do tom quando
                 pede ação, para o olho achar o cartão que importa. -->
            <span v-if="hint" class="mt-1.5 flex items-center gap-1 truncate text-xs">
                <UiIcon
                    v-if="trend"
                    :name="trend === 'up' ? 'external' : 'clock'"
                    :size="13"
                    aria-hidden="true"
                    :class="['shrink-0', hintTone]"
                />
                <span :class="['truncate', hintTone]">{{ hint }}</span>
            </span>
        </span>

        <UiIcon
            v-if="href"
            name="external"
            :size="16"
            aria-hidden="true"
            :class="[
                'mt-1 shrink-0 transition-colors',
                filled ? 'text-brand-200 group-hover:text-ink-inverse' : 'text-ink-subtle group-hover:text-brand-600',
            ]"
        />
    </component>
</template>
