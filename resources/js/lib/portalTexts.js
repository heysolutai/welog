/**
 * Textos da tela de resgate escritos pela campanha (Itens do pedido > Tela de
 * resgate). Quem escreve pode usar {empresa} e {n}, como no texto padrão de
 * exemplo: "A {empresa} preparou um presente para você.", "Escolha 1 destas
 * {n} opções".
 */
export function preencher(texto, { empresa = '', n = '' } = {}) {
    return String(texto ?? '')
        .replaceAll('{empresa}', empresa)
        .replaceAll('{n}', String(n));
}
