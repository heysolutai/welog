import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import AppLayout from './Layouts/AppLayout.vue';
import { initSentry } from './sentry';

const appName = import.meta.env.VITE_APP_NAME || 'WeLog';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];

        // Layout padrão só quando a página não define o próprio (ou opta por
        // nenhum com `layout: null`, como a tela de login).
        if (page.default.layout === undefined) {
            page.default.layout = AppLayout;
        }

        return page;
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        // Antes do mount, para capturar erro já na renderização inicial.
        // No-op sem VITE_SENTRY_DSN (dev e testes).
        initSentry(app);

        app.use(plugin).use(ZiggyVue).mount(el);
    },
    progress: {
        // Lê o token em vez de cravar a cor: antes era um slate-900 do tema
        // anterior, que não existia na paleta e ficaria para trás na troca
        // de marca — logo na barra que aparece em TODA navegação.
        color: getComputedStyle(document.documentElement)
            .getPropertyValue('--color-brand-600')
            .trim() || '#bc5411',
        showSpinner: false,
    },
});

/*
 * Os links principais pré-carregam a página no hover (`prefetch`), e o
 * Inertia guarda a resposta por 30 segundos: o clique abre na hora. Qualquer
 * gravação (POST, PATCH, DELETE) pode mudar o que essas páginas mostram, então
 * o cache inteiro cai antes dela. Ninguém volta para uma tela com o dado de
 * antes da própria ação.
 */
router.on('before', (event) => {
    if (event.detail.visit.method !== 'get') router.flushAll();
});
