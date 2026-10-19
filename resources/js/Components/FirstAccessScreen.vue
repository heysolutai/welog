<script>
/** O aviso de quando o e-mail não chega, para o Cliente que não escreveu o seu. */
export const DICA_PADRAO = 'Não achou? Veja o spam ou a caixa de promoções.';
</script>

<script setup>
/**
 * A tela de primeiro acesso: o colaborador informa o e-mail e recebe o link
 * para criar a senha.
 *
 * Mora fora da página porque é a MESMA tela na prévia das Customizações. Uma
 * cópia escrita só para a prévia mostraria ao Admin WeGift uma página que o
 * colaborador já não vê na primeira mudança feita aqui.
 *
 * A tela não envia nada: avisa quem a usa (`submit`, `trocar-email`), e a
 * página real faz o pedido. Na prévia ninguém ouve.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthCard from './AuthCard.vue';
import UiButton from './UiButton.vue';
import UiField from './UiField.vue';
import UiIcon from './UiIcon.vue';
import UiInput from './UiInput.vue';

const props = defineProps({
    companyName: { type: String, required: true },
    // O aviso do Cliente; null = o padrão.
    dica: { type: String, default: null },
    // { email, errors, processing }: o useForm da página, ou um objeto na prévia.
    form: { type: Object, required: true },
    // O e-mail para onde o link saiu. Preenchido, a tela vira a confirmação.
    enviadoPara: { type: String, default: null },
    // Hora do último "Enviar de novo" que deu certo ("14:32").
    reenviadoAs: { type: String, default: null },
    autofocus: { type: Boolean, default: true },
    embedded: { type: Boolean, default: false },
});

defineEmits(['submit', 'trocar-email']);

// "Sotreq S.A." + "." virava "Sotreq S.A.." no fim da frase.
const nomeNaFrase = computed(() => props.companyName.replace(/\.+$/, ''));
</script>

<template>
    <!--
        Enviado, a tela INTEIRA vira a confirmação: título, para onde foi e o
        que fazer se não chegar. Antes era um alerta verde em cima de um
        cartão, com título "Pronto" e lista de tópicos: três camadas para
        dizer uma frase.

        O formulário sai de cena: deixá-lo preenchido embaixo do aviso é o que
        faz a pessoa reenviar sem parar, e cada reenvio é outro e-mail na
        caixa dela.
    -->
    <AuthCard
        v-if="enviadoPara"
        title="Confira seu e-mail"
        :embedded="embedded"
    >
        <template #subtitle>
            Enviamos o link para criar sua senha para
            <span class="font-medium text-ink [overflow-wrap:anywhere]">{{ enviadoPara }}</span>.
        </template>

        <!-- Cada empresa tem o seu correio: no Outlook o e-mail cai em "Lixo
             eletrônico" ou "Outros". O texto vem das Customizações do Cliente. -->
        <p class="text-sm text-ink-muted">{{ dica || DICA_PADRAO }}</p>

        <div class="mt-6 space-y-3">
            <UiButton
                variant="secondary"
                size="lg"
                class="w-full"
                :loading="form.processing"
                @click="$emit('submit')"
            >
                Enviar de novo
            </UiButton>

            <p v-if="form.errors?.email" role="alert" class="text-center text-sm text-danger">
                {{ form.errors.email }}
            </p>
            <p v-else-if="reenviadoAs" aria-live="polite" class="text-center text-sm text-ink-muted">
                Enviado de novo às {{ reenviadoAs }}.
            </p>

            <div class="flex justify-center">
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center text-sm font-medium text-brand-700 hover:underline"
                    @click="$emit('trocar-email')"
                >
                    Usar outro e-mail
                </button>
            </div>
        </div>

        <template #footer>
            <Link :href="route('login')">
                <UiIcon name="chevron-left" :size="16" class="mr-1" />
                Já tenho senha, quero entrar
            </Link>
        </template>
    </AuthCard>

    <AuthCard
        v-else
        title="Primeiro acesso"
        :subtitle="`Campanha de brindes ${nomeNaFrase}. Informe seu e-mail corporativo para criar sua senha.`"
        :embedded="embedded"
    >
        <form class="space-y-5" @submit.prevent="$emit('submit')">
            <UiField label="E-mail corporativo" :error="form.errors?.email" required>
                <UiInput
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    autocapitalize="off"
                    autocorrect="off"
                    spellcheck="false"
                    placeholder="voce@empresa.com.br"
                    :invalid="Boolean(form.errors?.email)"
                    :autofocus="autofocus"
                />
            </UiField>

            <UiButton type="submit" size="lg" class="w-full" :loading="form.processing">
                Receber link para criar senha
            </UiButton>
        </form>

        <template #footer>
            <Link :href="route('login')">
                <UiIcon name="chevron-left" :size="16" class="mr-1" />
                Já tenho senha, quero entrar
            </Link>
        </template>
    </AuthCard>
</template>
