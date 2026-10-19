<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import ProductGalleryManager from '../../Components/ProductGalleryManager.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiBadge from '../../Components/UiBadge.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCard from '../../Components/UiCard.vue';
import UiDescriptionList from '../../Components/UiDescriptionList.vue';
import UiField from '../../Components/UiField.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiInput from '../../Components/UiInput.vue';
import UiTextarea from '../../Components/UiTextarea.vue';
import { useConfirm } from '../../composables/useConfirm';
import { formatDate } from '../../lib/format';

const props = defineProps({
    product: { type: Object, required: true },
    sugestoes: { type: Object, default: () => ({ product_types: [], departments: [] }) },
    canEdit: { type: Boolean, default: false },
});

const quantidade = new Intl.NumberFormat('pt-BR');

/*
 * O que vem da Omie é só leitura. O espelho reescreve esses campos a cada
 * importação: deixá-los editáveis aqui seria prometer uma mudança que some
 * sozinha no próximo sync.
 */
const daOmie = computed(() => [
    { key: 'code', label: 'Código', value: props.product.omie_code, mono: true },
    { key: 'name', label: 'Nome', value: props.product.name },
    { key: 'type', label: 'Tipo', value: props.product.is_kit ? 'Kit' : 'Produto' },
    { key: 'stock', label: 'Estoque', value: quantidade.format(props.product.stock_qty) },
    { key: 'active', label: 'Situação', value: props.product.active ? 'Ativo' : 'Inativo' },
    {
        key: 'synced',
        label: 'Sincronizado',
        value: props.product.stock_synced_at ? formatDate(props.product.stock_synced_at) : 'Nunca',
    },
]);

// --- Descrição ---
const descricaoForm = useForm({ description: props.product.description ?? '' });

function salvarDescricao() {
    descricaoForm.put(route('products.update', props.product.id), { preserveScroll: true });
}

const confirm = useConfirm();

// --- Reconhece ---
/*
 * As duas categorias são exigidas pela Reconhece e não existem na Omie. Ficam
 * salvas no produto, publicado ou não: o formulário abre com o que foi
 * gravado, e "Salvar" guarda sem enviar nada.
 */
const reconheceForm = useForm({
    product_type: props.product.categorias?.product_type ?? '',
    department: props.product.categorias?.department ?? '',
});

const temDescricao = computed(() => Boolean(props.product.description));

function salvarCategorias() {
    reconheceForm.put(route('products.reconhece.categories', props.product.id), {
        preserveScroll: true,
        // Depois de salvo, o que está no campo passa a ser o "original": o
        // botão volta a desabilitar até a próxima mudança.
        onSuccess: () => reconheceForm.defaults(),
    });
}

function publicar() {
    reconheceForm.post(route('products.reconhece.publish', props.product.id), {
        preserveScroll: true,
        onSuccess: () => reconheceForm.defaults(),
    });
}

// --- Exclusão ---
async function excluir() {
    await confirm({
        title: `Excluir "${props.product.name}"?`,
        description: 'O produto sai da WeLog.',
        warning: 'Se ele continuar ativo na Omie, volta na próxima importação.',
        confirmLabel: 'Excluir produto',
        variant: 'danger',
        errorBag: 'product',
        action: () => router.delete(route('products.destroy', props.product.id)),
    });
}
</script>

