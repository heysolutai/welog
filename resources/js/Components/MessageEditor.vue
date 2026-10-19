<script setup>
/**
 * Campo de texto do Estúdio de mensagens, no jeito de um app de e-mail.
 *
 * Quem escreve vê o negrito em negrito e o itálico em itálico, e cada
 * informação automática (Nome, Presente...) como uma etiqueta. Por baixo, o
 * texto continua o mesmo que o envio sempre entendeu: `**negrito**`,
 * `*itálico*` e `{nome}`. Nada disso aparece para o Cliente.
 *
 * O botão do e-mail é um bloco próprio (`{botao}`), numa linha só: mostra
 * onde ele vai ficar, e muda de lugar recortando e colando, ou pelo botão
 * "Botão" da barra. Existe uma vez só por mensagem.
 *
 * `multiline` é o corpo da mensagem: Enter quebra a linha e uma linha em
 * branco começa um parágrafo. Sem ele, o campo tem uma linha só (Assunto e
 * Título). `rich` liga negrito e itálico.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    // token => { label }
    variaveis: { type: Object, default: () => ({}) },
    multiline: { type: Boolean, default: false },
    rich: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    label: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'focus', 'formato']);

const el = ref(null);
let ultimoEmitido = null;
let faixaSalva = null;

// ── Texto → tela ────────────────────────────────────────────────────────────
const escapar = (s) => String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const CLASSE_ETIQUETA = 'mx-px rounded-md bg-brand-50 px-1.5 py-px text-brand-700 ring-1 ring-brand-100 ring-inset select-all';

const BOTAO = 'botao';

function etiqueta(token) {
    const rotulo = escapar(props.variaveis[token]?.label ?? token);

    if (token === BOTAO) {
        return `<span data-token="botao" contenteditable="false" class="inline-flex w-full cursor-default justify-center py-1 select-none" `
            + `title="O botão do e-mail. Para mudar de lugar, recorte e cole, ou use Botão na barra.">`
            + `<span class="rounded-md bg-brand-600 px-4 py-1.5 text-[13px] font-medium text-ink-inverse shadow-xs">${rotulo}</span></span>`;
    }

    return `<span data-token="${escapar(token)}" contenteditable="false" class="${CLASSE_ETIQUETA}">${rotulo}</span>`;
}

function paraHtml(texto) {
    let html = escapar(String(texto ?? '').replace(/ {2}\n/g, '\n'))
        .replace(/\{([a-z_]+)\}/g, (inteiro, token) => (props.variaveis[token] ? etiqueta(token) : inteiro));

    if (props.rich) {
        html = html
            .replace(/\*\*(.+?)\*\*/gs, '<b>$1</b>')
            .replace(/\*(\S(?:.*?\S)?)\*/gs, '<i>$1</i>');
    }

    // Linha final vazia só aparece com um <br> a mais.
    if (html.endsWith('\n')) html += '<br>';

    return html.replace(/\n/g, '<br>');
}

// ── Tela → texto ────────────────────────────────────────────────────────────
const BLOCOS = new Set(['DIV', 'P', 'LI']);

function envolver(conteudo, marca) {
    const [, antes, miolo, depois] = conteudo.match(/^(\s*)([\s\S]*?)(\s*)$/);

    return miolo ? `${antes}${marca}${miolo}${marca}${depois}` : conteudo;
}

