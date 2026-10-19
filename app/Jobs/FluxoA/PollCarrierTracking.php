<?php

namespace App\Jobs\FluxoA;

use App\Contracts\Integrations\CorreiosGateway;
use App\Integrations\Support\TrackingStatus;
use App\Jobs\Concerns\IntegrationJob;
use App\Mail\NotificaColaborador;
use App\Mail\ShipmentUpdateMail;
use App\Models\EmailTemplate;
use App\Models\Redemption;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rastreabilidade (módulo 9, Fluxo A): consulta periódica dos Correios para
 * resgates expedidos e não entregues. MRB/DDM não têm API — o operador
 * atualiza a entrega manualmente na tela do resgate.
 */
class PollCarrierTracking implements ShouldBeUnique, ShouldQueue
{
    use IntegrationJob, Queueable;

    /** Agendado a cada 2h; uma varredura lenta não pode empilhar com a seguinte. */
    public int $uniqueFor = 7200;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(CorreiosGateway $correios): void
    {
        $redemptions = Redemption::query()
            ->with('user')
            ->where('status', Redemption::STATUS_SHIPPED)
            ->where('carrier', Redemption::CARRIER_CORREIOS)
            ->whereNotNull('tracking_code')
            ->get();

        foreach ($redemptions as $redemption) {
            try {
                $this->sync($correios, $redemption);
            } catch (Throwable $e) {
                // Isolado por item: um código de rastreio inválido não pode
                // interromper o polling — e o e-mail — dos demais resgates.
                Log::channel('integrations')->error('Falha ao consultar rastreio nos Correios.', [
                    'redemption_id' => $redemption->id,
                    'tracking_code' => $redemption->tracking_code,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sync(CorreiosGateway $correios, Redemption $redemption): void
    {
        foreach ($correios->track($redemption->tracking_code) as $event) {
            $redemption->trackingEvents()->firstOrCreate(
                [
                    'occurred_at' => Carbon::parse($event['occurred_at']),
                    'status' => $event['status'],
                ],
                [
                    'description' => $event['description'],
                    'location' => $event['location'] ?? null,
                ],
            );

            $delivered = TrackingStatus::tryFrom($event['status'])?->isDelivered() ?? false;

            if ($delivered && $redemption->status !== Redemption::STATUS_DELIVERED) {
                $redemption->update([
                    'status' => Redemption::STATUS_DELIVERED,
                    'delivered_at' => Carbon::parse($event['occurred_at']),
                ]);

                NotificaColaborador::enfileirar($redemption->user, new ShipmentUpdateMail(
                    $redemption,
                    'Pedido entregue!',
                    'Seu presente foi entregue. Esperamos que aproveite!',
                    EmailTemplate::KEY_REDEMPTION_DELIVERED,
                ));
            }
        }
    }
}
