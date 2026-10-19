<script setup>
import { computed } from 'vue';

/**
 * Distribuição de projetos por estágio do pipeline. Série única → um só tom
 * (índigo da marca); a identidade vem dos rótulos do eixo, nunca da cor.
 *
 * O valor fica FORA da barra, em coluna própria. Dentro dela ele era cortado:
 * a barra mais curta media 8% da área — ~19px no celular — e o número não
 * cabia entre os 16px de respiro horizontal. Fora, ele nunca some, os dígitos
 * alinham em coluna (`tabular-nums`) e a barra volta a ser proporcional ao
 * dado, sem o piso de 8% que existia só para tentar caber o texto.
 */
const props = defineProps({
    data: { type: Array, required: true }, // [{ label, value }]
});

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)));
const total = computed(() => props.data.reduce((s, d) => s + d.value, 0));
</script>

<template>
    <dl v-if="total > 0" class="space-y-2.5">
        <div v-for="d in data" :key="d.label" class="flex items-center gap-3">
            <dt class="w-24 shrink-0 text-right text-xs font-medium text-ink-muted">{{ d.label }}</dt>

            <dd class="flex min-w-0 flex-1 items-center gap-3">
                <!-- A barra é redundante com o número ao lado: para o leitor de
                     tela ela é ruído, e o par rótulo/valor já está no dt/dd. -->
                <div class="h-7 flex-1 overflow-hidden rounded-control bg-surface-muted" aria-hidden="true">
                    <div
                        class="h-full rounded-control bg-brand-600 transition-[width] duration-300 ease-out motion-reduce:transition-none"
                        :style="{
                            width: `${(d.value / max) * 100}%`,
                            minWidth: d.value ? '0.375rem' : '0',
                        }"
                    />
                </div>

                <span class="w-10 shrink-0 text-right text-sm font-semibold tabular-nums text-ink">
                    {{ d.value }}
                </span>
            </dd>
        </div>
    </dl>

    <p v-else class="py-8 text-center text-sm text-ink-muted">Nenhum projeto no pipeline ainda.</p>
</template>
