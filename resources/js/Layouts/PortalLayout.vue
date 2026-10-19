<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Toaster from '../Components/Toaster.vue';
import UiConfirm from '../Components/UiConfirm.vue';
import UiIcon from '../Components/UiIcon.vue';
import UiMenu from '../Components/UiMenu.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const support = computed(() => page.props.support ?? {});

/**
 * O layout é persistente entre navegações e `route().current()` lê a URL do
 * navegador, que não é reativa: sem depender de `page.url`, o item ativo
 * congelaria no primeiro destino visitado.
 */
const current = computed(() => (page.url ? route().current() : null));

// Dois destinos, uma lista: a barra inferior do celular e a fila do desktop
// renderizam este mesmo array (§8.4).
const nav = [
    { label: 'Meu presente', route: 'portal.catalog', icon: 'gift' },
    { label: 'Entregas', route: 'portal.redemptions', icon: 'truck' },
];

// Admin do lado do Cliente que também resgata: o caminho de volta às telas
// de administração, à mão no menu da conta.
const isAdmin = computed(() => user.value?.is_client_admin || user.value?.is_project_admin);

/**
 * Telas que abrem em duas colunas no desktop, em vez da coluna única de 672px.
 *
 * A coluna estreita é a medida certa para LER — o formulário de resgate e a
 * lista de entregas continuam nela. A do presente tem cartão, banner e
 * acompanhamento ao mesmo tempo, e nessa largura tudo empilha no meio com os
 * dois lados vazios.
 *
 * A decisão vive AQUI, e não como prop vinda da página: passá-la por uma
 * função de layout parece funcionar e descarta o layout inteiro em silêncio
 * nesta versão do Inertia (ver o comentário em Portal/Catalog.vue).
 */
const ROTAS_LARGAS = ['portal.catalog'];

const wide = computed(() => ROTAS_LARGAS.includes(current.value));

function logout() {
    router.post(route('logout'));
}

/** Sem isto, um Admin que cai no portal fica sem saída. */
const accountItems = computed(() => [
    ...(isAdmin.value
        ? [{ label: 'Voltar para Projetos', icon: 'projects', onSelect: () => router.visit(route('projects.index')) }]
        : []),
    { label: 'Sair', icon: 'logout', onSelect: logout },
]);
</script>