function serializar(no, negrito = false, italico = false) {
    let saida = '';

    no.childNodes.forEach((filho, i) => {
        if (filho.nodeType === Node.TEXT_NODE) {
            saida += filho.textContent.replace(/​/g, '').replace(/ /g, ' ');

            return;
        }

        if (filho.nodeType !== Node.ELEMENT_NODE) return;

        if (filho.dataset.token === BOTAO) {
            // Parágrafo próprio, sempre: no e-mail ele é um bloco.
            saida += '\n\n{botao}\n\n';

            return;
        }

        if (filho.dataset.token) {
            saida += `{${filho.dataset.token}}`;

            return;
        }

        if (filho.tagName === 'BR') {
            // O último <br> é só o apoio do cursor no fim do campo.
            if (filho === no.lastChild && no === el.value) return;
            saida += '\n';

            return;
        }

        const estilo = filho.style ?? {};
        const ehNegrito = props.rich && (['B', 'STRONG'].includes(filho.tagName) || ['bold', '600', '700', '800'].includes(estilo.fontWeight));
        const ehItalico = props.rich && (['I', 'EM'].includes(filho.tagName) || estilo.fontStyle === 'italic');

        let dentro = serializar(filho, negrito || ehNegrito, italico || ehItalico);

        if (ehItalico && !italico) dentro = envolver(dentro, '*');
        if (ehNegrito && !negrito) dentro = envolver(dentro, '**');

        if (BLOCOS.has(filho.tagName) && i > 0 && !saida.endsWith('\n')) saida += '\n';
        saida += dentro;
    });

    return saida;
}

/** Uma quebra vira quebra de linha do e-mail; linha em branco, parágrafo. */
function normalizar(texto) {
    if (!props.multiline) return texto.replace(/\n+/g, ' ');

    return texto
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .replace(/^\n+|\n+$/g, '')
        .replace(/([^\n])\n(?!\n)/g, '$1  \n');
}

function aoDigitar() {
    let texto = normalizar(serializar(el.value));

    if (!el.value.textContent.replace(/​/g, '').trim() && !el.value.querySelector('[data-token]')) {
        texto = '';
        el.value.innerHTML = '';
    }

    ultimoEmitido = texto;
    emit('update:modelValue', texto);
}

function desenhar() {
    if (!el.value) return;
    el.value.innerHTML = paraHtml(props.modelValue);
    ultimoEmitido = props.modelValue;
}

watch(() => props.modelValue, (valor) => {
    if (valor !== ultimoEmitido) desenhar();
});
watch(() => props.variaveis, desenhar);

// ── Teclado, colar, seleção ─────────────────────────────────────────────────
function aoTeclar(evento) {
    const atalho = evento.ctrlKey || evento.metaKey;

    if (atalho && ['b', 'i', 'u'].includes(evento.key.toLowerCase())) {
        evento.preventDefault();
        if (props.rich && evento.key.toLowerCase() !== 'u') formatar(evento.key.toLowerCase() === 'b' ? 'bold' : 'italic');

        return;
    }

    if (evento.key === 'Enter') {
        evento.preventDefault();
        if (props.multiline) document.execCommand('insertLineBreak');
    }
}

/**
 * Copiar e recortar levam o texto como o envio o entende: com o negrito, as
 * informações e o botão. É o que deixa mover o botão recortando e colando.
 */
function aoCopiar(evento, recortar = false) {
    const sel = document.getSelection();

    if (!sel?.rangeCount || !dentro(sel.anchorNode)) return;

    const pedaco = document.createElement('div');
    pedaco.appendChild(sel.getRangeAt(0).cloneContents());

    evento.preventDefault();
    evento.clipboardData.setData('text/plain', normalizar(serializar(pedaco)));

    if (recortar) {
        const levouBotao = !!pedaco.querySelector('[data-token="botao"]');

        document.execCommand('delete');

        // Bloco que não é editável às vezes resiste ao "delete": sai à mão.
        if (levouBotao) removerBotao();

        aoDigitar();
        if (levouBotao) reorganizar();
    }
}

/**
 * Colar entra sem a formatação de outro lugar: o que vem de fora é texto
 * puro. O que saiu daqui (negrito, informações, o botão) volta como estava.
 */
