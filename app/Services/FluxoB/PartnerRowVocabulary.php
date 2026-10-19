<?php

namespace App\Services\FluxoB;

/**
 * Traduz o relatório da appnoz para os campos que o sistema usa.
 *
 * O export da appnoz não fala a nossa língua, e não adianta pedir que fale: o
 * relatório é gerado por lá, com os nomes de lá. Quem se adapta somos nós, num
 * lugar só — aqui — em vez de espalhar `$data['usuario_solicitante'] ??
 * $data['nome']` por toda a importação.
 *
 * O template próprio continua valendo: quem já manda `nome`, `endereco` e `uf`
 * segue entrando sem mudar nada. As duas grafias convivem.
 */
class PartnerRowVocabulary
{
    /**
     * Cabeçalho da appnoz → nosso campo.
     *
     * Em minúsculas e sem acento porque é assim que o SpreadsheetReader
     * normaliza o cabeçalho; o webhook passa pelo mesmo caminho para que uma
     * planilha e um POST com os mesmos dados dêem exatamente o mesmo
     * resultado.
     */
    private const DE_PARA = [
        'id_produto' => 'codigo_produto',
        'nome_do_produto' => 'produto',
        'id_solicitacao' => 'id_solicitacao',
        'usuario_solicitante' => 'nome',
        'rua' => 'endereco',
        'estado' => 'uf',
    ];

    /**
     * A appnoz escreve o estado por extenso ("Paraná"), e o cadastro da Omie
     * quer a sigla. Sem esta tabela, TODA linha do relatório seria bloqueada
     * por "UF deve ter 2 caracteres" — e o erro apontaria para o campo certo
     * pelo motivo errado, que é o tipo de bloqueio que ninguém resolve.
     */
    private const UNIDADES = [
        'acre' => 'AC', 'alagoas' => 'AL', 'amapa' => 'AP', 'amazonas' => 'AM',
        'bahia' => 'BA', 'ceara' => 'CE', 'distrito federal' => 'DF',
        'espirito santo' => 'ES', 'goias' => 'GO', 'maranhao' => 'MA',
        'mato grosso' => 'MT', 'mato grosso do sul' => 'MS', 'minas gerais' => 'MG',
        'para' => 'PA', 'paraiba' => 'PB', 'parana' => 'PR', 'pernambuco' => 'PE',
        'piaui' => 'PI', 'rio de janeiro' => 'RJ', 'rio grande do norte' => 'RN',
        'rio grande do sul' => 'RS', 'rondonia' => 'RO', 'roraima' => 'RR',
        'santa catarina' => 'SC', 'sao paulo' => 'SP', 'sergipe' => 'SE',
        'tocantins' => 'TO',
    ];

    /** @param  array<string, mixed>  $data */
    public function traduzir(array $data): array
    {
        $normalizado = [];

        foreach ($data as $chave => $valor) {
            $chave = $this->normalizarChave((string) $chave);
            $chave = self::DE_PARA[$chave] ?? $chave;

            /*
             * O que já existe não é sobrescrito.
             *
             * O relatório traz DUAS colunas "Complemento": a da atividade,
             * logo depois de "Atividade", e a do endereço, entre "Número" e
             * "Bairro". Com o mesmo nome, uma sobrescreve a outra; se a vazia
             * ganhar, a casa perde o "apto 42" e o brinde volta.
             */
            if (($normalizado[$chave] ?? '') !== '' && trim((string) $valor) === '') {
                continue;
            }

            $normalizado[$chave] = $valor;
        }

        $normalizado['uf'] = $this->sigla((string) ($normalizado['uf'] ?? ''));

        // A appnoz não tem coluna de quantidade: é uma solicitação, um brinde.
        if (trim((string) ($normalizado['quantidade'] ?? '')) === '') {
            $normalizado['quantidade'] = 1;
        }

        return $normalizado;
    }

    /** O "ID Solicitação", quando vem: a trava contra o brinde em duplicidade. */
    public function identificador(array $traduzido): ?string
    {
        $id = trim((string) ($traduzido['id_solicitacao'] ?? ''));

        return $id !== '' ? $id : null;
    }

    private function sigla(string $estado): string
    {
        $limpo = trim($estado);

        if ($limpo === '') {
            return '';
        }

        if (mb_strlen($limpo) === 2) {
            return mb_strtoupper($limpo);
        }

        return self::UNIDADES[$this->semAcento(mb_strtolower($limpo))] ?? $limpo;
    }

    private function normalizarChave(string $chave): string
    {
        $chave = $this->semAcento(mb_strtolower(trim($chave)));

        return str_replace([' ', '-'], '_', $chave);
    }

    private function semAcento(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ]);
    }
}
