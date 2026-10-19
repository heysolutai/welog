<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import UiButton from '../../Components/UiButton.vue';

/**
 * 403 renderizado DENTRO do app (navegação Inertia). O irmão Blade
 * (errors/403.blade.php) atende o acesso direto por URL; este atende o clique
 * numa tela viva, onde o Blade chegava preso num iframe e os botões não
 * respondiam.
 */
defineOptions({ layout: null });

const props = defineProps({
    home: { type: String, required: true },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <Head title="Sem acesso" />

    <main class="flex min-h-dvh items-center justify-center bg-canvas px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <p class="mb-4 text-xl font-bold tracking-tight text-ink">
                    We<span class="text-brand-600">Log</span>
                </p>
                <h1 class="text-title font-bold text-ink">Esta página é de outra área do sistema.</h1>
                <p class="mt-2 text-sm text-ink-muted">
                    Seu acesso continua válido. O endereço é que não pertence ao seu perfil.
                </p>
            </div>

            <div class="rounded-card bg-surface shadow-card p-6 sm:p-8">
                <p class="text-sm text-ink">
                    Você está conectado como <strong>{{ user?.name }}</strong>. Volte para a sua área
                    de trabalho pelo botão abaixo.
                </p>

                <UiButton class="mt-6" size="lg" block :href="home">Ir para a minha área</UiButton>
                <UiButton class="mt-3" variant="secondary" block @click="logout">
                    Sair e entrar com outra conta
                </UiButton>
            </div>
        </div>
    </main>
</template>
