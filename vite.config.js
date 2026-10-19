import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        // Origem que o NAVEGADOR usa para buscar os módulos/HMR (o container
        // escuta em 0.0.0.0, mas o browser acessa via localhost).
        origin: 'http://localhost:5173',
        cors: true,
        hmr: { host: 'localhost' },
        watch: {
            // Bind mount do Docker Desktop no Windows não propaga eventos de
            // arquivo: sem polling o HMR fica servindo módulo antigo.
            //
            // Mas polling é `stat` em TODO arquivo observado, a cada intervalo,
            // e neste mount cada `stat` custa ~3ms. Observando a raiz inteira,
            // uma varredura não terminava antes da seguinte começar — o
            // processo ficava permanentemente ocupado e disputava CPU com a
            // compilação dos módulos.
            //
            // Só `resources/` precisa de HMR. O resto (PHP, testes, docs,
            // build, uploads) ou não recarrega o navegador ou é servido pelo
            // Laravel, que lê do disco a cada requisição.
            ignored: [
                '**/.git/**',
                '**/node_modules/**',
                '**/vendor/**',
                '**/storage/**',
                '**/public/build/**',
                '**/tests/**',
                '**/docs/**',
                '**/bootstrap/cache/**',
            ],
            usePolling: true,
            interval: 1500,
        },
    },
});
