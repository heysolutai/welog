<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
import { pushToast } from '../composables/useToast';

/**
 * O aviso flutuante da importação de Colaboradores.
 *
 * Uma planilha de nove mil linhas leva de meia hora a uma hora na fila, e
 * ninguém fica olhando a aba do Projeto esse tempo todo. Este cartão segue a
 * pessoa em qualquer tela, mostra o andamento e avisa quando termina: um
 * toast na tela e, com a aba em segundo plano, uma notificação do navegador.
 *
 * Só acompanha as planilhas que a própria pessoa subiu (prop `importacoes`,
 * do HandleInertiaRequests) e consulta o servidor apenas enquanto alguma
 * corre — parada, não gasta requisição.
 */
const page = usePage();
const importacoes = ref([...(page.props.importacoes ?? [])]);

// Por navegador: o que a pessoa fechou e o que já foi avisado (para o aviso
// de fim não se repetir a cada tela nem em outra aba).
const CHAVE_FECHADAS = 'welog:importacoes:fechadas';
const CHAVE_AVISADAS = 'welog:importacoes:avisadas';

function lerIds(chave) {
    try {
        return new Set(JSON.parse(localStorage.getItem(chave) ?? '[]'));
    } catch {
        return new Set();
    }
}

function gravarIds(chave, ids) {
    try {
        localStorage.setItem(chave, JSON.stringify([...ids].slice(-50)));
    } catch {
        // Navegação privada ou armazenamento bloqueado: o aviso só não lembra.
    }
}

const fechadas = ref(lerIds(CHAVE_FECHADAS));

function plural(n, um, varios) {
    return `${n.toLocaleString('pt-BR')} ${n === 1 ? um : varios}`;
}

function resumoDoFim(imp) {
    const partes = [plural(imp.created + imp.admins, 'cadastrado', 'cadastrados')];
    if (imp.ignored) partes.push(`${imp.ignored.toLocaleString('pt-BR')} já no projeto`);
    if (imp.failed) partes.push(plural(imp.failed, 'recusada', 'recusadas'));

    return partes.join(' · ');
}

function avisarFim(imp) {
    const avisadas = lerIds(CHAVE_AVISADAS);
    if (avisadas.has(imp.id)) return;

    avisadas.add(imp.id);
    gravarIds(CHAVE_AVISADAS, avisadas);

    const texto = `Planilha "${imp.filename}" importada: ${resumoDoFim(imp)}.`;
    pushToast(imp.failed ? 'warning' : 'success', texto);

    // Com a aba à vista o toast basta; em segundo plano, o navegador avisa.
    if ('Notification' in window && Notification.permission === 'granted' && document.visibilityState !== 'visible') {
        try {
            new Notification('Planilha importada', { body: `${imp.project_name}: ${resumoDoFim(imp)}.`, tag: `importacao-${imp.id}` });
        } catch {
            // Alguns navegadores só notificam por service worker: fica o toast.
        }
    }
}

function atualizar(novas) {
    importacoes.value = novas;
    novas.filter((imp) => !imp.running).forEach(avisarFim);
}

// A cada navegação o servidor manda o estado atual.
watch(() => page.props.importacoes, (novas) => novas && atualizar(novas), { immediate: true });

const correndo = computed(() => importacoes.value.some((imp) => imp.running));

let timer = null;

async function consultar() {
    try {
        const resposta = await fetch(route('imports.track'), { headers: { Accept: 'application/json' } });
        if (resposta.ok) atualizar((await resposta.json()).importacoes ?? []);
    } catch {
        // Sem rede agora: tenta de novo na próxima volta.
    }
}

watch(
    correndo,
    (sim) => {
        clearInterval(timer);
        timer = sim ? setInterval(consultar, 4000) : null;
    },
    { immediate: true },
);

onBeforeUnmount(() => clearInterval(timer));

/** A tela do próprio Projeto já mostra o painel completo: aqui não repete. */
const visiveis = computed(() =>
    importacoes.value.filter(
        (imp) =>
            !fechadas.value.has(imp.id) &&
            !(page.component === 'Projects/Show' && page.props.project?.id === imp.project_id),
    ),
);

function fechar(imp) {
    const ids = new Set(fechadas.value);
    ids.add(imp.id);
    fechadas.value = ids;
    gravarIds(CHAVE_FECHADAS, ids);
}
</script>

<template>
    <div
        v-if="visiveis.length"
        class="fixed bottom-4 left-4 z-50 flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2"
        aria-live="polite"
    >
        <section
            v-for="imp in visiveis"
            :key="imp.id"
            class="rounded-card border border-line bg-surface p-4 shadow-lg"
            :aria-label="`Importação da planilha ${imp.filename}`"
        >
            <div class="flex items-start gap-3">
                <span
                    class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                    :class="imp.running ? 'bg-surface-muted text-ink-muted' : imp.failed ? 'bg-warning-soft text-warning' : 'bg-success-soft text-success'"
                    aria-hidden="true"
                >
                    <UiIcon :name="imp.running ? 'upload' : imp.failed ? 'alert' : 'check'" :size="16" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-ink">
                        {{ imp.running ? 'Importando planilha' : 'Planilha importada' }}
                    </p>
                    <p class="truncate text-xs text-ink-muted" :title="`${imp.filename} · ${imp.project_name}`">
                        {{ imp.filename }} · {{ imp.project_name }}
                    </p>
                </div>

                <button
                    v-if="!imp.running"
                    type="button"
                    class="-mt-1 -mr-1 rounded-full p-1 text-ink-muted hover:bg-surface-muted hover:text-ink"
                    aria-label="Fechar aviso"
                    @click="fechar(imp)"
                >
                    <UiIcon name="close" :size="16" />
                </button>
            </div>

            <template v-if="imp.running">
                <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-line">
                    <div
                        class="h-full rounded-full bg-trail transition-[width] duration-500"
                        :style="{ width: `${imp.percent}%` }"
                    />
                </div>
                <p class="mt-1.5 flex justify-between text-xs text-ink-muted tabular-nums">
                    <span>{{ imp.processed.toLocaleString('pt-BR') }} de {{ imp.total.toLocaleString('pt-BR') }} linhas</span>
                    <span class="font-semibold text-ink">{{ imp.percent }}%</span>
                </p>
                <p class="mt-2 text-xs text-ink-muted">
                    Pode continuar usando a plataforma. Avisamos aqui quando terminar.
                </p>
            </template>

            <p v-else class="mt-2 text-sm text-ink tabular-nums">{{ resumoDoFim(imp) }}</p>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <!-- <a>, não <Link>: é download de arquivo, não visita do Inertia. -->
                <a
                    v-if="!imp.running && imp.failures_url"
                    :href="imp.failures_url"
                    class="inline-flex h-control-sm items-center gap-1.5 rounded-full bg-brand-600 px-3 text-sm font-medium text-ink-inverse hover:bg-brand-700"
                >
                    <UiIcon name="download" :size="16" />
                    Baixar recusadas
                </a>
                <Link
                    :href="imp.project_url"
                    class="inline-flex h-control-sm items-center gap-1.5 rounded-full border border-line px-3 text-sm font-medium text-ink hover:bg-surface-muted"
                >
                    Abrir projeto
                </Link>
            </div>
        </section>
    </div>
</template>