function aoColar(evento) {
    evento.preventDefault();
    let texto = evento.clipboardData?.getData('text/plain') ?? '';

    if (!props.multiline) {
        document.execCommand('insertText', false, texto.replace(/\s*\n\s*/g, ' '));

        return;
    }

    const comBotao = texto.includes('{botao}');

    // Um botão só: colar outro muda o de lugar.
    if (comBotao) removerBotao();

    document.execCommand('insertHTML', false, paraHtml(texto));
    aoDigitar();

    if (comBotao) reorganizar(true);
}

function removerBotao() {
    el.value?.querySelectorAll('[data-token="botao"]').forEach((b) => b.remove());
}

/**
 * Depois de o botão mudar de lugar, a tela é refeita a partir do texto: as
 * linhas em branco em volta dele ficam certas e o texto de onde ele saiu não
 * gruda. O cursor volta para logo depois do botão.
 */
function reorganizar(cursorNoBotao = false) {
    el.value.innerHTML = paraHtml(ultimoEmitido);

    const botao = cursorNoBotao ? el.value.querySelector('[data-token="botao"]') : null;

    if (botao) {
        const faixa = document.createRange();
        faixa.setStartAfter(botao);
        faixa.collapse(true);
        const sel = document.getSelection();
        sel.removeAllRanges();
        sel.addRange(faixa);
        faixaSalva = faixa.cloneRange();
    }
}

function dentro(no) {
    return !!no && !!el.value && el.value.contains(no);
}

function aoMudarSelecao() {
    const sel = document.getSelection();

    if (!sel?.rangeCount || !dentro(sel.anchorNode)) return;

    faixaSalva = sel.getRangeAt(0).cloneRange();

    if (props.rich) {
        emit('formato', {
            negrito: document.queryCommandState('bold'),
            italico: document.queryCommandState('italic'),
        });
    }
}

onMounted(() => {
    document.execCommand('styleWithCSS', false, false);
    desenhar();
    document.addEventListener('selectionchange', aoMudarSelecao);
});

onBeforeUnmount(() => document.removeEventListener('selectionchange', aoMudarSelecao));

/** Devolve o cursor para onde estava (ou para o fim), antes de um comando. */
function retomar() {
    el.value.focus();
    const sel = document.getSelection();

    if (faixaSalva && dentro(faixaSalva.startContainer)) {
        sel.removeAllRanges();
        sel.addRange(faixaSalva);

        return;
    }

    const faixa = document.createRange();
    faixa.selectNodeContents(el.value);
    faixa.collapse(false);
    sel.removeAllRanges();
    sel.addRange(faixa);
}

// ── O que o Estúdio chama ───────────────────────────────────────────────────
function formatar(comando) {
    if (!props.rich) return;
    retomar();
    document.execCommand(comando);
    aoDigitar();
    aoMudarSelecao();
}

/** A etiqueta entra onde está o cursor, com um espaço invisível de apoio. */
function inserir(token) {
    retomar();

    // O botão existe uma vez: inserir de novo é mudar de lugar.
    if (token === BOTAO) {
        removerBotao();
        document.execCommand('insertHTML', false, etiqueta(token));
        aoDigitar();
        reorganizar(true);

        return;
    }

    document.execCommand('insertHTML', false, `${etiqueta(token)}​`);
    aoDigitar();
}

defineExpose({ formatar, inserir, focar: retomar });
</script>

<template>
    <div
        ref="el"
        contenteditable="true"
        role="textbox"
        :aria-multiline="multiline ? 'true' : 'false'"
        :aria-label="label ?? placeholder"
        :data-placeholder="placeholder"
        spellcheck="true"
        class="min-w-0 break-words whitespace-pre-wrap outline-none empty:before:pointer-events-none empty:before:text-ink-subtle empty:before:content-[attr(data-placeholder)]"
        @input="aoDigitar"
        @keydown="aoTeclar"
        @paste="aoColar"
        @copy="aoCopiar($event)"
        @cut="aoCopiar($event, true)"
        @focus="emit('focus')"
    />
</template>
