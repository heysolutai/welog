<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ImportWatcher from '../Components/ImportWatcher.vue';
import NotificationsBell from '../Components/NotificationsBell.vue';
import SupportLinks from '../Components/SupportLinks.vue';
import Toaster from '../Components/Toaster.vue';
import UiBadge from '../Components/UiBadge.vue';
import UiButton from '../Components/UiButton.vue';
import UiConfirm from '../Components/UiConfirm.vue';
import UiDrawer from '../Components/UiDrawer.vue';
import UiIcon from '../Components/UiIcon.vue';
import UiMenu from '../Components/UiMenu.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);

/**
 * Fonte de verdade única da navegação: a sidebar do desktop e o drawer do
 * celular renderizam este mesmo array (§8.2). Item de menu é decidido por
 * PAPEL; o que cada perfil pode FAZER continua vindo das permissões que o
 * backend manda tela a tela (P7).
 */
/** Papel legível — o `role` do backend é chave técnica (`admin_wegift`). */
const ROLE_LABELS = {
    admin_wegift: 'Admin WeGift',
    wegift_n1: 'Operação (Nível 1)',
    wegift_n2: 'Consulta (Nível 2)',
    admin_client: 'Admin Cliente',
    project_admin: 'Admin Projeto',
    end_customer: 'Colaborador',
};

const roleLabel = computed(() => ROLE_LABELS[user.value?.role] ?? '');

/**
 * Admin do lado do cliente (geral OU de Projeto): os dois usam o shell mínimo.
 * O Admin Projeto caía no shell da equipe WeGift e ganhava uma sidebar
 * inteira de destinos que devolvem 403 para ele.
 */
const clientShell = computed(
    () => user.value?.is_client_admin === true || user.value?.is_project_admin === true,
);

/**
 * Há canal de contato configurado? Gate dos blocos "Fale com a WeGift": sem
 * ele, o wrapper (borda/margem) sobraria desenhado em volta de nada.
 */
const hasSupport = computed(() => Boolean(page.props.support?.whatsapp_link || page.props.support?.email));

/** Itens parados esperando ação — alimenta o sino (ver HandleInertiaRequests). */
const pendingCount = computed(() => page.props.pendingCount ?? 0);

/** Os Projetos por trás do número — o painel do sino lista e linka cada um. */
const pendingProjects = computed(() => page.props.pendingProjects ?? []);

const searchTerm = ref('');

/**
 * A busca leva para a lista de projetos com o termo, que o filtro `q` do
 * ProjectController@index aplica (nome, pedido, cliente ou kit).
 */
function submitSearch() {
    const termo = searchTerm.value.trim();

    router.get(route('projects.index'), termo ? { q: termo } : {}, {
        preserveState: false,
        replace: true,
    });
}

