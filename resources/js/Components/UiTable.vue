<script setup>
import { Comment, Fragment, Text, computed, onBeforeUnmount, onMounted, ref, useSlots } from 'vue';
import UiEmpty from './UiEmpty.vue';
import UiSkeleton from './UiSkeleton.vue';

const props = defineProps({
    // [{ key, label, align?: 'start' | 'end', numeric?, width?, srOnlyLabel? }]
    columns: { type: Array, required: true },
    caption: { type: String, default: null },
    density: { type: String, default: 'default' }, // compact | default
    stickyHeader: { type: Boolean, default: false },
    maxRows: { type: Number, default: null },
    loading: { type: Boolean, default: false },
});

// Até onde vai o mapa posição → coluna do <style> no fim do arquivo.
const MAPPED_COLUMNS = 10;
const SKELETON_ROWS = 5;

// `row`/`header` são a altura resultante do padding + entrelinha; servem só
// para converter `maxRows` em altura máxima do container.
// Linha mais alta no padrão (era 0,75rem): densidade de planilha é o outro
// motivo de uma lista parecer velha. A `compact` continua apertada, para as
// tabelas que vivem dentro de um painel.
const densities = {
    default: { px: '1.25rem', py: '0.9375rem', row: 52, header: 44 },
    compact: { px: '0.75rem', py: '0.5rem', row: 36, header: 32 },
};

const density = computed(() => densities[props.density] ?? densities.default);

// §9.4: abaixo de `md`, tabela com mais de 5 colunas vira lista de
// rótulo/valor — nunca rolagem em dois eixos.
const stacked = computed(() => props.columns.length > 5);

const headerSticky = computed(() => props.stickyHeader || props.maxRows !== null);

const containerStyle = computed(() => {
    const style = { '--cell-px': density.value.px, '--cell-py': density.value.py };

    // A `<td>` é escrita pela página e não sabe a que coluna pertence:
    // alinhamento e rótulo chegam nela por herança, indexados pela posição.
    props.columns.slice(0, MAPPED_COLUMNS).forEach((col, index) => {
        const n = index + 1;
        style[`--c${n}-align`] = col.numeric || col.align === 'end' ? 'right' : 'left';
        style[`--c${n}-nums`] = col.numeric ? 'tabular-nums' : 'normal';
        style[`--c${n}-label`] = col.srOnlyLabel ? 'none' : JSON.stringify(col.label ?? '');
    });

    if (props.maxRows) {
        style.maxHeight = `${density.value.header + props.maxRows * density.value.row}px`;
    }

    return style;
});

// Célula que a própria tabela desenha (esqueleto, estado vazio): não é de
// coluna nenhuma, então não herda alinhamento nem rótulo empilhado.
const ownCell = { '--cell-label': 'none', '--cell-align': 'left' };

const skeletonRows = computed(() => Array.from({ length: SKELETON_ROWS }, (_, i) => i));

const slots = useSlots();

function isRendered(node) {
    if (node.type === Comment) return false;
    if (node.type === Fragment) return Array.isArray(node.children) && node.children.some(isRendered);
    if (node.type === Text) return String(node.children).trim() !== '';
    return true;
}

// As linhas vêm do slot, então a única forma de saber que a lista está vazia
// (e trocar pelo `#empty`) é olhar os nós que a página produziu.
const hasRows = () => (slots.default?.() ?? []).some(isRendered);

const scroller = ref(null);
const table = ref(null);
const atStart = ref(true);
const atEnd = ref(true);
const scrollable = ref(false);

// A sombra de borda só existe quando há conteúdo cortado — senão vira enfeite
// permanente que sugere rolagem inexistente.
function syncEdges() {
    const el = scroller.value;
    if (!el) return;

    const overflowX = el.scrollWidth - el.clientWidth;
    atStart.value = el.scrollLeft <= 1;
    atEnd.value = el.scrollLeft >= overflowX - 1;
    scrollable.value = overflowX > 1 || el.scrollHeight - el.clientHeight > 1;
}

let observer = null;

onMounted(() => {
    observer = new ResizeObserver(syncEdges);
    observer.observe(scroller.value);
    observer.observe(table.value);
    syncEdges();
});

onBeforeUnmount(() => observer?.disconnect());

if (import.meta.env.DEV) {
    if (!props.caption) {
        console.warn('[UiTable] `caption` ausente: toda tabela precisa de legenda sr-only (§12.6).');
    }
    if (props.columns.some((col) => !col.label)) {
        console.warn('[UiTable] coluna sem `label`: use `srOnlyLabel: true` em vez de rótulo vazio.');
    }
    if (props.columns.length > MAPPED_COLUMNS) {
        console.warn(
            `[UiTable] mais de ${MAPPED_COLUMNS} colunas: da ${MAPPED_COLUMNS + 1}ª em diante a coluna perde alinhamento à direita e rótulo empilhado.`,
        );
    }
}
</script>

