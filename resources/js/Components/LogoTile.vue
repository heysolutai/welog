<script setup>
/**
 * Uma logo em linha: miniatura, nome (opcional) e as ações em texto.
 *
 * Serve a logo do Cliente e a de cada Projeto nas Customizações. Compacta de
 * propósito: o que se quer aqui é ver que a marca certa subiu, não examiná-la
 * — e um cartão grande por logo transformava o ajuste numa galeria.
 *
 * Clicar na miniatura ou em "Enviar"/"Trocar" escolhe o arquivo, e já sobe.
 */
import { ref } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    /** Dono da logo: o Cliente ou o Projeto. */
    name: { type: String, required: true },
    url: { type: String, default: null },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['pick', 'remove']);

const input = ref(null);

function escolher(evento) {
    const arquivo = evento.target.files?.[0];

    // Zera o input: escolher o MESMO arquivo de novo (depois de um erro)
    // precisa disparar `change`, senão a tela parece travada.
    evento.target.value = '';

    if (arquivo) emit('pick', arquivo);
}
</script>

<template>
    <div class="flex min-w-0 items-center gap-3">
        <button
            type="button"
            class="relative flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-line bg-surface-muted text-ink-subtle transition-colors hover:border-brand-600/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            :disabled="busy"
            :aria-label="url ? `Trocar a logo de ${name}` : `Enviar a logo de ${name}`"
            @click="input?.click()"
        >
            <img v-if="url" :src="url" alt="" class="size-full bg-surface object-contain p-0.5" />
            <UiIcon v-else name="imageAdd" :size="16" />

            <span
                v-if="busy"
                aria-hidden="true"
                class="absolute inset-0 flex items-center justify-center bg-surface/75"
            >
                <UiIcon name="refresh" :size="14" class="animate-spin text-ink-muted" />
            </span>
        </button>

        <span class="min-w-0 flex-1 truncate text-sm text-ink" :title="name">{{ name }}</span>

        <span class="flex shrink-0 items-center gap-3 text-xs">
            <button
                type="button"
                class="font-medium text-brand-700 hover:underline disabled:opacity-60"
                :disabled="busy"
                tabindex="-1"
                aria-hidden="true"
                @click="input?.click()"
            >
                {{ url ? 'Trocar' : 'Enviar logo' }}
            </button>
            <button
                v-if="url"
                type="button"
                class="text-ink-muted transition-colors hover:text-danger disabled:opacity-60"
                :disabled="busy"
                :aria-label="`Remover a logo de ${name}`"
                @click="emit('remove')"
            >
                Remover
            </button>
        </span>

        <input
            ref="input"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            @change="escolher"
        />
    </div>
</template>