const groups = computed(() => {
    const admin = user.value?.is_admin === true;

    return [
        {
            // O que se acompanha todo dia: o que está andando e o que travou.
            label: 'Operação',
            items: [
                { label: 'Visão geral', route: 'dashboard', match: 'dashboard', icon: 'dashboard' },
                { label: 'Projetos', route: 'projects.index', match: 'projects.', icon: 'projects' },
                { label: 'Resgates', route: 'redemptions.index', match: 'redemptions.', icon: 'redemptions' },
                /*
                 * Clientes saiu de "Cadastros" porque deixou de ser cadastro:
                 * a empresa nasce no fluxo de criar Projeto, junto com o admin
                 * dela. O que resta aqui é consulta — quem é o cliente, quem
                 * administra, o que está em falta —, e isso é trabalho de todo
                 * dia, ao lado de Projetos e Resgates.
                 */
                ...(admin
                    ? [{ label: 'Clientes', route: 'admin.companies.index', match: 'admin.companies.', icon: 'companies' }]
                    : []),
                // Consulta e exportação: rota `role:operator,admin_client` — o
                // N2 também entra. O Admin Cliente chega pelo shell dele.
                { label: 'Acompanhamento', route: 'reports.index', match: 'reports.', icon: 'reports' },
            ],
        },
        {
            /*
             * O que se mantém: o dado que alimenta a operação.
             *
             * O grupo NÃO pode ser inteiro condicional a `is_admin`, como era
             * quando só tinha Clientes e Benefícios. Produtos e Importações são
             * `role:operator` nas rotas — dentro de um bloco só de admin, o N1 e
             * o N2 perderiam dois destinos que eles têm permissão de abrir, e
             * ainda por cima em silêncio: a rota continua respondendo, só o
             * caminho para ela some.
             */
            label: 'Cadastros',
            items: [
                { label: 'Produtos', route: 'products.index', match: 'products.', icon: 'catalog' },
                // Destino próprio, e não uma aba dentro de Produtos: quem vem
                // aqui vem zerar pendência de vínculo antes de a campanha
                // rodar, e essa lista precisa ser alcançável sem procurar.
                { label: 'Catálogo do Parceiro', route: 'partner-skus.index', match: 'partner-skus.', icon: 'gift' },
                { label: 'Importações Parceiros', route: 'imports.index', match: 'imports.', icon: 'imports' },
                ...(admin
                    ? [
                          { label: 'Transportadoras', route: 'admin.carriers.index', match: 'admin.carriers.', icon: 'truck' },
                          { label: 'Benefícios Fiscais', route: 'benefits.index', match: 'benefits.', icon: 'benefits' },
                      ]
                    : []),
            ],
        },
    ];
});

/**
 * Configurações — vivem no menu de conta, não na navegação principal.
 *
 * São telas de manutenção do sistema: quem administra usuários ou revisa o log
 * de webhooks vai lá de propósito, algumas vezes por mês. Na coluna, ocupavam
 * um terço dos destinos e competiam com o trabalho diário (Projetos, Resgates).
 *
 * A lista é ÚNICA e alimenta os dois lugares — o menu "⋯" no desktop e o rodapé
 * do drawer no celular, que não tem menu de conta. Duplicar aqui significaria
 * um destino inalcançável no telefone assim que alguém esquecesse de mexer nos
 * dois.
 */
const settingsItems = computed(() =>
    user.value?.is_admin
        ? [
              /*
               * "Equipe WeGift", não "Usuários".
               *
               * O cliente pediu para chamar o cliente final de "Usuário" (era
               * "Colaborador"), e a troca foi aplicada na aplicação inteira —
               * caindo em cima desta tela, que já se chamava "Usuários" e trata
               * de gente completamente diferente: admin, N1 e N2 da WeGift.
               *
               * A mesma palavra passou a apontar para três conceitos. O pedido
               * do cliente fica intacto; quem muda é este item, que nunca fez
               * parte dele.
               */
              { label: 'Equipe WeGift', route: 'admin.users.index', match: 'admin.users.', icon: 'users' },
              { label: 'Modelos de E-mail', route: 'admin.email-templates.index', match: 'admin.email-templates.', icon: 'emails' },
              { label: 'Log de Webhooks', route: 'admin.webhooks.index', match: 'admin.webhooks.', icon: 'refresh' },
              { label: 'Auditoria', route: 'admin.audit.index', match: 'admin.audit.', icon: 'audit' },
          ]
        // Do lado do cliente, a única configuração é a voz das mensagens que
        // os Colaboradores dele recebem.
        : user.value?.can_edit_messages
            ? [{ label: 'Comunicações', route: 'company.templates.index', match: 'company.templates.', icon: 'emails' }]
            : [],
);

/**
 * O layout é persistente entre navegações e `route().current()` lê a URL do
 * navegador, que não é reativa: sem depender de `page.url`, o item ativo
 * congelaria no primeiro destino visitado.
 */
const current = computed(() => (page.url ? route().current() : null));

function isActive(match) {
    return current.value?.startsWith(match.replace(/\.$/, '')) ?? false;
}

const initials = computed(() => {
    const parts = (user.value?.name?.trim() ?? '').split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';

    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});