<template>
    <!-- data-surface="portal" redefine os papéis de cor e sombra para toda a
         árvore (ver o escopo em app.css). É o que permite a MESMA base servir
         a operação densa e o portal acolhedor sem duplicar componente. -->
    <div data-surface="portal" class="min-h-dvh bg-canvas">
        <a
            href="#conteudo"
            class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:border focus:border-line focus:bg-surface focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-ink focus:shadow-overlay"
        >
            Pular para o conteúdo
        </a>

        <!--
            Cabeçalho escuro com o laranja da WeGift como luz, não como chapa:
            um brilho difuso atrás da marca e uma linha acesa na base. É o que
            dá ao portal cara de lugar de resgate cuidado, sem competir com o
            presente, que continua sobre o fundo claro logo abaixo.
        -->
        <header class="relative overflow-hidden bg-ink">
            <span
                aria-hidden="true"
                class="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-brand-400 to-transparent shadow-[0_0_14px_2px_rgb(255_90_10/0.45)]"
            />

            <!-- Na largura do conteúdo da tela: a marca alinha com a vitrine. -->
            <div :class="['relative isolate mx-auto flex h-16 w-full items-center gap-3 px-4', wide ? 'max-w-5xl' : 'max-w-2xl']">
                <!-- O brilho mora atrás da marca, e não no canto da janela. -->
                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute -top-24 -left-20 -z-10 h-44 w-80 rounded-full bg-brand-500/25 blur-3xl"
                />

                <div class="flex shrink-0 items-center gap-2.5">
                    <span
                        aria-hidden="true"
                        class="flex size-8 items-center justify-center rounded-control bg-brand-500 text-sm font-bold text-ink-inverse shadow-[0_0_18px_rgb(255_90_10/0.55)]"
                    >
                        W
                    </span>
                    <span class="flex flex-col leading-none">
                        <span class="text-base font-semibold tracking-tight text-ink-inverse">WeLog</span>
                        <span class="mt-0.5 text-[10px] font-medium tracking-wide text-ink-inverse/50">por WeGift</span>
                    </span>
                </div>

                <nav aria-label="Navegação do portal" class="ml-2 hidden items-center gap-1 sm:flex">
                    <Link
                        v-for="item in nav"
                        :key="item.route"
                        :href="route(item.route)"
                        :aria-current="current === item.route ? 'page' : undefined"
                        :class="[
                            'inline-flex min-h-10 items-center gap-2 rounded-full px-3.5 text-sm font-medium transition-colors',
                            current === item.route
                                ? 'bg-ink-inverse/10 text-ink-inverse ring-1 ring-ink-inverse/10'
                                : 'text-ink-inverse/60 hover:bg-ink-inverse/5 hover:text-ink-inverse',
                        ]"
                    >
                        <UiIcon
                            :name="item.icon"
                            :size="17"
                            :class="current === item.route ? 'text-brand-300' : ''"
                        />
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <div class="min-w-0 max-w-40 text-right">
                        <p class="truncate text-sm font-medium text-ink-inverse">{{ user?.name }}</p>
                        <p class="hidden truncate text-xs text-ink-inverse/50 sm:block">{{ user?.email }}</p>
                    </div>
                    <UiMenu label="Conta" tone="dark" :items="accountItems" />
                </div>
            </div>
        </header>

        <!--
            Atendimento do Cliente (Customizações → "Atendimento no portal").

            Uma faixa fina colada no cabeçalho, em todas as telas: é ajuda,
            não aviso — à vista quando a dúvida aparece, sem disputar com o
            presente. Branca como o cabeçalho, para ler como parte dele.
        -->
        <div v-if="support.banner && support.email" class="border-b border-line bg-surface">
            <!-- Centrada e mais larga que o cabeçalho: numa linha só no
                 desktop. O e-mail nunca quebra no meio — quando falta espaço,
                 ele desce inteiro para a linha de baixo. -->
            <p
                class="mx-auto flex w-full max-w-5xl items-start gap-2 px-4 py-2.5 text-sm text-ink-muted sm:items-center sm:justify-center"
            >
                <UiIcon name="support" :size="16" class="mt-0.5 shrink-0 text-brand-700 sm:mt-0" aria-hidden="true" />
                <span>
                    Em caso de dúvidas ou problemas, contate a equipe da WeGift no e-mail
                    <a
                        :href="`mailto:${support.email}`"
                        class="font-medium whitespace-nowrap text-ink underline-offset-2 hover:underline"
                    >{{ support.email }}</a>
                </span>
            </p>
        </div>

        <!-- O espaço extra embaixo é da barra inferior fixa, que respeita a safe area. -->
        <main
            id="conteudo"
            tabindex="-1"
            :class="[
                'mx-auto w-full px-4 pt-6 pb-[calc(6rem+env(safe-area-inset-bottom))] text-base text-ink sm:pb-10',
                wide ? 'max-w-5xl' : 'max-w-2xl',
            ]"
        >
            <slot />
        </main>

        <nav
            aria-label="Navegação do portal"
            class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] sm:hidden"
        >
            <div class="flex">
                <Link
                    v-for="item in nav"
                    :key="item.route"
                    :href="route(item.route)"
                    :aria-current="current === item.route ? 'page' : undefined"
                    :class="[
                        'flex h-14 flex-1 flex-col items-center justify-center gap-0.5 text-xs font-medium',
                        current === item.route ? 'text-brand-700' : 'text-ink-muted',
                    ]"
                >
                    <UiIcon :name="item.icon" :size="22" />
                    {{ item.label }}
                </Link>
            </div>
        </nav>

        <UiConfirm />
        <Toaster />
    </div>
</template>