<template>
    <Head :title="`Editar · ${product.name}`" />

    <PageHeader :title="product.name" :subtitle="`Código ${product.omie_code}`">
        <template #actions>
            <Link :href="route('products.index')">
                <UiButton variant="ghost" icon="chevron-left">Produtos</UiButton>
            </Link>

            <!-- Produto em uso não tem o botão: o motivo aparece embaixo, em vez
                 de um diálogo que só serviria para dizer "não dá". -->
            <UiButton v-if="canEdit && !product.em_uso" variant="danger" icon="trash" @click="excluir">
                Excluir
            </UiButton>
        </template>
    </PageHeader>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <!-- Descrição: o único texto editável, porque a Omie quase nunca a
                 tem e a Reconhece a exige. -->
            <UiCard title="Descrição">
                <form class="space-y-4" @submit.prevent="salvarDescricao">
                    <UiField
                        label="Descrição do produto"
                        :error="descricaoForm.errors.description"
                        :hint="product.description_edited
                            ? 'Editada na WeLog. A importação da Omie não sobrescreve. Apague o texto para voltar a usar a da Omie.'
                            : 'Obrigatória para enviar à Reconhece. Depois de salva aqui, a importação da Omie não a sobrescreve.'"
                    >
                        <UiTextarea
                            v-model="descricaoForm.description"
                            :rows="5"
                            :maxlength="5000"
                            :disabled="!canEdit"
                            autogrow
                            counter
                        />
                    </UiField>

                    <div v-if="canEdit" class="flex justify-end">
                        <UiButton type="submit" :loading="descricaoForm.processing" :disabled="!descricaoForm.isDirty">
                            Salvar descrição
                        </UiButton>
                    </div>
                </form>
            </UiCard>

            <!-- Reconhece -->
            <UiCard title="Reconhece">
                <template #header>
                    <UiBadge v-if="product.reconhece" variant="success">Na Reconhece</UiBadge>
                    <UiBadge v-else variant="neutral">Não enviado</UiBadge>
                </template>

                <UiAlert v-if="!temDescricao" variant="warning" class="mb-4">
                    A Reconhece exige descrição. Salve uma acima antes de enviar.
                </UiAlert>

                <form class="space-y-4" @submit.prevent="publicar">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiField label="Categoria (nível 1)" :error="reconheceForm.errors.product_type" required>
                            <UiInput
                                v-model="reconheceForm.product_type"
                                list="sugestoes-categoria"
                                autocomplete="off"
                                placeholder="Casa e Construção"
                                :invalid="Boolean(reconheceForm.errors.product_type)"
                                :disabled="!canEdit"
                            />
                        </UiField>

                        <UiField label="Departamento (nível 2)" :error="reconheceForm.errors.department" required>
                            <UiInput
                                v-model="reconheceForm.department"
                                list="sugestoes-departamento"
                                autocomplete="off"
                                placeholder="Ferramentas"
                                :invalid="Boolean(reconheceForm.errors.department)"
                                :disabled="!canEdit"
                            />
                        </UiField>
                    </div>

                    <!-- Sugestões do que já foi usado em outros produtos: a
                         Reconhece recusa nome que não bate exatamente, e
                         redigitar é como nasce a variação que ela não aceita. -->
                    <datalist id="sugestoes-categoria">
                        <option v-for="c in sugestoes.product_types" :key="c" :value="c" />
                    </datalist>
                    <datalist id="sugestoes-departamento">
                        <option v-for="d in sugestoes.departments" :key="d" :value="d" />
                    </datalist>

                    <p class="text-sm text-ink-muted">
                        Use exatamente os nomes que a Reconhece cadastrou, senão ela recusa o envio.
                    </p>

                    <div v-if="canEdit" class="flex flex-wrap justify-end gap-2">
                        <UiButton
                            variant="secondary"
                            :loading="reconheceForm.processing"
                            :disabled="!reconheceForm.isDirty"
                            @click="salvarCategorias"
                        >
                            Salvar
                        </UiButton>
                        <UiButton type="submit" :loading="reconheceForm.processing" :disabled="!temDescricao">
                            {{ product.reconhece ? 'Atualizar na Reconhece' : 'Enviar para Reconhece' }}
                        </UiButton>
                    </div>
                </form>
            </UiCard>
        </div>

        <div class="space-y-6">
            <!-- Fotos: a galeria do item principal. Tamanho de grade não tem a
                 sua; a tela aponta onde ela mora. -->
            <UiCard title="Fotos">
                <template v-if="product.galeria">
                    <ProductGalleryManager v-if="canEdit" :galeria="product.galeria" :show-title="false" />

                    <template v-else>
                        <ul v-if="product.galeria.fotos.length" class="grid grid-cols-3 gap-3">
                            <li
                                v-for="foto in product.galeria.fotos"
                                :key="foto.id"
                                class="flex aspect-square items-center justify-center overflow-hidden rounded-control border border-line"
                            >
                                <img :src="foto.url" alt="" class="max-h-full max-w-full object-contain" />
                            </li>
                        </ul>
                        <p v-else class="text-sm text-ink-muted">Sem foto.</p>
                    </template>
                </template>

                <p v-else class="text-sm text-ink-muted">
                    Este é um tamanho de
                    <Link
                        v-if="product.item_principal.id"
                        :href="route('products.edit', product.item_principal.id)"
                        class="font-medium text-brand-700 hover:underline"
                    >{{ product.item_principal.name }}</Link>
                    <span v-else class="font-medium text-ink">{{ product.item_principal.name }}</span>.
                    As fotos ficam no item principal e valem para todos os tamanhos.
                </p>
            </UiCard>

            <!-- Opções de cor/tamanho: SKUs do parceiro com este código como
                 parent code. É exatamente o que o colaborador escolhe no portal. -->
            <UiCard title="Cor e tamanho no portal">
                <template v-if="product.opcoes?.length">
                    <p class="mb-3 text-sm text-ink-muted">
                        O colaborador escolhe entre {{ product.opcoes.length }} opções, vindas do catálogo da
                        Reconhece pelo parent code <span class="font-mono">{{ product.omie_code }}</span>.
                    </p>
                    <ul class="flex flex-wrap gap-1.5">
                        <li v-for="o in product.opcoes" :key="o.sku">
                            <UiBadge variant="info" :title="o.sku">
                                {{ [o.color, o.size].filter(Boolean).join(' · ') }}
                            </UiBadge>
                        </li>
                    </ul>
                </template>
                <p v-else class="text-sm text-ink-muted">
                    Sem opções: nenhum SKU do catálogo da Reconhece tem
                    <span class="font-mono">{{ product.omie_code }}</span> como parent code. O colaborador resgata o
                    produto como está.
                </p>
            </UiCard>

            <!-- Dados da Omie -->
            <UiCard title="Dados da Omie">
                <UiDescriptionList :items="daOmie" layout="stacked" />

                <p class="mt-4 flex gap-2 text-sm text-ink-muted">
                    <UiIcon name="info" :size="16" class="mt-0.5 shrink-0" />
                    Vêm da Omie e são reescritos a cada importação. Para mudar, altere lá.
                </p>

                <p v-if="product.em_uso" class="mt-3 flex gap-2 text-sm text-ink-muted">
                    <UiIcon name="info" :size="16" class="mt-0.5 shrink-0" />
                    Não pode ser excluído: {{ product.em_uso }}.
                </p>
            </UiCard>
        </div>
    </div>
</template>