// Sem este selo o operador de consulta conclui que o sistema está quebrado (§9.8).
const readOnly = computed(() => Boolean(user.value?.is_operator && !user.value?.can_critical));

function logout() {
    router.post(route('logout'));
}

/**
 * `href` em vez de `onSelect`: item que navega tem de ser link de verdade —
 * clique do meio abre em nova aba, e o endereço aparece na barra de status.
 * "Sair" continua sendo ação, então segue botão.
 */
const accountItems = computed(() => [
    ...(settingsItems.value.length
        ? [
              { heading: 'Configurações' },
              ...settingsItems.value.map((item) => ({
                  label: item.label,
                  icon: item.icon,
                  href: route(item.route),
              })),
          ]
        : []),
    { label: 'Sair', icon: 'logout', onSelect: logout, separatorBefore: true },
]);

const menuOpen = ref(false);

// O drawer só existe abaixo de lg: chegando ao desktop, a sidebar reassume.
let desktop = null;

function closeOnDesktop(event) {
    if (event.matches) menuOpen.value = false;
}

onMounted(() => {
    desktop = window.matchMedia('(min-width: 1024px)');
    desktop.addEventListener('change', closeOnDesktop);
});

onUnmounted(() => desktop?.removeEventListener('change', closeOnDesktop));
</script>

