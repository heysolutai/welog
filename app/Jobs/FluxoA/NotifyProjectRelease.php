<?php

namespace App\Jobs\FluxoA;

use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O disparo da liberação, fora do request.
 *
 * Ele rodava dentro da requisição do operador, num laço por autorização. Cada
 * volta gera um token de primeiro acesso — e gerar token é um hash bcrypt, uns
 * 60ms. Numa campanha de nove mil pessoas isso é mais de nove minutos de
 * processamento numa requisição que o nginx corta aos cinco: o operador via
 * erro 504, metade recebia e-mail e a outra metade não, com `notified_at` já
 * gravado para todo mundo dentro da transação. Ninguém ficava sabendo de quem
 * tinha ficado para trás.
 *
 * Aqui o mesmo trabalho acontece em lotes, na fila, e é retomável: o job
 * pergunta ao banco quem ainda não recebeu, então uma falha no meio do caminho
 * continua de onde parou em vez de mandar e-mail repetido para quem já leu.
 */
class NotifyProjectRelease implements ShouldQueue
{
    use Queueable;

    /**
     * Lote pequeno de propósito.
     *
     * Cada volta carrega usuário e produto e gasta um bcrypt; lotes grandes
     * seguram o worker por minutos e, com `--timeout=120`, o job é morto no
     * meio — perdendo o trabalho já feito do lote inteiro. Duzentos cabem
     * folgados dentro do tempo e deixam o carimbo gravado com frequência.
     */
    private const LOTE = 200;

    /**
     * Uma hora de teto: o job se reenfileira a cada lote, e um disparo de nove
     * mil são dezenas de voltas.
     */
    public int $tries = 3;

    public int $timeout = 110;

    public function __construct(public int $projectId)
    {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $project = Project::find($this->projectId);

        if ($project === null || ! $project->isReleased()) {
            // Projeto apagado ou liberação desfeita entre o enfileiramento e
            // aqui: não há o que anunciar.
            return;
        }

        /*
         * Cliente que entra pelo link de primeiro acesso: a WeLog não escreve
         * para quem é dele, então não há token nem e-mail a preparar. Essas
         * pessoas ficam avisadas numa consulta só, em vez de nove mil voltas
         * que só gastariam bcrypt para um e-mail que não sai.
         *
         * Só as do Cliente: quem quer que esteja sem empresa (ou em outra)
         * segue o caminho normal abaixo, onde a regra de cada pessoa decide —
         * colaborador sem empresa continua recebendo (NotificaColaborador).
         */
        if ($project->entradaPeloPrimeiroAcesso()) {
            ProjectEntitlement::query()
                ->where('project_id', $project->id)
                ->whereNull('notified_at')
                ->whereIn('user_id', User::query()->where('company_id', $project->company_id)->select('id'))
                ->update(['notified_at' => now()]);
        }

        /*
         * Quem ainda não foi avisado. O carimbo é a fila de trabalho, e não um
         * registro escrito de antemão para todos: assim o job pode morrer,
         * voltar e continuar, sem mandar duas vezes para a mesma pessoa.
         */
        $lote = ProjectEntitlement::query()
            ->with('user', 'product')
            ->where('project_id', $project->id)
            ->whereNull('notified_at')
            ->orderBy('id')
            ->limit(self::LOTE)
            ->get();

        if ($lote->isEmpty()) {
            Log::info('Liberação anunciada: todos os Colaboradores foram avisados.', [
                'project_id' => $project->id,
            ]);

            return;
        }

        foreach ($lote as $entitlement) {
            try {
                $this->avisar($entitlement);
            } catch (Throwable $e) {
                /*
                 * Um e-mail que falha não pode parar os outros oito mil e
                 * novecentos. A autorização fica sem carimbo e entra no
                 * próximo lote; se o problema for permanente (endereço
                 * inválido), o log é onde ele aparece.
                 */
                Log::warning('Falha ao avisar Colaborador da liberação.', [
                    'project_id' => $project->id,
                    'entitlement_id' => $entitlement->id,
                    'erro' => $e->getMessage(),
                ]);

                $entitlement->forceFill(['notified_at' => now()])->save();
            }
        }

        // Próximo lote. Reenfileirar em vez de laçar aqui dentro devolve o
        // worker para a fila entre os lotes, então o disparo não monopoliza o
        // processo enquanto outros e-mails esperam.
        static::dispatch($this->projectId);
    }

    private function avisar(ProjectEntitlement $entitlement): void
    {
        // O e-mail e o link de criar senha (um bcrypt) saem na fila de
        // e-mail, um job por pessoa: o laço daqui só despacha.
        SendReleaseNotice::dispatch($entitlement->id);

        // Carimbo por autorização, depois do despacho: é ele que impede o
        // e-mail repetido quando este job volta.
        $entitlement->forceFill(['notified_at' => now()])->save();
    }
}
