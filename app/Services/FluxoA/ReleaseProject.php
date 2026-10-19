<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Jobs\FluxoA\NotifyProjectRelease;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo A, etapas 8-9: com estoque disponível, o Projeto é liberado e os
 * Usuários cadastrados são notificados por e-mail.
 *
 * Quem libera: só a equipe WeGift (admin/N1). O Admin Cliente cadastra as
 * pessoas e acompanha; a abertura do resgate é autorização da WeGift.
 */
class ReleaseProject
{
    public function handle(Project $project, User $releasedBy): void
    {
        if (! self::canRelease($releasedBy, $project)) {
            throw new DomainException('Este perfil não tem permissão para liberar Projetos.');
        }

        if ($project->isReleased()) {
            throw new DomainException('Projeto já foi liberado.');
        }

        if (! $project->isInStock()) {
            throw new DomainException(
                'O Projeto ainda não chegou ao estágio "Em Estoque": é dele que a liberação depende.',
            );
        }

        $this->garantirSaldoEspelhado($project);

        /*
         * O saldo do Projeto precisa existir ANTES de o portal abrir.
         *
         * Ele nasce no sinal de estoque da Omie, mas nem todo Projeto chega
         * por lá: o estágio pode ter sido ajustado à mão, ou a consulta à Omie
         * pode ter falhado naquele instante. Sem o número, a tela mostra zero e
         * o primeiro resgate é recusado por falta de saldo — num Projeto que
         * acabou de ser liberado justamente porque o kit existe.
         *
         * Idempotente: item já carregado não é tocado, então isto não desfaz
         * as baixas de resgates anteriores.
         */
        $project->loadMissing('products');
        $project->carregarEstoqueDoProjeto();

        // A liberação é travada: sem o lock, dois cliques simultâneos passam
        // pela verificação e disparam o e-mail duas vezes para todo o Projeto.
        DB::transaction(function () use ($project, $releasedBy) {
            $locked = Project::lockForUpdate()->findOrFail($project->id);

            if ($locked->isReleased()) {
                throw new DomainException('Projeto já foi liberado.');
            }

            $locked->update([
                'released_at' => now(),
                'released_by' => $releasedBy->id,
            ]);
        });

        $project->refresh();

        /*
         * O anúncio sai daqui: vai para a fila, em lotes.
         *
         * Ele já foi um laço nesta requisição, uma volta por autorização, e
         * cada volta gera um token de primeiro acesso — um bcrypt de uns 60ms.
         * Numa campanha de nove mil pessoas são mais de nove minutos numa
         * requisição que o nginx corta aos cinco: o operador via 504, metade
         * recebia e-mail, e o `notified_at` gravado de uma vez para todos
         * dentro da transação escondia quem tinha ficado para trás.
         *
         * Depois do commit, nunca dentro dele: enfileirado antes, o worker
         * poderia pegar o job com o Projeto ainda não liberado.
         */
        NotifyProjectRelease::dispatch($project->id);
    }

    /**
     * Regra de negócio: a partir de "Em Estoque" o saldo da plataforma e o da
     * Omie andam juntos — o espelho local (products.stock_qty, alimentado pelo
     * relatório em lote da Omie) é o saldo oficial da esteira. A liberação é o
     * portão que abre o CONSUMO desse saldo (cada resgate vira uma entrega),
     * por isso ela exige saldo real no espelho para TODOS os itens do Projeto.
     * Estágio posicionado à mão não basta: sem esta trava, mover o Projeto
     * manualmente para "Em Estoque" habilitava a liberação sem kit no estoque
     * (falha do UAT no projeto 68366). A baixa do saldo na Omie a cada resgate
     * é a outra metade desta regra e vive fora deste serviço.
     */
    private function garantirSaldoEspelhado(Project $project): void
    {
        // Consulta fresca (não a relação já carregada): a mudança manual de
        // estágio acabou de ressincronizar o espelho, e é esse número que vale.
        // `stock_qty` nasce 0 (NOT NULL) — quem diz "nunca sincronizado" é o
        // `stock_synced_at` nulo, e sem sincronização não há base para liberar.
        $semSaldo = $project->products()->get()->filter(
            fn (Product $item) => $item->stock_synced_at === null
                || (float) $item->stock_qty < (float) $item->pivot->quantity,
        );

        if ($semSaldo->isEmpty()) {
            return;
        }

        $itens = $semSaldo->map(function (Product $item) {
            $precisa = self::formatarQuantidade((float) $item->pivot->quantity);

            // Nunca sincronizado: dizer "ha 0" esconderia que o problema é de
            // sincronização com a Omie, não de kit faltando.
            if ($item->stock_synced_at === null) {
                return $item->name.' (precisa '.$precisa.', estoque nunca sincronizado)';
            }

            return $item->name.' (precisa '.$precisa.', há '
                .self::formatarQuantidade((float) $item->stock_qty).')';
        })->implode('; ');

        throw new DomainException(
            'Sem saldo em estoque para liberar: '.$itens
                .'. Sincronize o estoque ou aguarde a produção.',
        );
    }

    /** Quantidade legível em pt-BR: inteiros secos, decimais com vírgula. */
    private static function formatarQuantidade(float $quantidade): string
    {
        return rtrim(rtrim(number_format($quantidade, 2, ',', '.'), '0'), ',');
    }

    /**
     * Só a equipe WeGift (admin/N1) libera — é a dupla trava contratual: o
     * estágio diz que o kit existe, a liberação diz quem da WeGift autorizou.
     * O Admin Cliente cadastra as pessoas e acompanha; não abre o resgate.
     */
    public static function canRelease(User $user, Project $project): bool
    {
        return $user->canPerformCriticalActions();
    }
}