<template>
    <div class="relative">
        <div
            ref="scroller"
            :class="[
                'ui-table scrollbar-slim overflow-auto rounded-card bg-surface shadow-card',
                stacked && 'is-stacked',
            ]"
            :style="containerStyle"
            :role="scrollable && caption ? 'region' : null"
            :aria-label="scrollable && caption ? caption : null"
            :tabindex="scrollable ? 0 : null"
            :aria-busy="loading ? 'true' : null"
            @scroll.passive="syncEdges"
        >
            <table ref="table" class="w-full text-sm text-ink">
                <caption v-if="caption" class="sr-only">
                    {{ caption }}
                </caption>

                <!--
                    Cabeçalho sem faixa cinza e sem caixa alta. A faixa
                    desenhava uma barra sólida no topo de cada lista, e o
                    `uppercase` com tracking largo é o sotaque de planilha —
                    juntos davam a aparência de relatório antigo, mesmo com o
                    resto da tela nova. O que separa o cabeçalho das linhas
                    agora é peso, cor e a divisória.
                -->
                <thead>
                    <tr>
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            scope="col"
                            :class="[
                                'border-b border-line bg-surface text-xs font-semibold whitespace-nowrap text-ink-muted',
                                headerSticky && 'sticky top-0 z-10',
                            ]"
                            :style="col.width ? { width: col.width } : null"
                        >
                            <span :class="col.srOnlyLabel && 'sr-only'">{{ col.label }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody v-if="loading" aria-hidden="true">
                    <tr v-for="row in skeletonRows" :key="row">
                        <td v-for="col in columns" :key="col.key" :style="ownCell">
                            <UiSkeleton :lines="1" />
                        </td>
                    </tr>
                </tbody>

                <tbody v-else>
                    <slot v-if="hasRows()" />

                    <tr v-else>
                        <td :colspan="columns.length" class="p-3" :style="ownCell">
                            <slot name="empty">
                                <UiEmpty message="Nenhum registro." />
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <span
            v-show="!atStart"
            class="pointer-events-none absolute inset-y-px left-px w-6 rounded-l-card bg-linear-to-r from-ink/10 to-transparent"
            aria-hidden="true"
        />
        <span
            v-show="!atEnd"
            class="pointer-events-none absolute inset-y-px right-px w-6 rounded-r-card bg-linear-to-l from-ink/10 to-transparent"
            aria-hidden="true"
        />

        <p v-if="loading" class="sr-only" role="status">Carregando…</p>
    </div>
</template>

<style scoped>
/* A `<tr>` vem do slot da página: quem impõe densidade, alinhamento,
   separador e rótulo é a tabela — daí o `:deep()`. Tudo mora na camada
   `components` para que uma classe utilitária escrita na própria célula
   continue vencendo. */
@layer components {
    .ui-table :deep(th),
    .ui-table :deep(td) {
        padding: var(--cell-py) var(--cell-px);
        text-align: var(--cell-align, left);
        font-variant-numeric: var(--cell-nums, normal);
        vertical-align: middle;
    }

    .ui-table :deep(tbody tr + tr) {
        border-top: 1px solid var(--color-line);
    }

    .ui-table :deep(tbody tr:hover) {
        background-color: var(--color-surface-muted);
    }

    /* `<th scope="row">` é o nome da linha, não título de bloco: peso de
       tabela, não o `bold` que o navegador dá de graça. */
    .ui-table :deep(tbody th) {
        font-weight: 500;
    }

    /* Mapa posição → coluna. Os valores `--c*` saem do inline style do
       container (`containerStyle`) e param aqui, na célula certa. */
    .ui-table :deep(tr > :nth-child(1)) { --cell-align: var(--c1-align); --cell-nums: var(--c1-nums); --cell-label: var(--c1-label); }
    .ui-table :deep(tr > :nth-child(2)) { --cell-align: var(--c2-align); --cell-nums: var(--c2-nums); --cell-label: var(--c2-label); }
    .ui-table :deep(tr > :nth-child(3)) { --cell-align: var(--c3-align); --cell-nums: var(--c3-nums); --cell-label: var(--c3-label); }
    .ui-table :deep(tr > :nth-child(4)) { --cell-align: var(--c4-align); --cell-nums: var(--c4-nums); --cell-label: var(--c4-label); }
    .ui-table :deep(tr > :nth-child(5)) { --cell-align: var(--c5-align); --cell-nums: var(--c5-nums); --cell-label: var(--c5-label); }
    .ui-table :deep(tr > :nth-child(6)) { --cell-align: var(--c6-align); --cell-nums: var(--c6-nums); --cell-label: var(--c6-label); }
    .ui-table :deep(tr > :nth-child(7)) { --cell-align: var(--c7-align); --cell-nums: var(--c7-nums); --cell-label: var(--c7-label); }
    .ui-table :deep(tr > :nth-child(8)) { --cell-align: var(--c8-align); --cell-nums: var(--c8-nums); --cell-label: var(--c8-label); }
    .ui-table :deep(tr > :nth-child(9)) { --cell-align: var(--c9-align); --cell-nums: var(--c9-nums); --cell-label: var(--c9-label); }
    .ui-table :deep(tr > :nth-child(10)) { --cell-align: var(--c10-align); --cell-nums: var(--c10-nums); --cell-label: var(--c10-label); }
}

/* §9.4 — lista empilhada de rótulo/valor em tela estreita: o cabeçalho de
   cada coluna reaparece como `::before` da célula. Célula que a tabela
   desenha (esqueleto, vazio) traz `--cell-label: none` e não ganha rótulo. */
@media (width < 48rem) {
    @layer components {
        .ui-table.is-stacked thead {
            display: none;
        }

        .ui-table.is-stacked :deep(tbody tr) {
            display: block;
            padding-block: 0.5rem;
        }

        .ui-table.is-stacked :deep(tbody tr > *) {
            display: block;
            padding-block: 0.125rem;
            text-align: left;
        }

        .ui-table.is-stacked :deep(tbody td)::before {
            content: var(--cell-label, none);
            display: block;
            font-size: 0.75rem;
            line-height: 1rem;
            color: var(--color-ink-muted);
        }
    }
}
</style>
