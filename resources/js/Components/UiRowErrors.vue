<script setup>
import { computed, ref } from 'vue';
import UiButton from './UiButton.vue';

const props = defineProps({
    // String única do bag `batch`: 'Linha 4: … | Linha 17: … | Linha 23: …'
    raw: { type: String, required: true },
    max: { type: Number, default: 10 },
});

const expanded = ref(false);

const errors = computed(() =>
    props.raw
        .split(' | ')
        .map((part) => part.trim())
        .filter(Boolean)
        .map((part) => {
            const match = part.match(/^Linha (\d+): (.*)$/);
            return match ? { line: Number(match[1]), message: match[2] } : { line: null, message: part };
        }),
);

const visible = computed(() => (expanded.value ? errors.value : errors.value.slice(0, props.max)));
const hidden = computed(() => errors.value.length - visible.value.length);
</script>

<template>
    <div class="space-y-2">
        <div class="scrollbar-slim max-h-64 overflow-y-auto rounded-control border border-line">
            <table class="w-full text-sm">
                <caption class="sr-only">
                    Linhas que falharam na importação
                </caption>
                <thead class="bg-surface-muted">
                    <tr>
                        <th
                            scope="col"
                            class="w-20 px-3 py-2 text-left text-xs font-semibold tracking-wider text-ink-muted uppercase"
                        >
                            Linha
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-left text-xs font-semibold tracking-wider text-ink-muted uppercase"
                        >
                            Motivo
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(error, index) in visible" :key="index">
                        <td class="px-3 py-2 align-top font-mono text-xs text-ink">
                            <template v-if="error.line !== null">{{ error.line }}</template>
                            <template v-else>
                                <span class="sr-only">Sem número de linha</span>
                                <span aria-hidden="true">-</span>
                            </template>
                        </td>
                        <td class="px-3 py-2 align-top text-ink">{{ error.message }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UiButton v-if="hidden > 0" variant="ghost" size="sm" @click="expanded = true">
            Ver mais {{ hidden }} {{ hidden === 1 ? 'linha' : 'linhas' }}
        </UiButton>
        <UiButton v-else-if="expanded && errors.length > max" variant="ghost" size="sm" @click="expanded = false">
            Ver menos
        </UiButton>
    </div>
</template>
