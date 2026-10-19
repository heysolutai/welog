<script setup>
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import UiBadge from './UiBadge.vue';
import UiEmpty from './UiEmpty.vue';
import UiIcon from './UiIcon.vue';
import UiSkeleton from './UiSkeleton.vue';
import { formatDocument } from '../lib/format';

/**
 * Consulta em árvore: Cliente → Admin Cliente → Projetos → Admin Projeto →
 * Colaboradores.
 *
 * Existe porque a pergunta que chega ao suporte nunca é "me liste as
 * empresas": é "quem cuida deste cliente", "este Projeto tem admin?", "esta
 * pessoa está cadastrada?". Uma tabela plana responde a primeira e obriga a
 * abrir cinco telas para as outras.
 *
 * Os dois níveis mais fundos são buscados ao ABRIR, não junto da lista: um
 * Projeto pode ter centenas de colaboradores, e carregar todos de todos os
 * clientes para mostrar um seria pagar o custo inteiro em toda visita.
 */
const props = defineProps({
    companies: { type: Array, default: () => [] },
    emptyMessage: { type: String, default: 'Nenhum cliente.' },
    /**
     * Resultado da busca por pessoa (e-mail ou nome), vindo da tela.
     *
     * Traz o CAMINHO de cada uma: empresa e, quando houver, os Projetos. A
     * árvore usa para abrir já no lugar certo — achar alguém e ainda ter de
     * clicar três níveis para chegar nela é o mesmo que não ter achado.
     */
    peopleHits: { type: Object, default: null },
});

// Cache por id: reabrir um nível já visitado não volta ao servidor.
const openCompanies = ref(new Set());
const openProjects = ref(new Set());
const projectsOf = ref({});
const peopleOf = ref({});
const loading = ref(new Set());

/*
 * Uma função por nível, em vez de uma genérica que recebe o ref.
 *
 * No TEMPLATE o Vue desembrulha refs: `isOpen(openCompanies, id)` entregava o
 * Set puro, a função fazia `set.value.has(...)` sobre `undefined` e a árvore
 * inteira morria na renderização — tela vazia, sem nem o estado vazio. No
 * script o ref chega inteiro, e é por isso que `toggleSet` abaixo continua
 * recebendo o ref sem problema.
 */
const isCompanyOpen = (id) => openCompanies.value.has(id);
const isProjectOpen = (id) => openProjects.value.has(id);

function toggleSet(set, id) {
    const copia = new Set(set.value);
    copia.has(id) ? copia.delete(id) : copia.add(id);
    set.value = copia;

    return copia.has(id);
}

function marcarCarregando(chave, ligado) {
    const copia = new Set(loading.value);
    ligado ? copia.add(chave) : copia.delete(chave);
    loading.value = copia;
}

async function buscar(url, chave) {
    marcarCarregando(chave, true);

    try {
        const resposta = await fetch(url, { headers: { Accept: 'application/json' } });

        return resposta.ok ? await resposta.json() : null;
    } catch {
        // Rede caiu no meio: o nível fica fechado e a pessoa tenta de novo.
        return null;
    } finally {
        marcarCarregando(chave, false);
    }
}

async function toggleCompany(company) {
    const abriu = toggleSet(openCompanies, company.id);

    if (!abriu || projectsOf.value[company.id]) return;

    const dados = await buscar(route('admin.companies.projects', company.id), `c${company.id}`);
    if (dados) projectsOf.value = { ...projectsOf.value, [company.id]: dados };
}

async function toggleProject(project) {
    const abriu = toggleSet(openProjects, project.id);

    if (!abriu || peopleOf.value[project.id]) return;

    const dados = await buscar(route('admin.projects.people', project.id), `p${project.id}`);
    if (dados) peopleOf.value = { ...peopleOf.value, [project.id]: dados };
}

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/*
 * Busca com resultado abre o caminho sozinha.
 *
 * `immediate` porque a busca pode já vir pronta quando a árvore aparece (ao
 * trocar de Tabela para Árvore com o termo digitado). Os níveis abertos aqui
 * disparam a mesma carga sob demanda de um clique — não há caminho especial.
 */
