<script setup>
import { computed, nextTick, onScopeDispose, ref, useId, watch } from 'vue';
import UiInput from './UiInput.vue';

/**
 * Campo de texto com sugestões vindas do servidor (padrão combobox).
 *
 *   <UiAutocomplete v-model="form.company_name" :fetch="buscarEmpresas" @select="aplicar">
 *       <template #option="{ item }">{{ item.name }} · {{ item.cnpj }}</template>
 *   </UiAutocomplete>
 *
 * O texto continua sendo texto livre (`v-model`): quem digita algo que não
 * existe segue em frente e cadastra. Escolher uma sugestão emite `select`
 * com o item inteiro — quem usa decide o que preencher a partir dele.
 *
 * `fetch(term, { signal })` deve devolver um array. A busca tem debounce e
 * cancela a anterior: a resposta de "ac" não pode chegar depois de "acme" e
 * sobrescrever a lista certa.
 */

defineOptions({ inheritAttrs: false });

const model = defineModel({ type: String, default: '' });

const props = defineProps({
    fetch: { type: Function, required: true },
    minChars: { type: Number, default: 2 },
    debounce: { type: Number, default: 250 },
    itemKey: { type: String, default: 'id' },
    /** Texto exibido quando a busca terminou e não achou nada. `null` esconde. */
    emptyText: { type: String, default: 'Nenhuma sugestão.' },
});

const emit = defineEmits(['select']);

const listId = useId();
const items = ref([]);
const open = ref(false);
const loading = ref(false);
const searched = ref(false); // já houve ao menos uma resposta para o termo atual
const active = ref(-1);
const focused = ref(false);

let timer = null;
let controller = null;
// Quando `select` preenche o modelo, o watch dispara: este flag evita que a
// própria escolha reabra a lista com uma nova busca.
let skipNext = false;

const optionId = (index) => `${listId}-opt-${index}`;
const activeId = computed(() => (open.value && active.value >= 0 ? optionId(active.value) : null));
const showEmpty = computed(() => open.value && searched.value && !loading.value && items.value.length === 0);
const showList = computed(() => open.value && (items.value.length > 0 || showEmpty.value));

function cancel() {
    clearTimeout(timer);
    timer = null;
    controller?.abort();
    controller = null;
    loading.value = false;
}

function close() {
    open.value = false;
    active.value = -1;
}

async function search(term) {
    cancel();
    controller = new AbortController();
    const { signal } = controller;
    loading.value = true;

    try {
        const result = await props.fetch(term, { signal });
        if (signal.aborted) return;
        items.value = Array.isArray(result) ? result : [];
        searched.value = true;
        active.value = -1;
        // Resposta que chega depois de a pessoa sair do campo não abre nada.
        open.value = focused.value;
    } catch (error) {
        if (signal.aborted || error?.name === 'AbortError') return;
        items.value = [];
        searched.value = false;
        close();
    } finally {
        if (!signal.aborted) loading.value = false;
    }
}

watch(model, (value) => {
    if (skipNext) {
        skipNext = false;
        return;
    }

    const term = (value ?? '').trim();
    // Preenchimento vindo de fora (outro campo, reset do formulário) não é
    // digitação: sem foco, só descarta o que estava listado.
    if (!focused.value) {
        cancel();
        items.value = [];
        searched.value = false;
        close();
        return;
    }
    cancel();
    searched.value = false;

    if (term.length < props.minChars) {
        items.value = [];
        close();
        return;
    }

    timer = setTimeout(() => search(term), props.debounce);
});

function select(item) {
    cancel(); // resposta atrasada de uma busca antiga não pode reabrir a lista
    skipNext = true;
    emit('select', item);
    items.value = [];
    close();
    // Se a escolha não mudou o texto (já estava igual), o watch não roda e a
    // flag ficaria presa engolindo a próxima digitação.
    nextTick(() => (skipNext = false));
}

function onFocus() {
    focused.value = true;
    if (items.value.length) open.value = true;
}

function onBlur() {
    // O `mousedown.prevent` nas opções mantém o foco no input, então o blur
    // só acontece quando a pessoa realmente saiu do campo.
    focused.value = false;
    close();
}

function onKeydown(event) {
    if (!showList.value) {
        if (event.key === 'ArrowDown' && items.value.length) {
            open.value = true;
            active.value = 0;
            event.preventDefault();
        }
        // Enter no meio de uma busca (debounce ou requisição) não pode virar
        // submit do formulário: a pessoa ainda está escolhendo a empresa.
        if (event.key === 'Enter' && (timer !== null || loading.value)) {
            event.preventDefault();
        }
        return;
    }

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            active.value = (active.value + 1) % items.value.length;
            break;
        case 'ArrowUp':
            event.preventDefault();
            active.value = active.value <= 0 ? items.value.length - 1 : active.value - 1;
            break;
        case 'Enter':
            if (active.value >= 0 && items.value[active.value]) {
                event.preventDefault();
                select(items.value[active.value]);
            }
            break;
        case 'Escape':
            event.preventDefault();
            close();
            break;
        case 'Tab':
            close();
            break;
    }
}

onScopeDispose(cancel);
</script>

<template>
    <div class="relative">
        <UiInput
            v-model="model"
            :loading="loading"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            :aria-expanded="showList ? 'true' : 'false'"
            :aria-controls="listId"
            :aria-activedescendant="activeId"
            v-bind="$attrs"
            @focus="onFocus"
            @blur="onBlur"
            @keydown="onKeydown"
        />

        <ul
            v-show="showList"
            :id="listId"
            role="listbox"
            class="animate-overlay-in absolute top-full left-0 z-30 mt-1 max-h-72 w-full min-w-64 overflow-y-auto rounded-control border border-line bg-surface py-1 shadow-overlay"
        >
            <li
                v-for="(item, index) in items"
                :id="optionId(index)"
                :key="item[itemKey] ?? index"
                role="option"
                :aria-selected="index === active ? 'true' : 'false'"
                :class="[
                    'cursor-pointer px-3 py-2 text-sm text-ink',
                    index === active ? 'bg-surface-muted' : 'hover:bg-surface-muted',
                ]"
                @mousedown.prevent
                @mouseenter="active = index"
                @click="select(item)"
            >
                <slot name="option" :item="item" :active="index === active">
                    {{ item.label ?? item.name ?? item }}
                </slot>
            </li>

            <li v-if="showEmpty && emptyText" class="px-3 py-2 text-sm text-ink-muted" role="presentation">
                {{ emptyText }}
            </li>
        </ul>
    </div>
</template>