<template>
    <div class="min-h-dvh bg-canvas">
        <a
            href="#conteudo"
            class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:border focus:border-line focus:bg-surface focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-ink focus:shadow-overlay"
        >
            Pular para o conteúdo
        </a>

        <!-- Admin do lado do cliente (geral ou de Projeto): shell mínimo (§8.3).
             Dois destinos — Projetos e Relatórios — cabem no próprio cabeçalho,
             sem sidebar. -->
        <template v-if="clientShell">
            <header class="sticky top-0 z-30 border-b border-line bg-surface/95 backdrop-blur">
                <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="flex shrink-0 items-center gap-2.5">
                            <span
                                aria-hidden="true"
                                class="flex size-9 items-center justify-center rounded-control bg-brand-600 text-sm font-bold text-ink-inverse"
                            >
                                W
                            </span>
                            <div class="leading-tight">
                                <span class="block text-base font-semibold tracking-tight text-ink">WeLog</span>
                                <span class="hidden text-[11px] text-ink-muted sm:block">por WeGift</span>
                            </div>

                            <!-- A marca do Cliente, ligada em Customizações:
                                 separada da WeLog por um fio, na mesma altura,
                                 sem moldura — é dele, não um ícone nosso. -->
                            <template v-if="user?.company_logo_url">
                                <span aria-hidden="true" class="mx-1.5 h-7 w-px bg-line" />
                                <img
                                    :src="user.company_logo_url"
                                    alt="Logo da empresa"
                                    class="h-9 w-auto max-w-32 object-contain"
                                />
                            </template>
                        </div>

                        <nav aria-label="Navegação principal" class="flex items-center gap-1">
                            <Link
                                v-for="item in [
                                    { label: 'Projetos', route: 'projects.index', match: 'projects.', icon: 'projects' },
                                    { label: 'Acompanhamento', route: 'reports.index', match: 'reports.', icon: 'reports' },
                                    // Só com os e-mails de resgate ligados no Cliente.
                                    ...(user?.can_edit_messages
                                        ? [{ label: 'Comunicações', route: 'company.templates.index', match: 'company.templates.', icon: 'emails' }]
                                        : []),
                                ]"
                                :key="item.route"
                                :href="route(item.route)"
                                prefetch
                                :aria-current="isActive(item.match) ? 'page' : undefined"
                                :aria-label="item.label"
                                :class="[
                                    'inline-flex min-h-10 items-center gap-2 rounded-control px-3 text-sm font-medium transition-colors',
                                    isActive(item.match)
                                        ? 'bg-brand-50 text-brand-700'
                                        : 'text-ink-muted hover:bg-surface-muted hover:text-ink',
                                ]"
                            >
                                <UiIcon :name="item.icon" :size="17" aria-hidden="true" />
                                <span class="hidden sm:inline">{{ item.label }}</span>
                            </Link>
                        </nav>
                    </div>

                    <div class="flex items-center gap-3">
                        <!-- O Admin que também resgata: o presente dele fica ao
                             lado do nome, no espaço pessoal do cabeçalho, e não
                             entre os destinos de administração à esquerda. -->
                        <Link
                            v-if="user?.meu_presente"
                            :href="route('portal.catalog')"
                            class="inline-flex min-h-10 items-center gap-2 rounded-full border border-brand-100 bg-surface px-3.5 text-sm font-medium text-brand-700 transition-colors hover:bg-brand-50"
                            aria-label="Meu presente"
                        >
                            <UiIcon name="gift" :size="17" aria-hidden="true" />
                            <span class="hidden sm:inline">Meu presente</span>
                        </Link>

                        <div class="hidden min-w-0 max-w-48 text-right sm:block">
                            <p class="truncate text-sm font-medium text-ink">{{ user?.name }}</p>
                            <p class="truncate text-xs text-ink-muted">{{ user?.email }}</p>
                        </div>
                        <span
                            aria-hidden="true"
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700"
                        >
                            {{ initials }}
                        </span>
                        <UiMenu label="Conta" :items="accountItems" />
                    </div>
                </div>
            </header>

            <main id="conteudo" tabindex="-1" class="mx-auto w-full max-w-6xl px-4 pt-6 pb-12 sm:px-6 lg:px-8">
                <slot />
            </main>

            <!-- Canal de contato (UAT): some inteiro quando nada configurado. -->
            <footer v-if="hasSupport" class="mx-auto w-full max-w-6xl px-4 pb-10 sm:px-6 lg:px-8">
                <SupportLinks class="-mx-3 max-w-xs" />
            </footer>
        </template>

        <template v-else>
            <div class="flex min-h-dvh">
                <!-- `border-r`: com a navegação branca sobre o canvas cinza, é
                     a borda que separa as duas superfícies. Sem ela a coluna
                     encosta no conteúdo e a divisa some. -->
                <aside
                    class="sticky top-0 hidden h-dvh w-sidebar shrink-0 flex-col border-r border-nav-line bg-nav lg:flex"
                >
                    <!-- A palavra É o logotipo: o quadrado com "W" era um
                         provisório copiado em 6 lugares e 3 tamanhos. Aqui a
                         marca fica escrita, e a sílaba em laranja carrega a
                         cor sem precisar de símbolo. Sobre o branco o laranja
                         vivo (400/500) não passa 4,5:1 — quem carrega a marca
                         em texto é o 700. -->
                    <div class="flex h-20 items-center px-6">
                        <span class="flex flex-col leading-none">
                            <span class="text-2xl font-bold tracking-tight text-nav-ink">
                                We<span class="text-brand-700">Log</span>
                            </span>
                            <span class="mt-1 text-[10px] font-medium tracking-wide text-nav-ink-subtle">
                                por WeGift
                            </span>
                        </span>
                    </div>

                    <nav aria-label="Navegação principal" class="scrollbar-slim flex-1 overflow-y-auto px-3 pb-4">
                        <div
                            v-for="(group, index) in groups"
                            :key="group.label"
                            :class="index > 0 && 'mt-4 border-t border-nav-line pt-4'"
                        >
                            <!-- Rótulo estrutural, não momento de marca: estava
                                 em laranja e gastava a cor da marca três vezes
                                 numa coluna onde ela precisa apontar UMA coisa
                                 — onde você está. -->
                            <p class="px-3 pb-2 text-[10px] font-semibold tracking-wide text-nav-ink-subtle uppercase">
                                {{ group.label }}
                            </p>

                            <ul class="space-y-0.5">
                                <li v-for="item in group.items" :key="item.route">
                                    <Link
                                        :href="route(item.route)"
                                        prefetch
                                        :aria-current="isActive(item.match) ? 'page' : undefined"
                                        :class="[
                                            'relative flex items-center gap-3 rounded-control px-3 py-2.5 text-sm font-medium transition-colors',
                                            isActive(item.match)
                                                ? 'bg-nav-raised text-brand-700'
                                                : 'text-nav-ink-muted hover:bg-nav-raised/60 hover:text-nav-ink',
                                        ]"
                                    >
                                        <span
                                            v-if="isActive(item.match)"
                                            aria-hidden="true"
                                            class="absolute inset-y-1.5 left-0 w-1 rounded-full bg-brand-600"
                                        />
                                        <UiIcon
                                            :name="item.icon"
                                            :size="18"
                                            :class="isActive(item.match) ? 'text-brand-700' : 'text-nav-ink-muted'"
                                        />
                                        <span class="truncate">{{ item.label }}</span>
                                    </Link>
                                </li>
                            </ul>
                        </div>
                    </nav>

                    <!-- Rodapé da coluna: contato de suporte e a conta. O bloco
                         some inteiro quando nenhum canal está configurado. -->
                    <SupportLinks
                        v-if="hasSupport"
                        tone="nav"
                        class="border-t border-nav-line px-3 pt-3 pb-1"
                    />

                    <div class="flex items-center gap-2.5 border-t border-nav-line p-3">
                        <span
                            aria-hidden="true"
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-ink-inverse"
                        >
                            {{ initials }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-nav-ink">{{ user?.name }}</p>
                            <!-- Perfil no lugar do e-mail: quem está logado sabe
                                 o próprio e-mail; o que muda o que ele pode fazer
                                 é o papel. -->
                            <p class="truncate text-xs text-nav-ink-muted">{{ roleLabel }}</p>
                            <!-- `surface-muted`, não `nav-raised`: com a
                                 navegação clara, o raised virou a chapa da
                                 marca que marca o item ATIVO, e o selo de
                                 leitura passaria a disputar essa leitura. -->
                            <span
                                v-if="readOnly"
                                class="mt-1.5 inline-flex items-center rounded-full bg-surface-muted px-2 py-0.5 text-xs font-medium text-nav-ink-muted"
                            >
                                Somente consulta
                            </span>
                        </div>
                        <UiMenu label="Conta" :items="accountItems" tone="nav" />
                    </div>
                </aside>

                <div class="flex min-w-0 flex-1 flex-col">
                    <header
                        class="sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-line bg-surface/95 px-3 backdrop-blur sm:px-6 lg:px-8"
                    >
                        <UiButton
                            variant="ghost"
                            icon="menu"
                            icon-only
                            label="Abrir menu"
                            class="lg:hidden"
                            @click="menuOpen = true"
                        />
                        <span class="text-base font-bold tracking-tight text-ink lg:hidden">
                            We<span class="text-brand-600">Log</span>
                        </span>

                        <!-- Busca leva à lista de projetos já filtrada: o campo
                             não guarda estado próprio nem promete resultado que
                             não existe. -->
                        <form
                            class="ml-auto hidden min-w-0 flex-1 justify-end md:flex"
                            role="search"
                            @submit.prevent="submitSearch"
                        >
                            <label for="busca-global" class="sr-only">Buscar projetos e clientes</label>
                            <div class="relative w-full max-w-80">
                                <UiIcon
                                    name="search"
                                    :size="16"
                                    aria-hidden="true"
                                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-subtle"
                                />
                                <input
                                    id="busca-global"
                                    v-model="searchTerm"
                                    type="search"
                                    placeholder="Buscar projetos, clientes…"
                                    class="h-control-md w-full rounded-control border border-line bg-canvas pr-3 pl-9 text-sm text-ink placeholder:text-ink-subtle hover:border-line-strong focus:border-brand-600 focus:bg-surface"
                                />
                            </div>
                        </form>

                        <!-- Sino: a contagem é de itens REAIS parados esperando
                             ação (ver HandleInertiaRequests). O clique abre o
                             painel de notificações com cada pendência linkada —
                             não teleporta mais para a aba Projetos (UAT). -->
                        <NotificationsBell
                            class="ml-auto md:ml-0"
                            :count="pendingCount"
                            :projects="pendingProjects"
                            :all-href="route('projects.index', { stage: 'in_stock', situation: 'ready' })"
                        />

                        <div class="flex shrink-0 items-center gap-2 border-l border-line pl-2">
                            <span
                                aria-hidden="true"
                                class="flex size-9 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700"
                            >
                                {{ initials }}
                            </span>
                            <UiMenu label="Conta" :items="accountItems" />
                        </div>
                    </header>

                    <main id="conteudo" tabindex="-1" class="mx-auto w-full max-w-7xl px-4 py-5 pb-10 sm:px-6 lg:px-8">
                        <slot />
                    </main>
                </div>
            </div>

            <UiDrawer v-model:open="menuOpen" title="WeLog" side="left">
                <nav aria-label="Navegação principal">
                    <div
                        v-for="(group, index) in groups"
                        :key="group.label"
                        :class="index > 0 && 'mt-4 border-t border-line pt-4'"
                    >
                        <p class="px-3 pb-1 text-xs font-semibold tracking-wide text-ink-muted uppercase">
                            {{ group.label }}
                        </p>

                        <ul class="space-y-0.5">
                            <li v-for="item in group.items" :key="item.route">
                                <Link
                                    :href="route(item.route)"
                                    :aria-current="isActive(item.match) ? 'page' : undefined"
                                    :class="[
                                        'flex min-h-12 items-center gap-3 rounded-control px-3 text-sm font-medium',
                                        isActive(item.match)
                                            ? 'bg-brand-50 text-brand-700'
                                            : 'text-ink-muted hover:bg-surface-muted hover:text-ink',
                                    ]"
                                    @click="menuOpen = false"
                                >
                                    <UiIcon
                                        :name="item.icon"
                                        :size="18"
                                        :class="isActive(item.match) ? 'text-brand-600' : 'text-ink-muted'"
                                    />
                                    <span class="truncate">{{ item.label }}</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </nav>

                <template #footer>
                    <div class="w-full">
                        <!-- O celular não tem o menu "⋯" da sidebar: sem esta
                             seção, Usuários, Modelos de e-mail e Log de webhooks
                             não teriam NENHUM caminho no telefone. -->
                        <template v-if="settingsItems.length">
                            <p class="px-3 pb-1 text-xs font-semibold tracking-wide text-ink-muted uppercase">
                                Configurações
                            </p>
                            <ul class="mb-3 space-y-0.5">
                                <li v-for="item in settingsItems" :key="item.route">
                                    <Link
                                        :href="route(item.route)"
                                        :aria-current="isActive(item.match) ? 'page' : undefined"
                                        :class="[
                                            'flex min-h-12 items-center gap-3 rounded-control px-3 text-sm font-medium',
                                            isActive(item.match)
                                                ? 'bg-brand-50 text-brand-700'
                                                : 'text-ink-muted hover:bg-surface-muted hover:text-ink',
                                        ]"
                                        @click="menuOpen = false"
                                    >
                                        <UiIcon
                                            :name="item.icon"
                                            :size="18"
                                            :class="isActive(item.match) ? 'text-brand-600' : 'text-ink-muted'"
                                        />
                                        <span class="truncate">{{ item.label }}</span>
                                    </Link>
                                </li>
                            </ul>
                        </template>

                        <SupportLinks v-if="hasSupport" class="mb-3" />

                        <p class="truncate text-sm font-medium text-ink">{{ user?.name }}</p>
                        <p class="truncate text-xs text-ink-muted">{{ user?.email }}</p>
                        <UiBadge v-if="readOnly" variant="neutral" class="mt-2">Somente consulta</UiBadge>

                        <UiButton variant="secondary" icon="logout" block class="mt-3" @click="logout">
                            Sair
                        </UiButton>
                    </div>
                </template>
            </UiDrawer>
        </template>

        <UiConfirm />
        <Toaster />
        <!-- Planilha de Colaboradores em andamento: segue a pessoa em
             qualquer tela e avisa quando termina. -->
        <ImportWatcher />
    </div>
</template>
