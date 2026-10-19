<script setup>
import { computed, ref } from 'vue';
import UiIcon from './UiIcon.vue';
import UiInput from './UiInput.vue';

/**
 * Campo de senha com o olho DENTRO dele.
 *
 * Existia como um botão "Mostrar" ao lado do campo, repetido em três telas.
 * Ele tomava um terço da linha, vinha com a moldura de uma ação de verdade —
 * competindo com "Entrar" — e deixava a senha visivelmente mais curta que o
 * e-mail logo acima.
 *
 * A visibilidade pode ser COMPARTILHADA entre campos (`v-model:visible`): na
 * redefinição, "nova senha" e "confirmar senha" mostram e escondem juntas.
 * Sem isso, alguém revelaria uma e continuaria digitando a outra às cegas,
 * que é justamente onde o erro de digitação acontece.
 */
const model = defineModel({ type: String, default: '' });

// Opcional: quando o pai não controla, o campo cuida de si.
const visibleModel = defineModel('visible', { type: Boolean, default: undefined });

const props = defineProps({
    invalid: { type: Boolean, default: false },
    autocomplete: { type: String, default: 'current-password' },
    autofocus: { type: Boolean, default: false },
    size: { type: String, default: 'lg' },
});

const visivelInterno = ref(false);

const visivel = computed({
    get: () => (visibleModel.value === undefined ? visivelInterno.value : visibleModel.value),
    set: (valor) => {
        if (visibleModel.value === undefined) {
            visivelInterno.value = valor;

            return;
        }

        visibleModel.value = valor;
    },
});
</script>

<template>
    <UiInput
        v-model="model"
        :type="visivel ? 'text' : 'password'"
        :size="size"
        :invalid="invalid"
        :autocomplete="autocomplete"
        :autofocus="autofocus || undefined"
    >
        <template #action>
            <button
                type="button"
                class="flex h-full w-12 items-center justify-center rounded-r-control text-ink-muted transition-colors hover:text-ink"
                :aria-pressed="visivel"
                :aria-label="visivel ? 'Ocultar senha' : 'Mostrar senha'"
                @click="visivel = !visivel"
            >
                <UiIcon :name="visivel ? 'eyeOff' : 'eye'" :size="18" aria-hidden="true" />
            </button>
        </template>
    </UiInput>
</template>
