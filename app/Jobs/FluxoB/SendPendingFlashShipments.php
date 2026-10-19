<?php

namespace App\Jobs\FluxoB;

use App\Jobs\Concerns\IntegrationJob;
use App\Services\FluxoB\SendShipmentsToFlash;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envia à Flash todo resgate com NF emitida que ainda não foi.
 *
 * Disparado quando a nota chega (webhook, botão ou varredura) e, de reserva,
 * a cada 10 minutos. Único na fila: várias notas chegando juntas viram um
 * envio só, em lote.
 */
class SendPendingFlashShipments implements ShouldBeUnique, ShouldQueue
{
    use IntegrationJob, Queueable;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(SendShipmentsToFlash $envio): void
    {
        if (! config('integrations.flash.import.enabled')) {
            return;
        }

        $envio->handle(SendShipmentsToFlash::pendentes()->get());
    }
}
