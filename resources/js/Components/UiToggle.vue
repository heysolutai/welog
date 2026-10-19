<script setup>
import { computed, useId } from 'vue';

/**
 * Chave liga/desliga de uma feature.
 *
 * Existe separado do `UiCheckbox` porque responde a outra pergunta. A caixa
 * marca uma ESCOLHA dentro de um formulário, que só vale quando alguém salva;
 * a chave aplica o efeito na hora, e o que ela mostra é o ESTADO atual do
 * sistema. Usar caixa para isso fazia a pessoa procurar o botão de salvar que
 * não existe.
 *
 * O estado é dito de TRÊS formas, e nenhuma delas sozinha:
 *
 *   1. a palavra "Ativado"/"Desativado" ao lado — a que não depende de vista
 *      nem de convenção, e a que faltava: só a cor do trilho deixava a chave
 *      lendo como um botão verde, sem par visível de ligado/desligado;
 *   2. a posição do pino, que é a leitura rápida de quem já conhece o controle;
 *   3. a cor do trilho, que reforça as duas.
 *
 * É um `<button role="switch">` e não um `<input type=checkbox>` estilizado:
 * o leitor de tela anuncia "ligado/desligado" em vez de "marcado", que é o
 * vocabulário certo para uma feature.
 *
 * Enquanto `loading`, a chave fica desabilitada e já pinta o estado de
 * DESTINO. O servidor é quem confirma, mas mostrar o estado antigo durante a
 * ida e volta faz parecer que o clique não pegou — e leva ao segundo clique,
 * que desfaz o primeiro.
 */
const props = defineProps({
    modelValue: { type: Boolean, required: true },
    /** Rótulo para leitor de tela quando não há texto visível ao lado. */
    label: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    /** Id de um elemento que descreve a chave (a linha de apoio da feature). */
    describedBy: { type: String, default: null },
    /**
     * A palavra "Ativado"/"Desativado" ao lado. Desligue só numa lista de
     * ajustes em que a frase da própria linha já diz o estado — aí a palavra
     * repete o que está escrito ao lado e a chave continua tendo pino e cor.
     */
    stateLabel: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue']);

const id = useId();

const ligado = computed(() => (props.loading ? !props.modelValue : props.modelValue));
const travado = computed(() => props.disabled || props.loading);

function alternar() {
    if (travado.value) return;

    emit('update:modelValue', !props.modelValue);
}
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <!-- A palavra muda de peso junto com o estado: desligado fica discreto,
             ligado ganha a cor de sucesso. Assim os dois estados se distinguem
             mesmo em escala de cinza. -->
        <span
            v-if="stateLabel"
            aria-hidden="true"
            :class="[
                'text-xs font-medium tabular-nums transition-colors',
                ligado ? 'text-success' : 'text-ink-subtle',
            ]"
        >
            {{ ligado ? 'Ativado' : 'Desativado' }}
        </span>

        <button
            :id="id"
            type="button"
            role="switch"
            :aria-checked="ligado"
            :aria-label="label"
            :aria-describedby="describedBy"
            :disabled="travado"
            :class="[
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition-colors duration-200',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                'motion-reduce:transition-none',
                ligado ? 'border-success bg-success' : 'border-line bg-surface-muted',
                travado && 'cursor-not-allowed opacity-60',
            ]"
            @click="alternar"
        >
            <!--
                Medidas em pixel e não na escala de espaçamento: o pino precisa
                caber no trilho com folga exata (44 − 20 − 2 − 2 = 22px de
                curso), e é o tipo de conta que um passo da escala arredonda
                para o lado errado — o pino some dentro do trilho.

                Sombra e contorno no pino: sobre o verde do ligado, um círculo
                branco chapado quase desaparece.

                `bg-surface` e não `bg-white`: o tema fecha a paleta de fábrica
                (`--color-*: initial` no app.css), então `bg-white` não gera
                regra nenhuma — e o pino ficava sem fundo, invisível sobre o
                verde. O branco do sistema é `--color-surface`.
            -->
            <span
                aria-hidden="true"
                class="absolute top-1/2 block size-5 -translate-y-1/2 rounded-full bg-surface shadow-xs ring-1 ring-ink/15 transition-[left] duration-200 motion-reduce:transition-none"
                :style="{ left: ligado ? '22px' : '2px' }"
            />
        </button>
    </span>
</template>
