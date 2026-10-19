<script setup>
import { computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    links: { type: Array, default: () => [] }, // paginator->linkCollection()
    label: { type: String, default: 'Resultados' },
    // Paginação client-side: a lista inteira já veio nas props da página
    // (§9.5) e é ela que fatia. Sem `total`, o modo é o do backend.
    total: { type: Number, default: null },
    perPage: { type: Number, default: 25 },
    modelValue: { type: Number, default: 1 },
});

const emit = defineEmits(['update:modelValue']);

const serverMode = computed(() => props.links.length > 0);

// Sem `v-html`: o rótulo que o Laravel manda vem em HTML ("&laquo; Anterior")
// e nem sempre em pt-BR. Só os itens numéricos interessam — o resto do texto
// é escrito aqui.
const numbered = computed(() =>
    props.links
        .map((link) => ({ page: Number(String(link.label).trim()), url: link.url, active: link.active }))
        .filter((link) => Number.isInteger(link.page)),
);

const lastPage = computed(() =>
    serverMode.value
        ? numbered.value.reduce((last, link) => Math.max(last, link.page), 1)
        : Math.max(1, Math.ceil((props.total ?? 0) / props.perPage)),
);

const currentPage = computed(() =>
    serverMode.value
        ? (numbered.value.find((link) => link.active)?.page ?? 1)
        : Math.min(Math.max(1, props.modelValue), lastPage.value),
);

// Busca client-side encurta a lista: sem isto a página corrente fica além do
// fim e a tela mostra uma fatia vazia.
watch(lastPage, (last) => {
    if (!serverMode.value && props.modelValue > last) emit('update:modelValue', last);
});

const range = computed(() => {
    if (props.total === null) return null;

    const from = props.total === 0 ? 0 : (currentPage.value - 1) * props.perPage + 1;

    return `${from} a ${Math.min(currentPage.value * props.perPage, props.total)} de ${props.total}`;
});

// Janela de no máximo 5 números; `null` vira elipse.
const pages = computed(() => {
    const last = lastPage.value;
    if (last <= 5) return Array.from({ length: last }, (_, i) => i + 1);

    let start = Math.max(2, currentPage.value - 1);
    let end = Math.min(last - 1, currentPage.value + 1);

    if (currentPage.value <= 3) [start, end] = [2, 4];
    if (currentPage.value >= last - 2) [start, end] = [last - 3, last - 1];

    const window = [1];
    if (start > 2) window.push(null);
    for (let page = start; page <= end; page += 1) window.push(page);
    if (end < last - 1) window.push(null);
    window.push(last);

    return window;
});

const href = (page) => numbered.value.find((link) => link.page === page)?.url ?? null;

const reachable = (page) =>
    page >= 1 && page <= lastPage.value && (!serverMode.value || href(page) !== null);

function go(page) {
    if (!serverMode.value && reachable(page)) emit('update:modelValue', page);
}

// Um destino por vez: `<Link>` quando quem pagina é o backend, `<button>`
// quando a página fatia a lista, `<span>` inerte nos extremos.
function control(page, attrs) {
    if (!reachable(page)) {
        return { is: 'span', attrs: { 'aria-disabled': 'true', tabindex: -1, ...attrs } };
    }

    return serverMode.value
        ? { is: Link, attrs: { href: href(page), preserveScroll: true, ...attrs } }
        : { is: 'button', attrs: { type: 'button', ...attrs } };
}

const previous = computed(() => control(currentPage.value - 1, { 'aria-label': 'Página anterior' }));
const next = computed(() => control(currentPage.value + 1, { 'aria-label': 'Próxima página' }));

const items = computed(() =>
    pages.value.map((page, i) =>
        page === null
            ? { key: `elipse-${i}`, gap: true }
            : {
                  key: page,
                  page,
                  ...control(page, {
                      'aria-label': `Página ${page}`,
                      'aria-current': page === currentPage.value ? 'page' : null,
                  }),
              },
    ),
);

// Duas paginações podem conviver na mesma tela (Usuários e resgates do
// Projeto): o rótulo é o que distingue os dois <nav> (§12.1).
const navLabel = computed(() =>
    props.label === 'Resultados' ? 'Paginação' : `Paginação de ${props.label.toLowerCase()}`,
);

const base =
    'inline-flex h-control-md min-w-control-md items-center justify-center gap-1 rounded-full px-3 text-sm font-medium transition-colors';

const edgeClass = (page) => [
    base,
    reachable(page)
        ? 'border border-line bg-surface text-ink hover:bg-surface-muted'
        : 'cursor-not-allowed border border-line bg-surface text-ink-muted opacity-60',
];

const pageClass = (page) => [
    base,
    'tabular-nums',
    page === currentPage.value ? 'bg-brand-600 text-ink-inverse' : 'text-ink hover:bg-surface-muted',
];
</script>

<template>
    <nav
        v-if="lastPage > 1"
        :aria-label="navLabel"
        class="mt-4 flex flex-wrap items-center justify-between gap-3"
    >
        <p class="text-xs tabular-nums text-ink-muted">
            Página {{ currentPage }} de {{ lastPage }}
            <template v-if="range">
                <span class="text-ink-subtle" aria-hidden="true">·</span>
                {{ label }} {{ range }}
            </template>
        </p>

        <ul class="flex flex-wrap items-center gap-1">
            <li>
                <component
                    :is="previous.is"
                    v-bind="previous.attrs"
                    :class="edgeClass(currentPage - 1)"
                    @click="go(currentPage - 1)"
                >
                    <UiIcon name="chevron-left" />
                    <span class="hidden sm:inline">Anterior</span>
                </component>
            </li>

            <li v-for="item in items" :key="item.key">
                <span v-if="item.gap" class="px-1 text-sm text-ink-subtle" aria-hidden="true">…</span>

                <component
                    v-else
                    :is="item.is"
                    v-bind="item.attrs"
                    :class="pageClass(item.page)"
                    @click="go(item.page)"
                >
                    {{ item.page }}
                </component>
            </li>

            <li>
                <component
                    :is="next.is"
                    v-bind="next.attrs"
                    :class="edgeClass(currentPage + 1)"
                    @click="go(currentPage + 1)"
                >
                    <span class="hidden sm:inline">Próxima</span>
                    <UiIcon name="chevron-right" />
                </component>
            </li>
        </ul>
    </nav>
</template>
