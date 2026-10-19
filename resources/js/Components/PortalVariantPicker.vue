<script setup>
import { computed, watch } from 'vue';
import UiField from './UiField.vue';
import UiSelect from './UiSelect.vue';

/**
 * Escolha de cor e tamanho, como numa loja.
 *
 *   <PortalVariantPicker v-model="form.variant_sku_id" :variants="variants" />
 *
 * `variants`: [{ id, color, color_hex, size, image_url, available }] — as
 * opções do produto pai pelo parent code. A variação é produzida depois da
 * escolha, então normalmente todas vêm disponíveis; se alguma vier com
 * `available: false`, aparece marcada como esgotada e desabilitada, nunca
 * some.
 *
 * A cor é escolhida nas bolinhas; o tamanho, numa lista. A cor abre na
 * primeira disponível; o tamanho NÃO vem marcado (a não ser que só exista
 * um): resgate com o tamanho errado não tem troca.
 */
const props = defineProps({
    variants: { type: Array, required: true },
    modelValue: { type: Number, default: null },
    /** Cor escolhida, controlada por fora (a foto da página acompanha). */
    color: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'update:color']);

/** Cores na ordem que vieram, uma vez cada. Null = família só de tamanhos. */
const cores = computed(() => {
    const vistas = new Map();

    for (const v of props.variants) {
        const chave = v.color ?? '';
        const atual = vistas.get(chave);

        vistas.set(chave, {
            nome: v.color,
            hex: v.color_hex,
            disponivel: (atual?.disponivel ?? false) || v.available,
        });
    }

    return [...vistas.values()];
});

const temCores = computed(() => cores.value.some((c) => c.nome));

const corAtual = computed(() => props.color ?? '');

const tamanhos = computed(() => props.variants.filter((v) => (v.color ?? '') === corAtual.value));

const temTamanhos = computed(() => tamanhos.value.some((v) => v.size));

const selecionada = computed(() => props.variants.find((v) => v.id === props.modelValue) ?? null);

function escolherCor(cor) {
    if (!cor.disponivel) return;

    emit('update:color', cor.nome ?? '');

    // Troca de cor mantém o tamanho quando ele existe e tem estoque na cor nova.
    const mesmoTamanho = props.variants.find(
        (v) => (v.color ?? '') === (cor.nome ?? '') && v.size === selecionada.value?.size && v.available,
    );
    emit('update:modelValue', mesmoTamanho?.id ?? null);
}

const opcoesDeTamanho = computed(() => tamanhos.value.map((v) => ({
    value: v.id,
    label: v.available ? v.size : `${v.size} (esgotado)`,
    disabled: !v.available,
})));

// O <select> fala em texto e '' é o "Selecione"; o formulário quer o id ou null.
const tamanhoEscolhido = computed({
    get: () => (tamanhos.value.some((v) => v.id === props.modelValue) ? props.modelValue : ''),
    set: (valor) => {
        const v = tamanhos.value.find((t) => String(t.id) === String(valor));
        emit('update:modelValue', v?.available ? v.id : null);
    },
});

// Abre na primeira cor com estoque; com um tamanho só (ou nenhum), ele já
// vem escolhido — não há decisão a tomar.
watch(
    () => [props.variants, corAtual.value],
    () => {
        if (props.color === null) {
            const primeira = cores.value.find((c) => c.disponivel) ?? cores.value[0];
            emit('update:color', primeira?.nome ?? '');

            return;
        }

        const disponiveis = tamanhos.value.filter((v) => v.available);
        if (props.modelValue === null && disponiveis.length === 1 && (tamanhos.value.length === 1 || !temTamanhos.value)) {
            emit('update:modelValue', disponiveis[0].id);
        }
    },
    { immediate: true },
);

/** Bolinha branca/clara precisa de contorno para existir sobre o fundo branco. */
function contornoForte(hex) {
    if (!hex) return true;
    const n = parseInt(hex.slice(1), 16);
    const luz = ((n >> 16) & 255) * 0.299 + ((n >> 8) & 255) * 0.587 + (n & 255) * 0.114;

    return luz > 225;
}
</script>

<template>
    <div class="space-y-5">
        <fieldset v-if="temCores">
            <legend class="text-sm font-medium text-ink">
                Cor: <span class="font-normal text-ink-muted">{{ corAtual || 'escolha' }}</span>
            </legend>

            <div class="mt-2.5 flex flex-wrap gap-3" role="radiogroup" aria-label="Cor">
                <button
                    v-for="cor in cores"
                    :key="cor.nome ?? '-'"
                    type="button"
                    role="radio"
                    :aria-checked="(cor.nome ?? '') === corAtual"
                    :aria-label="cor.disponivel ? cor.nome : `${cor.nome} (esgotada)`"
                    :title="cor.disponivel ? cor.nome : `${cor.nome}, esgotada`"
                    :disabled="!cor.disponivel"
                    class="relative flex size-11 items-center justify-center rounded-full transition-shadow focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-not-allowed"
                    :class="(cor.nome ?? '') === corAtual ? 'ring-2 ring-brand-600 ring-offset-2' : 'hover:ring-2 hover:ring-line-strong hover:ring-offset-2'"
                    @click="escolherCor(cor)"
                >
                    <span
                        class="block size-9 rounded-full"
                        :class="[contornoForte(cor.hex) && 'border border-line-strong', !cor.disponivel && 'opacity-40']"
                        :style="cor.hex ? { backgroundColor: cor.hex } : null"
                    >
                        <!-- Cor sem bolinha conhecida: a inicial, para não virar um círculo vazio. -->
                        <span v-if="!cor.hex" class="flex size-full items-center justify-center text-xs font-semibold text-ink-muted">
                            {{ (cor.nome ?? '?').slice(0, 2) }}
                        </span>
                    </span>
                    <!-- Esgotada: risco diagonal sobre a bolinha. -->
                    <span
                        v-if="!cor.disponivel"
                        aria-hidden="true"
                        class="pointer-events-none absolute h-0.5 w-10 rotate-45 bg-ink-muted"
                    />
                </button>
            </div>
        </fieldset>

        <!-- Tamanho numa lista (no celular, a lista nativa do sistema).
             Esgotado aparece na lista, marcado e sem poder escolher: nunca
             some, para a pessoa entender por que o tamanho dela não dá. -->
        <UiField v-if="temTamanhos" label="Tamanho">
            <UiSelect
                v-model="tamanhoEscolhido"
                :options="opcoesDeTamanho"
                placeholder="Selecione o tamanho"
                size="lg"
            />
        </UiField>
    </div>
</template>
