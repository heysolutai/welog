<script setup>
/**
 * Único ponto de upload do sistema.
 *
 * Nunca envia no `@change`: seleciona → mostra o arquivo → o pai clica em
 * enviar (§9.7). O <input type="file"> é `sr-only` (não `hidden`) para
 * continuar alcançável por teclado, e a caixa reage ao `:focus-within`.
 */
import { computed, inject, ref, useId } from 'vue';
import UiIcon from './UiIcon.vue';
import UiButton from './UiButton.vue';

const props = defineProps({
    accept: { type: String, default: '.xlsx,.csv' },
    disabled: { type: Boolean, default: false },
    progress: { type: Number, default: null }, // form.progress?.percentage
    file: { type: Object, default: null }, // o File selecionado, controlado pelo pai
    label: { type: String, default: 'Arraste o arquivo ou clique para selecionar' },
});

const emit = defineEmits(['select', 'clear']);

const field = inject('uiField', null);

const inputId = field?.id ?? useId();
const describedBy = computed(() => field?.describedBy.value);
const invalid = computed(() => field?.invalid.value === true);

const input = ref(null);
const dragging = ref(false);
const rejected = ref(null);
// dragleave dispara ao passar por cima dos filhos; sem contador a caixa pisca.
let dragDepth = 0;

const extensions = computed(() =>
    String(props.accept ?? '')
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean),
);

const acceptLabel = computed(() => {
    const list = extensions.value;

    if (!list.length) return null;
    if (list.length === 1) return `Aceita ${list[0]}`;

    return `Aceita ${list.slice(0, -1).join(', ')} e ${list[list.length - 1]}`;
});

const sizeLabel = computed(() => {
    const bytes = props.file?.size;

    if (typeof bytes !== 'number') return null;
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
});

const percentage = computed(() => Math.min(100, Math.max(0, Math.round(props.progress ?? 0))));
const uploading = computed(() => props.progress !== null && props.progress !== undefined);

function accepted(file) {
    const list = extensions.value;

    if (!list.length) return true;

    const name = file.name.toLowerCase();

    return list.some((item) => (item.startsWith('.') ? name.endsWith(item.toLowerCase()) : file.type === item));
}

function select(file) {
    if (!file) return;

    if (!accepted(file)) {
        rejected.value = `Formato não aceito. ${acceptLabel.value}.`;

        return;
    }

    rejected.value = null;
    emit('select', file);
}

function onChange(event) {
    select(event.target.files?.[0]);
    // Zera o input: escolher o mesmo arquivo de novo precisa disparar change.
    event.target.value = '';
}

function onDragEnter() {
    if (props.disabled || uploading.value) return;

    dragDepth += 1;
    dragging.value = true;
}

function onDragLeave() {
    dragDepth = Math.max(0, dragDepth - 1);

    if (dragDepth === 0) dragging.value = false;
}

function onDrop(event) {
    dragDepth = 0;
    dragging.value = false;

    if (props.disabled || uploading.value) return;

    select(event.dataTransfer?.files?.[0]);
}

function pick() {
    input.value?.click();
}

function clear() {
    rejected.value = null;
    emit('clear');
}
</script>

<template>
    <div>
        <!-- O input é sr-only: o foco global cairia num alvo invisível, então a caixa o repete. -->
        <div
            :class="[
                'rounded-control border transition-colors',
                'focus-within:border-brand-600 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-focus',
                dragging
                    ? 'border-brand-600 bg-brand-50'
                    : file || uploading
                      ? 'border-line bg-surface'
                      : 'border-dashed bg-surface-muted',
                !dragging && !file && !uploading && (invalid ? 'border-danger' : 'border-line-strong'),
            ]"
            @dragenter.prevent="onDragEnter"
            @dragover.prevent
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
        >
            <input
                :id="inputId"
                ref="input"
                type="file"
                class="sr-only"
                :accept="accept"
                :disabled="disabled || uploading"
                :aria-describedby="describedBy"
                :aria-invalid="invalid ? 'true' : null"
                @change="onChange"
            />

            <!-- Enviando: barra de progresso + texto de etapa (spinner mudo não basta) -->
            <div v-if="uploading" class="p-4" aria-busy="true">
                <div
                    class="h-2 w-full overflow-hidden rounded-full bg-line"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="percentage"
                    :aria-valuetext="`${percentage}%`"
                >
                    <div
                        class="h-full rounded-full bg-brand-600 transition-[width] duration-200 ease-out motion-reduce:transition-none"
                        :style="{ width: `${percentage}%` }"
                    />
                </div>
                <p role="status" class="mt-2 text-sm text-ink">
                    <slot name="progress" :percentage="percentage">
                        <template v-if="percentage < 100">Enviando o arquivo… {{ percentage }}%</template>
                        <template v-else>
                            Arquivo enviado. Processando no servidor, pode levar alguns minutos.
                            Não feche esta aba.
                        </template>
                    </slot>
                </p>
            </div>

            <!-- Escolhido: mostra o arquivo e espera o botão de enviar do pai -->
            <div v-else-if="file" class="flex flex-wrap items-center gap-x-3 gap-y-2 p-3">
                <UiIcon name="upload" :size="18" class="text-ink-muted" />
                <p class="min-w-0 flex-1 text-sm text-ink">
                    arquivo: <span class="font-medium">{{ file.name }}</span>
                    <span v-if="sizeLabel" class="text-ink-muted"> · {{ sizeLabel }}</span>
                </p>
                <UiButton variant="secondary" size="sm" :disabled="disabled" @click="pick">Trocar</UiButton>
                <UiButton
                    variant="ghost"
                    size="sm"
                    icon="close"
                    icon-only
                    label="Remover arquivo"
                    :disabled="disabled"
                    @click="clear"
                />
            </div>

            <!-- Vazio: a caixa inteira é rótulo do input, então o clique abre o seletor -->
            <label
                v-else
                :for="inputId"
                :class="[
                    'flex flex-col items-center gap-1 px-4 py-6 text-center',
                    disabled ? 'pointer-events-none opacity-60' : 'cursor-pointer',
                ]"
            >
                <UiIcon name="upload" :size="20" class="text-ink-muted" />
                <span class="text-sm font-medium text-ink">{{ label }}</span>
                <span v-if="acceptLabel" class="text-xs text-ink-muted">{{ acceptLabel }}</span>
            </label>
        </div>

        <p v-if="rejected" role="alert" class="mt-1.5 text-xs font-medium text-danger">{{ rejected }}</p>

        <div v-if="$slots.hint" class="mt-2 text-xs text-ink-muted">
            <slot name="hint" />
        </div>

        <div v-if="$slots.result" class="mt-3">
            <slot name="result" />
        </div>
    </div>
</template>
