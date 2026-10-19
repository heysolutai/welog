<?php

namespace App\Support\Projects;

use App\Models\Project;

/**
 * Tipo de campanha, vindo do PROJETO cadastrado na Omie ("Welog - Evento",
 * "Welog - Com estoque"…), e o template do card que ele gera no Trello.
 *
 * O quadro da operação já usava esses cards à mão, com prefixo e cor fixos por
 * tipo. Aqui eles viram regra: o pedido chega classificado num projeto da
 * Omie, o de-para (`integrations.omie.project_templates`) diz o tipo, e o card
 * nasce no formato que a produção já lê — em vez de alguém renomear card a
 * card e às vezes esquecer.
 *
 * O de-para é por CÓDIGO, não por nome: o nome do projeto na Omie é editável e
 * já mudou uma vez ("Welog" virou "Welog - ..."), enquanto o código é imutável.
 */
enum ProjectTemplate: string
{
    case Evento = 'evento';
    case ComEstoque = 'com_estoque';
    case Amostra = 'amostra';
    case SemEstoque = 'sem_estoque';
    case BoschSemEstoque = 'bosch_sem_estoque';

    /** Nome do tipo na tela do Projeto. */
    public function label(): string
    {
        return match ($this) {
            self::Evento => 'Evento',
            self::ComEstoque => 'Com estoque',
            self::Amostra => 'Amostra',
            self::SemEstoque => 'Sem estoque',
            self::BoschSemEstoque => 'Sem estoque (Pedido Bosch)',
        };
    }

    /**
     * Etiqueta do card, no nome e na cor que o quadro já usa. As cores são as
     * do Trello (`green`, `blue`, `orange`…) e vão como estão para a API.
     *
     * @return array{nome: string, cor: string}
     */
    public function boardLabel(): array
    {
        return match ($this) {
            self::Evento => ['nome' => 'Evento', 'cor' => 'orange'],
            self::ComEstoque => ['nome' => 'COM Estoque', 'cor' => 'blue'],
            self::Amostra => ['nome' => 'Amostra', 'cor' => 'blue'],
            self::SemEstoque => ['nome' => 'SEM Estoque', 'cor' => 'blue'],
            self::BoschSemEstoque => ['nome' => 'SEM Estoque', 'cor' => 'green'],
        };
    }

    /**
     * Título do card no formato do quadro.
     *
     *   [Evento] Empresa - Pedido do cliente
     *   [COM Estoque] Empresa - Pedido do cliente
     *   [Amostra] Empresa - Pedido do cliente
     *   [SEM Estoque][#66906] Empresa - Pedido do cliente
     *   [SEM Estoque] Pedidos Bosch - Setembro
     *
     * Bosch tem formato próprio (um card por pedido, o mês no lugar do
     * cliente) porque no quadro esses pedidos são lidos pelo mês de entrada,
     * não pela empresa — que é sempre a mesma.
     */
    public function cardTitle(Project $project): string
    {
        if ($this === self::BoschSemEstoque) {
            return '[SEM Estoque] Pedidos Bosch - '.$this->mesDoPedido($project);
        }

        $prefixo = '['.$this->boardLabel()['nome'].']';

        if ($this === self::SemEstoque) {
            // Zeros à esquerda como no quadro ([#0001]); pedido mais longo que
            // isso entra inteiro, sem corte.
            $prefixo .= '[#'.str_pad((string) $project->omie_order_number, 4, '0', STR_PAD_LEFT).']';
        }

        return trim($prefixo.' '.$this->corpoDoTitulo($project));
    }

    /**
     * "Empresa - Pedido do cliente". O nome do cliente vem do campo
     * `numero_pedido_cliente` da Omie — é onde a operação escreve como a
     * campanha é chamada. Sem ele, o número do pedido segura o lugar: card sem
     * identidade nenhuma é pior que card com o número.
     */
    private function corpoDoTitulo(Project $project): string
    {
        $empresa = trim((string) ($project->company?->label ?? ''));
        $campanha = trim((string) ($project->client_order_number ?: $project->omie_order_number));

        return trim(implode(' - ', array_filter([$empresa, $campanha])));
    }

    /** Mês de entrada do pedido, por extenso e em pt-BR ("Setembro"). */
    private function mesDoPedido(Project $project): string
    {
        $data = $project->ordered_at ?? $project->created_at ?? now();

        return mb_ucfirst($data->locale('pt_BR')->translatedFormat('F'));
    }

    /**
     * Card MODELO deste tipo no quadro (código curto da URL), ou null quando
     * não há modelo configurado — aí o card é criado do zero, com a etiqueta
     * do tipo pintada pela plataforma.
     */
    public function sourceCardId(): ?string
    {
        $mapa = [];

        foreach (explode(',', (string) config('integrations.trello.card_templates', '')) as $par) {
            [$chave, $card] = array_pad(explode('=', trim($par), 2), 2, null);
            $chave = trim((string) $chave);
            $card = trim((string) $card);

            if ($chave !== '' && $card !== '') {
                $mapa[$chave] = $card;
            }
        }

        return $mapa[$this->value] ?? null;
    }

    /**
     * Template do código do projeto na Omie, ou null quando o código não está
     * mapeado (aí o card mantém o formato antigo, sem prefixo).
     */
    public static function forOmieProjectCode(?string $code): ?self
    {
        if ($code === null || $code === '') {
            return null;
        }

        $chave = self::mapa()[$code] ?? null;

        return $chave === null ? null : self::tryFrom($chave);
    }

    /**
     * De-para `codigo=template`, do `.env` via config.
     *
     * @return array<string, string>
     */
    public static function mapa(): array
    {
        $mapa = [];

        foreach (explode(',', (string) config('integrations.omie.project_templates', '')) as $par) {
            [$codigo, $template] = array_pad(explode('=', trim($par), 2), 2, null);
            $codigo = trim((string) $codigo);
            $template = trim((string) $template);

            if ($codigo !== '' && self::tryFrom($template) !== null) {
                $mapa[$codigo] = $template;
            }
        }

        return $mapa;
    }
}
