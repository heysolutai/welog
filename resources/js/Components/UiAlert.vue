<script setup>
import { computed, ref } from 'vue';
import UiIcon from './UiIcon.vue';
import UiButton from './UiButton.vue';

const props = defineProps({
    variant: { type: String, default: 'info' }, // info | success | warning | danger
    title: { type: String, default: null },
    dismissible: { type: Boolean, default: false },
});

const emit = defineEmits(['dismiss']);

/*
 * Informação e sucesso não são problema: viram uma linha discreta, sem caixa,
 * com o ícone pequeno. Atenção e erro continuam em caixa, mas suave e sem
 * borda: chamam o olho sem gritar. Antes os quatro eram caixa com borda e cor
 * cheia, e uma simples nota lia como alarme.
 */
const variants = {
    info: { box: '', icon: 'text-ink-subtle', name: 'infoCircle', quieto: true },
    success: { box: '', icon: 'text-success', name: 'check', quieto: true },
    warning: { box: 'rounded-card bg-warning-soft/60 px-4 py-3', icon: 'text-warning', name: 'alert' },
    danger: { box: 'rounded-card bg-danger-soft/70 px-4 py-3', icon: 'text-danger', name: 'alert' },
};

const tone = computed(() => variants[props.variant]);
const quieto = computed(() => tone.value.quieto === true);
const role = computed(() => (props.variant === 'danger' || props.variant === 'warning' ? 'alert' : 'status'));

const visible = ref(true);

function dismiss() {
    visible.value = false;
    emit('dismiss');
}
</script>

<template>
    <div
        v-if="visible"
        :role="role"
        :class="[
            'flex',
            quieto ? 'gap-2' : 'gap-3',
            tone.box,
        ]"
    >
        <UiIcon :name="tone.name" :size="quieto ? 16 : 18" :class="['mt-0.5 shrink-0', tone.icon]" />

        <div class="min-w-0 flex-1">
            <p v-if="title" :class="['text-sm text-ink', quieto ? 'font-medium' : 'font-semibold']">{{ title }}</p>
            <div :class="['text-sm', quieto ? 'text-ink-muted' : 'text-ink', title && (quieto ? 'mt-0.5' : 'mt-1')]">
                <slot />
            </div>
            <div v-if="$slots.actions" class="mt-3 flex flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <UiButton
            v-if="dismissible"
            variant="ghost"
            size="sm"
            icon="close"
            icon-only
            label="Fechar aviso"
            class="-mt-0.5 -mr-1 shrink-0"
            @click="dismiss"
        />
    </div>
</template>
