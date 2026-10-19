<script setup>
import { computed } from 'vue';
import UiIcon from './UiIcon.vue';

/**
 * O andamento de uma importação de Colaboradores: a barra, o que entrou, o que
 * já estava no Projeto e o que foi recusado, com a planilha das recusadas.
 *
 * Usado no painel da aba e no pop-up de acompanhamento, para os dois contarem
 * a mesma história com os mesmos números (`EntitlementImport::resumo`).
 */
const props = defineProps({
    importacao: { type: Object, required: true },
});

const fmt = (n) => Number(n ?? 0).toLocaleString('pt-BR');

const cadastrados = computed(() => props.importacao.created + props.importacao.admins);

/** As mensagens guardadas param nas 50 primeiras; o total vem de `failed`. */
const errosOmitidos = computed(() => Math.max(0, props.importacao.failed - Math.min(props.importacao.errors.length, 10)));
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-ink">
                    <template v-if="importacao.running">Importando {{ importacao.filename }}</template>
                    <template v-else>Importação concluída · {{ importacao.filename }}</template>
                </p>
                <p class="mt-0.5 text-sm text-ink-muted tabular-nums">
                    {{ fmt(importacao.processed) }} de {{ fmt(importacao.total) }} linhas
                </p>
            </div>

            <span v-if="importacao.running" class="text-sm font-semibold text-ink tabular-nums">
                {{ importacao.percent }}%
            </span>
        </div>

        <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-line">
            <div
                class="h-full rounded-full bg-trail transition-[width] duration-500"
                :style="{ width: `${importacao.percent}%` }"
            />
        </div>

        <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div class="rounded-control bg-surface-muted px-2 py-2">
                <dt class="text-xs text-ink-muted">Cadastrados</dt>
                <dd class="text-base font-semibold text-ink tabular-nums">{{ fmt(cadastrados) }}</dd>
            </div>
            <div class="rounded-control bg-surface-muted px-2 py-2">
                <dt class="text-xs text-ink-muted">Já no projeto</dt>
                <dd class="text-base font-semibold text-ink tabular-nums">{{ fmt(importacao.ignored) }}</dd>
            </div>
            <div class="rounded-control px-2 py-2" :class="importacao.failed ? 'bg-warning-soft' : 'bg-surface-muted'">
                <dt class="text-xs" :class="importacao.failed ? 'text-warning' : 'text-ink-muted'">Recusadas</dt>
                <dd class="text-base font-semibold tabular-nums" :class="importacao.failed ? 'text-warning' : 'text-ink'">
                    {{ fmt(importacao.failed) }}
                </dd>
            </div>
        </dl>

        <p v-if="importacao.running" class="mt-3 text-sm text-ink-muted">
            Pode fechar esta janela e continuar usando a plataforma. Avisamos quando terminar, em qualquer tela.
        </p>

        <!-- As recusadas ficam aqui, e não numa mensagem de sessão: do jeito
             antigo, o primeiro F5 apagava o relatório. -->
        <div v-if="importacao.errors.length" class="mt-3 rounded-control border border-warning-line bg-warning-soft/40 p-3">
            <ul class="space-y-0.5 text-sm text-ink">
                <li v-for="(erro, i) in importacao.errors.slice(0, 10)" :key="i">
                    Linha {{ erro.row }}: {{ erro.message }}
                </li>
            </ul>
            <p v-if="errosOmitidos" class="mt-1 text-sm text-ink-muted">
                E mais {{ fmt(errosOmitidos) }} na planilha das recusadas.
            </p>
        </div>

        <div v-if="importacao.failures_url" class="mt-3 flex flex-wrap items-center gap-3">
            <!-- <a>, não <Link>: é download de arquivo, não visita do Inertia. -->
            <a
                :href="importacao.failures_url"
                class="inline-flex h-control-sm items-center gap-1.5 rounded-full bg-brand-600 px-3 text-sm font-medium text-ink-inverse hover:bg-brand-700"
            >
                <UiIcon name="download" :size="16" />
                Baixar recusadas (.xlsx)
            </a>
            <p class="text-xs text-ink-muted">
                Só as linhas recusadas, no formato do modelo e com o motivo. Corrija e envie de novo.
            </p>
        </div>
    </div>
</template>
