<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Cancela um resgate/solicitação ainda não expedido e devolve o saldo.
 *
 * Pedido do cliente no UAT: a baixa de estoque acontece na solicitação; se o
 * resgate não for concluído, o saldo precisa voltar — na plataforma E na Omie.
 *
 * O corte é a EXPEDIÇÃO: depois que o kit saiu (shipped/delivered), cancelar o
 * registro não devolve caixa nenhuma à prateleira — esse caso é logística
 * reversa, tratado fora do sistema. Cancelado, o modelo já reabre sozinho a
 * autorização e o grupo de escolha (`isRedeemed`/`choiceGroupConsumed` ignoram
 * cancelados): a pessoa pode escolher de novo.
 */
class CancelRedemption
{
    public function __construct(private readonly ProjectStockLedger $ledger) {}

    public function handle(Redemption $redemption, User $actor): Redemption
    {
        if ($redemption->status === Redemption::STATUS_CANCELED) {
            throw new DomainException('Este resgate já está cancelado.');
        }

        if ($redemption->status !== Redemption::STATUS_CONFIRMED) {
            throw new DomainException(
                'Este resgate já foi expedido e não pode ser cancelado pelo sistema. '
                    .'Para devolução, trate a logística reversa e ajuste o estoque na Omie.',
            );
        }

        $redemption->update(['status' => Redemption::STATUS_CANCELED]);

        // Devolução ao saldo do PROJETO, na hora e no mesmo lugar onde a baixa
        // aconteceu. Era um job assíncrono porque o crédito ia para a Omie;
        // local, esperar não tem sentido — quem cancelou precisa ver o saldo
        // de volta na tela que já está aberta.
        $this->ledger->devolver($redemption);

        Log::info('Resgate cancelado; saldo devolvido ao Projeto.', [
            'redemption_id' => $redemption->id,
            'project_id' => $redemption->project_id,
            'product_id' => $redemption->product_id,
            'quantity' => $redemption->quantity,
            'user_id' => $actor->id,
        ]);

        return $redemption->refresh();
    }
}
