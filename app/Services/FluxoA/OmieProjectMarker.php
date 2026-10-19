<?php

namespace App\Services\FluxoA;

use App\Integrations\Omie\OmieAccounts;
use App\Support\Projects\ProjectTemplate;

/**
 * Decide se um Pedido de Venda da Omie deve virar Projeto WeLog.
 *
 * A conta da Omie é a mesma para toda a operação da Santa Origem: só nos
 * últimos 5 dias entraram 532 pedidos, e o sistema criava Projeto para TODOS.
 *
 * ── Dois critérios, nesta ordem ─────────────────────────────────────────────
 *
 * 1. CÓDIGO DO PROJETO da Omie (`OMIE_PROJECT_CODES`). A Santa Origem
 *    classifica cada pedido num Projeto do cadastro da Omie — "Venda" e
 *    "Suvinil", hoje. É campo ESTRUTURADO, escolhido numa lista: não depende de
 *    ninguém lembrar de digitar nada, não erra por acento nem por caixa, e não
 *    some quando alguém reescreve a observação. Use `welog:omie:projetos` para
 *    ver os códigos.
 *
 * 2. Texto na observação (`OMIE_PROJECT_MARKER`), só quando o item 1 não está
 *    configurado. Fica como escada de saída para uma conta que ainda não use o
 *    cadastro de Projetos — mas marcador por texto livre erra: quem digita pode
 *    escrever "Projeto WeLog", "projeto welog" ou "Kit onboarding Wegift". Daí
 *    a comparação ignorar caixa e acento, e aceitar mais de um termo.
 *
 * Os dois são EXCLUSIVOS de propósito. Somá-los com "ou" faria um pedido do
 * projeto Suvinil entrar na esteira só por trazer a palavra certa na
 * observação — que é exatamente o vazamento que o item 1 veio fechar.
 */
class OmieProjectMarker
{
    /**
     * Sem NENHUM dos dois configurado, nada é filtrado — o comportamento
     * continua o de hoje. É a escolha menos destrutiva das duas: um filtro que
     * entra em vigor sozinho, com valor vazio, pararia de criar Projeto de
     * verdade sem ninguém perceber. A pendência aparece no
     * `welog:projects:audit`.
     */
    public function configured(?string $account = null): bool
    {
        return $this->projectCodes($account) !== [] || $this->termos($account) !== [];
    }

    /** Qual critério está valendo — o `welog:projects:audit` reporta isso. */
    public function criterio(?string $account = null): string
    {
        return match (true) {
            $this->projectCodes($account) !== [] => 'projeto',
            $this->termos($account) !== [] => 'observacao',
            default => 'nenhum',
        };
    }

    /** @param array<string, mixed> $order pedido da Omie (ConsultarPedido ou ListarPedidos) */
    public function matches(array $order, ?string $account = null): bool
    {
        if ($this->projectCodes($account) !== []) {
            return in_array($this->projectCodeOf($order), $this->projectCodes($account), true);
        }

        if ($this->termos($account) === []) {
            return true;
        }

        $observacao = $this->normalizar((string) ($order['observacoes']['obs_venda'] ?? ''));

        if ($observacao === '') {
            return false;
        }

        foreach ($this->termos($account) as $termo) {
            if (str_contains($observacao, $this->normalizar($termo))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Código do Projeto Omie gravado no pedido.
     *
     * Mora em `informacoes_adicionais.codProj` no `ConsultarPedido`. O
     * `ListarPedidos` devolve a mesma estrutura aninhada, então um caminho só
     * atende os dois. `0` e vazio significam "sem projeto" e viram string
     * vazia — que nunca casa com um código configurado, e é o que faz um pedido
     * sem classificação ficar de fora em vez de entrar por omissão.
     *
     * @param  array<string, mixed>  $order
     */
    public function projectCodeOf(array $order): string
    {
        $codigo = $order['informacoes_adicionais']['codProj'] ?? null;

        return $codigo === null || $codigo === 0 || $codigo === '0' ? '' : (string) $codigo;
    }

    /**
     * Códigos do projeto "Venda" — POR CONTA quando há mais de um CNPJ.
     *
     * Cada CNPJ tem o próprio cadastro de Projetos na Omie, e o mesmo "Venda"
     * ganha um código diferente em cada um. Filtrar o pedido da conta B com o
     * código da conta A reprovaria pedido legítimo em silêncio.
     *
     *   OMIE_WEBOX_PROJECT_CODES=7407717220
     *   OMIE_SANTA_PROJECT_CODES=9911223344
     *
     * Sem a variável da conta, vale o `OMIE_PROJECT_CODES` global.
     *
     * @return array<int, string>
     */
    public function projectCodes(?string $account = null): array
    {
        // Código com template de card entra na lista de aceitos sem precisar
        // ser repetido em `PROJECT_CODES`: configurar nos dois lugares é onde
        // nasceriam os erros simétricos — aceitar um pedido sem saber que card
        // criar, ou ter template para um projeto que nem entra na esteira.
        return array_values(array_unique(array_merge(
            $this->lista($this->porConta($account, 'PROJECT_CODES', 'project_codes')),
            // `strval` porque chave numérica de array vira INT em PHP, e a
            // comparação com o código do pedido é estrita: '7828126392' não é
            // 7828126392, e o pedido seria recusado em silêncio.
            array_map('strval', array_keys(ProjectTemplate::mapa())),
        )));
    }

    /** Template do card para este pedido, pelo projeto em que foi classificado. */
    public function templateFor(array $order): ?ProjectTemplate
    {
        return ProjectTemplate::forOmieProjectCode($this->projectCodeOf($order));
    }

    /** @return array<int, string> */
    public function termos(?string $account = null): array
    {
        return $this->lista($this->porConta($account, 'PROJECT_MARKER', 'project_marker'));
    }

    /** Valor da conta (OMIE_<CONTA>_<SUFIXO>), com o global como padrão. */
    private function porConta(?string $account, string $sufixo, string $chaveGlobal): string
    {
        $global = (string) config("integrations.omie.{$chaveGlobal}", '');

        if ($account === null || $account === '' || $account === OmieAccounts::DEFAULT) {
            return $global;
        }

        $daConta = env('OMIE_'.strtoupper(str_replace('-', '_', $account)).'_'.$sufixo);

        return (string) ($daConta ?: $global);
    }

    /** @return array<int, string> */
    private function lista(string $valor): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $valor))));
    }

    /** Caixa e acento não podem decidir se um pedido vira Projeto. */
    private function normalizar(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));

        return strtr($valor, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c',
        ]);
    }
}