watch(() => props.peopleHits, async (hits) => {
    if (!hits?.company_ids?.length) return;

    const empresas = new Set(openCompanies.value);
    hits.company_ids.forEach((id) => empresas.add(id));
    openCompanies.value = empresas;

    // Os Projetos de cada empresa precisam estar carregados antes de abrir o
    // nível de baixo, senão o `v-for` não tem o que expandir.
    await Promise.all(hits.company_ids.map(async (id) => {
        if (projectsOf.value[id]) return;

        const dados = await buscar(route('admin.companies.projects', id), `c${id}`);
        if (dados) projectsOf.value = { ...projectsOf.value, [id]: dados };
    }));

    const projetos = new Set(openProjects.value);
    (hits.project_ids ?? []).forEach((id) => projetos.add(id));
    openProjects.value = projetos;

    await Promise.all((hits.project_ids ?? []).map(async (id) => {
        if (peopleOf.value[id]) return;

        const dados = await buscar(route('admin.projects.people', id), `p${id}`);
        if (dados) peopleOf.value = { ...peopleOf.value, [id]: dados };
    }));
}, { immediate: true });
</script>

<template>
    <div class="overflow-hidden rounded-card bg-surface shadow-card">
        <UiEmpty v-if="!companies.length" icon="companies" :message="emptyMessage" class="p-6" />

        <ul v-else class="divide-y divide-line">
            <li v-for="company in companies" :key="company.id">
                <!--
                    Nível 1 — Cliente.

                    O "Ver detalhes" é IRMÃO do botão, não filho: abrir a
                    empresa e expandir a árvore são dois destinos diferentes, e
                    um link dentro de um `<button>` é HTML inválido — o
                    navegador desfaz o aninhamento e o clique vira loteria.
                -->
                <div class="flex items-center transition-colors hover:bg-surface-muted">
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-3 px-4 py-3.5 text-left"
                        :aria-expanded="isCompanyOpen(company.id)"
                        @click="toggleCompany(company)"
                    >
                        <UiIcon
                            name="chevronRight"
                            :size="16"
                            aria-hidden="true"
                            :class="[
                                'shrink-0 text-ink-muted transition-transform motion-reduce:transition-none',
                                isCompanyOpen(company.id) && 'rotate-90',
                            ]"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-ink">{{ company.label || company.name }}</span>
                            <span class="block truncate text-xs text-ink-muted tabular-nums">
                                {{ formatDocument(company.cnpj) }}
                            </span>
                        </span>

                        <UiBadge v-if="!company.admins.length" variant="danger">Sem admin</UiBadge>
                        <span class="hidden shrink-0 text-xs text-ink-muted sm:block">
                            {{ plural(company.projects_count, 'projeto', 'projetos') }}
                        </span>
                    </button>

                    <!-- Rótulo escrito, não só o ícone: um olho sozinho numa
                         linha que também expande não diz qual das duas coisas
                         faz. Em telas estreitas fica só o ícone, e aí o
                         `aria-label` carrega o sentido. -->
                    <Link
                        :href="route('admin.companies.show', company.id)"
                        class="mr-2 flex shrink-0 items-center gap-1.5 rounded-control px-2.5 py-1.5 text-xs font-medium text-ink-muted transition-colors hover:bg-surface hover:text-brand-700"
                        :aria-label="`Ver detalhes de ${company.label || company.name}`"
                    >
                        <UiIcon name="eye" :size="15" aria-hidden="true" />
                        <span class="hidden sm:inline">Ver detalhes</span>
                    </Link>
                </div>

                <div v-if="isCompanyOpen(company.id)" class="bg-surface-muted/40 pb-2">
                    <!-- Nível 2 — Admin Cliente -->
                    <p class="px-4 pt-1 pb-1.5 pl-11 text-xs font-semibold text-ink-muted">Admin Cliente</p>

                    <p v-if="!company.admins.length" class="px-4 pb-2 pl-11 text-sm text-danger">
                        Nenhum. Ninguém desta empresa consegue cadastrar Colaborador.
                    </p>

                    <ul v-else class="pl-11">
                        <li
                            v-for="admin in company.admins"
                            :key="admin.id"
                            class="flex items-center gap-2 px-4 py-1.5 text-sm"
                        >
                            <span class="min-w-0 flex-1 truncate text-ink">
                                {{ admin.name }}
                                <span class="text-ink-muted">· {{ admin.email }}</span>
                            </span>
                            <UiBadge v-if="admin.access_pending" variant="warning">Convite pendente</UiBadge>
                        </li>
                    </ul>

                    <!-- Nível 3 — Projetos -->
                    <p class="px-4 pt-2 pb-1.5 pl-11 text-xs font-semibold text-ink-muted">Projetos</p>

                    <UiSkeleton v-if="loading.has(`c${company.id}`)" :lines="2" class="mx-4 ml-11" />

                    <p
                        v-else-if="!(projectsOf[company.id] ?? []).length"
                        class="px-4 pb-2 pl-11 text-sm text-ink-muted"
                    >
                        Nenhum Projeto ainda.
                    </p>

                    <ul v-else class="pl-11">
                        <li v-for="project in projectsOf[company.id]" :key="project.id">
                            <div class="flex items-center gap-2 px-4 py-1.5">
                                <button
                                    type="button"
                                    class="flex min-w-0 flex-1 items-center gap-2 text-left"
                                    :aria-expanded="isProjectOpen(project.id)"
                                    :aria-label="`Pessoas de ${project.name}`"
                                    @click="toggleProject(project)"
                                >
                                    <UiIcon
                                        name="chevronRight"
                                        :size="14"
                                        aria-hidden="true"
                                        :class="[
                                            'shrink-0 text-ink-muted transition-transform motion-reduce:transition-none',
                                            isProjectOpen(project.id) && 'rotate-90',
                                        ]"
                                    />
                                    <span class="truncate text-sm text-ink-muted">
                                        {{ project.stage }}
                                        <span v-if="project.collaborators_count">
                                            · {{ plural(project.collaborators_count, 'colaborador', 'colaboradores') }}
                                        </span>
                                    </span>
                                </button>

                                <!-- O nome do Projeto é SEMPRE link: daqui se
                                     vai para a tela dele, com kits, pessoas e
                                     resgates. É o destino que a árvore serve. -->
                                <Link
                                    :href="project.url"
                                    class="order-first min-w-0 shrink-0 truncate text-sm font-medium text-ink hover:text-brand-700 hover:underline"
                                >
                                    {{ project.name }}
                                </Link>
                            </div>

                            <!-- Níveis 4 e 5 — Admin Projeto e Colaboradores -->
                            <div v-if="isProjectOpen(project.id)" class="pb-2 pl-6">
                                <UiSkeleton v-if="loading.has(`p${project.id}`)" :lines="2" class="mx-4" />

                                <template v-else-if="peopleOf[project.id]">
                                    <p class="px-4 pt-1 pb-1 text-xs font-semibold text-ink-muted">Admin Projeto</p>

                                    <p
                                        v-if="!peopleOf[project.id].admins.length"
                                        class="px-4 pb-1 text-sm text-ink-muted"
                                    >
                                        Nenhum.
                                    </p>
                                    <ul v-else>
                                        <li
                                            v-for="admin in peopleOf[project.id].admins"
                                            :key="admin.id"
                                            class="flex items-center gap-2 px-4 py-1 text-sm"
                                        >
                                            <span class="min-w-0 flex-1 truncate text-ink">
                                                {{ admin.name }}
                                                <span class="text-ink-muted">· {{ admin.email }}</span>
                                            </span>
                                            <UiBadge v-if="admin.access_pending" variant="warning">
                                                Convite pendente
                                            </UiBadge>
                                        </li>
                                    </ul>

                                    <p class="px-4 pt-2 pb-1 text-xs font-semibold text-ink-muted">Colaboradores</p>

                                    <p
                                        v-if="!peopleOf[project.id].collaborators.length"
                                        class="px-4 text-sm text-ink-muted"
                                    >
                                        Nenhum cadastrado.
                                    </p>
                                    <ul v-else>
                                        <li
                                            v-for="pessoa in peopleOf[project.id].collaborators"
                                            :key="pessoa.id"
                                            class="flex items-center gap-2 px-4 py-1 text-sm"
                                        >
                                            <span class="min-w-0 flex-1 truncate text-ink">
                                                {{ pessoa.name }}
                                                <span class="text-ink-muted">· {{ pessoa.email }}</span>
                                            </span>
                                            <UiBadge v-if="pessoa.access_pending" variant="warning">
                                                Convite pendente
                                            </UiBadge>
                                        </li>
                                    </ul>
                                </template>
                            </div>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </div>
</template>
