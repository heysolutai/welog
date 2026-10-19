<script setup>
import { computed, useId } from 'vue';
import UiButton from './UiButton.vue';
// O motor de foco preso, trava de rolagem e exclusividade de painel é o mesmo
// do modal — ver o bloco `<script>` de UiModal.vue.
import { useOverlay } from './UiModal.vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    description: { type: String, default: null },
    size: { type: String, default: 'md' }, // mantido por simetria com UiModal
    dismissible: { type: Boolean, default: true },
    side: { type: String, default: 'right' }, // right | left
});

const emit = defineEmits(['update:open', 'close']);

const titleId = useId();
const descriptionId = useId();

function requestClose() {
    if (!props.dismissible) return;
    emit('update:open', false);
    emit('close');
}

const { panel } = useOverlay({
    open: () => props.open,
    close: requestClose,
    dismissible: () => props.dismissible,
    layer: 'panel',
});

// 480px no desktop, tela cheia abaixo de sm.
const width = computed(() => (props.size === 'lg' ? 'sm:max-w-[40rem]' : 'sm:max-w-[30rem]'));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            :class="['fixed inset-0 z-40 flex', side === 'left' ? 'justify-start' : 'justify-end']"
        >
            <div class="animate-overlay-in absolute inset-0 bg-ink/40" aria-hidden="true" @click="requestClose" />

            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                tabindex="-1"
                :aria-labelledby="titleId"
                :aria-describedby="description ? descriptionId : null"
                :class="[
                    'animate-drawer-in relative flex h-full w-full flex-col bg-surface shadow-overlay',
                    width,
                ]"
            >
                <header class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                    <div class="min-w-0">
                        <h2 :id="titleId" class="text-sm font-semibold text-ink">{{ title }}</h2>
                        <p v-if="description" :id="descriptionId" class="mt-1 text-sm text-ink-muted">
                            {{ description }}
                        </p>
                    </div>

                    <UiButton
                        v-if="dismissible"
                        variant="ghost"
                        size="sm"
                        icon="close"
                        icon-only
                        label="Fechar"
                        class="-mt-1 -mr-2 shrink-0"
                        @click="requestClose"
                    />
                </header>

                <div class="scrollbar-slim min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4">
                    <slot />
                </div>

                <footer
                    v-if="$slots.footer"
                    class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-4"
                >
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
