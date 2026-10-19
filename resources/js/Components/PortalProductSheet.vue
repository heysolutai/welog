<script setup>
import { computed, ref, watch } from 'vue';
import UiButton from './UiButton.vue';
import UiField from './UiField.vue';
import UiIcon from './UiIcon.vue';
import UiModal from './UiModal.vue';
import UiSelect from './UiSelect.vue';

/**
 * A ficha do presente: foto grande com lupa, a descrição inteira e, num
 * presente com tamanhos, a escolha do tamanho.
 *
 * Abre pela lupa da foto, pela descrição ou, no presente com tamanhos, pelo
 * "Quero este". Os tamanhos saíram do cartão: botões de tamanho dentro dele
 * deixavam cada cartão de uma altura, com o botão desalinhado do vizinho.
 *
 * A lupa amplia a foto 2x seguindo o mouse, como nas lojas; no celular, um
 * toque amplia no ponto tocado e outro volta. Clicar fora, no X ou Esc fecha.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    nome: { type: String, required: true },
    fotos: { type: Array, default: () => [] },
    descricao: { type: String, default: null },
    // Presente com tamanhos: [{ id, rotulo }] na ordem da loja. Vazio = presente único.
    tamanhos: { type: Array, default: () => [] },
    // Presente único: para onde vai o "Quero este" ({ href, label }).
    acao: { type: Object, default: null },
    pausado: { type: Boolean, default: false },
    // Opção travada porque outra do grupo foi escolhida.
    travado: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open']);

const atual = ref(0);

// O tamanho escolhido na lista. '' = nenhum: é o valor do "Selecione".
const selecionadoId = ref('');
const selecionado = computed(() => props.tamanhos.find((t) => String(t.id) === String(selecionadoId.value)) ?? null);
const opcoesDeTamanho = computed(() => props.tamanhos.map((t) => ({ value: t.id, label: t.rotulo })));

// Cada abertura começa na capa e sem tamanho escolhido.
watch(() => props.open, (aberto) => {
    if (aberto) {
        atual.value = 0;
        selecionadoId.value = '';
        zoom.value = false;
    }
});

const foto = computed(() => props.fotos[atual.value] ?? null);

// ── Lupa ────────────────────────────────────────────────────────────────────
const zoom = ref(false);
const origem = ref('50% 50%');

function mirar(evento) {
    const caixa = evento.currentTarget.getBoundingClientRect();
    const x = ((evento.clientX - caixa.left) / caixa.width) * 100;
    const y = ((evento.clientY - caixa.top) / caixa.height) * 100;
    origem.value = `${Math.min(100, Math.max(0, x))}% ${Math.min(100, Math.max(0, y))}%`;
}

function entrar(evento) {
    if (evento.pointerType !== 'mouse') return;
    mirar(evento);
    zoom.value = true;
}

function sair(evento) {
    if (evento.pointerType === 'mouse') zoom.value = false;
}

// Toque: amplia no ponto tocado, e o próximo toque volta.
function tocar(evento) {
    if (evento.pointerType === 'mouse') return;
    mirar(evento);
    zoom.value = !zoom.value;
}

</script>

<template>
    <!-- O nome do presente vem em destaque no corpo; o topo só diz o que é a
         janela, para o nome não aparecer duas vezes. -->
    <UiModal :open="open" title="Detalhes do presente" size="lg" @update:open="emit('update:open', $event)">
        <div class="grid gap-6 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <!-- ── Fotos ──────────────────────────────────────────────── -->
            <div class="min-w-0">
                <div
                    v-if="foto"
                    :class="[
                        'relative aspect-square overflow-hidden rounded-card bg-surface',
                        zoom ? 'cursor-zoom-out' : 'cursor-zoom-in',
                    ]"
                    @pointerenter="entrar"
                    @pointermove="zoom && mirar($event)"
                    @pointerleave="sair"
                    @pointerup="tocar"
                >
                    <img
                        :src="foto"
                        :alt="`Foto de ${nome}`"
                        draggable="false"
                        class="size-full object-contain transition-transform duration-200 ease-out select-none"
                        :style="{ transform: zoom ? 'scale(2)' : 'scale(1)', transformOrigin: origem }"
                    />

                    <span
                        v-if="!zoom"
                        aria-hidden="true"
                        class="pointer-events-none absolute right-3 bottom-3 flex size-8 items-center justify-center rounded-full bg-surface/90 text-ink-muted shadow-card"
                    >
                        <UiIcon name="search" :size="15" />
                    </span>
                </div>

                <div v-else class="flex aspect-square items-center justify-center rounded-card bg-surface-muted">
                    <UiIcon name="gift" :size="72" :stroke-width="1.1" class="text-ink-subtle" aria-hidden="true" />
                </div>

                <!-- As outras fotos, para trocar a grande. -->
                <ul v-if="fotos.length > 1" class="mt-3 flex flex-wrap gap-2">
                    <li v-for="(f, i) in fotos" :key="f + i">
                        <button
                            type="button"
                            :aria-label="`Ver foto ${i + 1} de ${fotos.length}`"
                            :aria-current="atual === i ? 'true' : undefined"
                            :class="[
                                'flex size-14 items-center justify-center overflow-hidden rounded-control border bg-surface transition-colors',
                                atual === i ? 'border-ink' : 'border-line hover:border-line-strong',
                            ]"
                            @click="atual = i; zoom = false"
                        >
                            <img :src="f" alt="" class="max-h-full max-w-full object-contain" />
                        </button>
                    </li>
                </ul>
            </div>

            <!-- ── Sobre o presente ───────────────────────────────────── -->
            <div class="flex min-w-0 flex-col">
                <h3 class="text-xl font-semibold tracking-tight text-ink">{{ nome }}</h3>

                <p
                    v-if="descricao"
                    class="mt-3 max-h-64 overflow-y-auto pr-1 text-[15px] leading-relaxed whitespace-pre-line text-ink-muted scrollbar-slim"
                >
                    {{ descricao }}
                </p>

                <!-- Tamanhos: a escolha antes do formulário de entrega, numa
                     lista (no celular, a lista nativa do sistema). -->
                <UiField v-if="tamanhos.length && !pausado && !travado" label="Tamanho" class="mt-6">
                    <UiSelect
                        v-model="selecionadoId"
                        :options="opcoesDeTamanho"
                        placeholder="Selecione o tamanho"
                        size="lg"
                    />
                </UiField>

                <div class="mt-auto pt-6">
                    <p v-if="pausado" class="text-sm text-ink-muted">
                        Resgate pausado temporariamente. Assim que liberarem, você recebe o aviso.
                    </p>
                    <p v-else-if="travado" class="text-sm text-ink-muted">Você já escolheu seu brinde.</p>

                    <!-- O botão só aparece depois do tamanho: até lá, a única
                         coisa a fazer é escolher. -->
                    <UiButton
                        v-else-if="tamanhos.length && selecionado"
                        size="lg"
                        block
                        :href="route('portal.redeem.form', selecionado.id)"
                    >
                        Continuar
                    </UiButton>

                    <UiButton v-else-if="acao" size="lg" block :href="acao.href">{{ acao.label }}</UiButton>
                </div>
            </div>
        </div>

    </UiModal>
</template>
