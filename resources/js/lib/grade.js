/**
 * Tamanhos da mesma grade da Omie (PRD11363_1, _2…, do pai PRD11363) viram
 * UMA entrada: "Colete", com os tamanhos. O pedido pode trazer cada tamanho
 * como item, mas para quem cadastra e para quem resgata é um presente só —
 * a pessoa recebe um e escolhe o tamanho.
 *
 * Entrada: [{ value, label, code, photo, grade?: { pai_code, pai_nome, rotulo, ordem } }]
 * Saída:   [{ key, label, code, photo, values: [ids], tamanhos: ['P', 'M', …] }]
 *
 * Grade com um tamanho só não agrupa: é um item como outro qualquer.
 */
export function agruparPorGrade(options) {
    const porPai = new Map();

    for (const option of options) {
        const pai = option.grade?.pai_code;
        if (!pai) continue;
        if (!porPai.has(pai)) porPai.set(pai, []);
        porPai.get(pai).push(option);
    }

    const vistos = new Set();
    const entradas = [];

    for (const option of options) {
        const pai = option.grade?.pai_code;
        const irmaos = pai ? porPai.get(pai) : null;

        if (irmaos && irmaos.length > 1) {
            if (vistos.has(pai)) continue;
            vistos.add(pai);

            const ordenados = [...irmaos].sort((a, b) => compararOrdem(a.grade.ordem, b.grade.ordem));

            entradas.push({
                key: `grade-${pai}`,
                label: option.grade.pai_nome,
                code: pai,
                photo: ordenados.find((item) => item.photo)?.photo ?? null,
                values: ordenados.map((item) => item.value),
                tamanhos: ordenados.map((item) => item.grade.rotulo),
            });

            continue;
        }

        entradas.push({
            key: `item-${option.value}`,
            label: option.label,
            code: option.code,
            photo: option.photo,
            values: [option.value],
            tamanhos: [],
        });
    }

    return entradas;
}

/** Ordem de loja vinda do servidor: [cor, grupo, posição, texto]. */
export function compararOrdem(a = [], b = []) {
    for (let i = 0; i < Math.max(a.length, b.length); i++) {
        if (a[i] < b[i]) return -1;
        if (a[i] > b[i]) return 1;
    }

    return 0;
}

/**
 * Os presentes de um grupo de escolha, com os tamanhos de um mesmo pai
 * juntos num cartão só.
 *
 * Um grupo "escolhe 1" pode misturar itens avulsos e os tamanhos de uma
 * grade (a mochila, a necessaire e a jaqueta P, M, G…). Sem juntar, cada
 * tamanho virava uma opção solta: 26 cartões, nenhum com seletor de tamanho.
 *
 * Entrada: itens de `gifts[]` do portal.
 * Saída:   [{ key, tipo: 'grade', options: [...] } | { key, tipo: 'item', gift }]
 *
 * Grade com um tamanho só não junta: é um presente como outro qualquer.
 */
export function agruparPresentesPorGrade(gifts) {
    const porPai = new Map();

    for (const gift of gifts) {
        const pai = gift.grade?.pai_code;
        if (!pai) continue;
        if (!porPai.has(pai)) porPai.set(pai, []);
        porPai.get(pai).push(gift);
    }

    const vistos = new Set();
    const entradas = [];

    for (const gift of gifts) {
        const pai = gift.grade?.pai_code;
        const irmaos = pai ? porPai.get(pai) : null;

        if (irmaos && irmaos.length > 1) {
            if (!vistos.has(pai)) {
                vistos.add(pai);
                entradas.push({ key: `grade-${pai}`, tipo: 'grade', options: irmaos });
            }

            continue;
        }

        entradas.push({ key: `item-${gift.id}`, tipo: 'item', gift });
    }

    return entradas;
}
