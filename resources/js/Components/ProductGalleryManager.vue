<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
import { useConfirm } from '../composables/useConfirm';

/**
 * A galeria do item principal: até 10 fotos, em ordem, a primeira é a capa.
 *
 * Escolher arquivos já envia (sem um "Enviar" a mais), e cada foto tem as
 * próprias ações: mover para os lados e remover. A capa é a da primeira
 * posição; trazer outra para lá é o jeito de trocá-la.
 *
 * As fotos são do PRODUTO: valem em todos os Projetos que usam o item e no
 * portal do Colaborador.
 */
const props = defineProps({
    // { product_id, grade_code, name, omie_code, fotos: [{ id, url }], imagem_omie }
    galeria: { type: Object, required: true },
    // Dentro de um cartão que já se chama Fotos, o título repetiria.
    showTitle: { type: Boolean, default: true },
});

const MAX = 10;
const page = usePage();
const confirm = useConfirm();

// Espelho local da ordem: a foto muda de lugar no clique, e o servidor confirma.
const fotos = ref([...props.galeria.fotos]);
watch(() => props.galeria.fotos, (v) => (fotos.value = [...v]));

const cabem = computed(() => MAX - fotos.value.length);

const erro = computed(() => {
    const erros = page.props.errors ?? {};
    const chave = Object.keys(erros).find((k) => k === 'photos' || k.startsWith('photos.'));

    return chave ? erros[chave] : null;
});

const entrada = ref(null);
const enviando = ref(false);
const progresso = ref(null);

function escolher(evento) {
    const arquivos = [...(evento.target.files ?? [])];
    evento.target.value = '';
    if (!arquivos.length) return;

    router.post(route('products.photos.store'), {
        product_id: props.galeria.product_id,
        grade_code: props.galeria.product_id ? null : props.galeria.grade_code,
        photos: arquivos,
    }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onStart: () => (enviando.value = true),
        onProgress: (p) => (progresso.value = p?.percentage ?? null),
        onFinish: () => {
            enviando.value = false;
            progresso.value = null;
        },
    });
}

function mover(indice, passo) {
    const destino = indice + passo;
    if (destino < 0 || destino >= fotos.value.length) return;

    const anterior = [...fotos.value];
    const nova = [...fotos.value];
    [nova[indice], nova[destino]] = [nova[destino], nova[indice]];
    fotos.value = nova;

    router.put(route('products.photos.reorder', props.galeria.product_id), { ids: nova.map((f) => f.id) }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => (fotos.value = anterior),
    });
}

async function remover(foto, indice) {
    await confirm({
        title: indice === 0 && fotos.value.length > 1 ? 'Remover a capa?' : 'Remover esta foto?',
        description: indice === 0 && fotos.value.length > 1
            ? 'A próxima foto vira a capa.'
            : 'Ela sai deste item em todos os Projetos e no portal.',
        confirmLabel: 'Remover foto',
        variant: 'danger',
        errorBag: 'photos',
        action: () => router.delete(route('products.photos.destroy', foto.id), { preserveScroll: true, preserveState: true }),
    });
}
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-3">
            <p v-if="showTitle" class="text-sm font-medium text-ink">Fotos</p>
            <p class="text-xs text-ink-muted tabular-nums">{{ fotos.length }} de {{ MAX }}</p>
        </div>
        <p class="mt-0.5 text-xs text-ink-muted">
            A primeira é a capa. Com mais de uma, o portal mostra em carrossel.
        </p>

        <p v-if="erro" role="alert" class="mt-3 text-sm text-danger">{{ erro }}</p>

        <ul class="mt-4 grid grid-cols-3 gap-3">
            <li v-for="(foto, i) in fotos" :key="foto.id" class="min-w-0">
                <div class="relative flex aspect-square items-center justify-center overflow-hidden rounded-control border border-line bg-surface">
                    <img :src="foto.url" :alt="`Foto ${i + 1} de ${galeria.name}`" class="max-h-full max-w-full object-contain" />
                    <span
                        v-if="i === 0"
                        class="absolute top-1.5 left-1.5 rounded-full bg-ink/80 px-2 py-0.5 text-[11px] font-medium text-ink-inverse"
                    >
                        Capa
                    </span>
                </div>

                <div class="mt-1 flex items-center justify-between">
                    <div class="flex">
                        <button
                            type="button"
                            class="flex size-7 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-surface-muted hover:text-ink disabled:opacity-30 disabled:hover:bg-transparent"
                            :aria-label="i === 1 ? 'Usar como capa' : 'Mover para a esquerda'"
                            :title="i === 1 ? 'Usar como capa' : 'Mover para a esquerda'"
                            :disabled="i === 0"
                            @click="mover(i, -1)"
                        >
                            <UiIcon name="chevronLeft" :size="15" />
                        </button>
                        <button
                            type="button"
                            class="flex size-7 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-surface-muted hover:text-ink disabled:opacity-30 disabled:hover:bg-transparent"
                            aria-label="Mover para a direita"
                            title="Mover para a direita"
                            :disabled="i === fotos.length - 1"
                            @click="mover(i, 1)"
                        >
                            <UiIcon name="chevronRight" :size="15" />
                        </button>
                    </div>
                    <button
                        type="button"
                        class="flex size-7 items-center justify-center rounded-full text-ink-muted transition-colors hover:bg-danger-soft hover:text-danger"
                        :aria-label="`Remover foto ${i + 1}`"
                        title="Remover"
                        @click="remover(foto, i)"
                    >
                        <UiIcon name="trash" :size="15" />
                    </button>
                </div>
            </li>

            <!-- Adicionar: o mesmo quadrado das fotos, tracejado. -->
            <li v-if="cabem > 0">
                <button
                    type="button"
                    class="flex aspect-square w-full flex-col items-center justify-center gap-1.5 rounded-control border border-dashed border-line-strong text-ink-muted transition-colors hover:border-brand-400 hover:bg-brand-50/40 hover:text-brand-700 disabled:cursor-wait"
                    :disabled="enviando"
                    @click="entrada?.click()"
                >
                    <UiIcon :name="enviando ? 'refresh' : 'plus'" :size="20" :class="enviando && 'animate-spin'" />
                    <span class="text-xs font-medium">
                        {{ enviando ? (progresso !== null ? `${progresso}%` : 'Enviando…') : 'Adicionar' }}
                    </span>
                </button>
                <input
                    ref="entrada"
                    type="file"
                    class="sr-only"
                    accept=".jpg,.jpeg,.png,.webp"
                    multiple
                    tabindex="-1"
                    aria-hidden="true"
                    @change="escolher"
                />
            </li>
        </ul>

        <p v-if="!fotos.length && galeria.imagem_omie" class="mt-3 text-xs text-ink-muted">
            Enquanto não houver foto aqui, vale a imagem que veio da Omie.
        </p>
        <p class="mt-3 text-xs text-ink-subtle">JPG, PNG ou WEBP, até 4 MB cada, a partir de 200×200 pixels.</p>
    </div>
</template>
