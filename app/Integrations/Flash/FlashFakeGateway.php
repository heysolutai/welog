<?php

namespace App\Integrations\Flash;

use App\Contracts\Integrations\FlashGateway;
use App\Integrations\Support\FakeState;
use App\Integrations\Support\TrackingStatus;
use Illuminate\Support\Carbon;

/**
 * Simulação da Flash (driver 'fake').
 *
 * A primeira consulta de uma referência "coleta" a encomenda; cada consulta
 * seguinte avança um estágio: coletado → em trânsito → entregue. Três rodadas
 * do PollFlashTracking (ou do `welog:flash:rastrear`) levam um pedido do
 * faturamento à entrega.
 */
class FlashFakeGateway implements FlashGateway
{
    private FakeState $state;

    public function __construct()
    {
        $this->state = new FakeState('flash');
    }

    /** Guarda o lote, como a importação de verdade: reenvio do mesmo número é ignorado. */
    public function importShipments(array $encomendas): array
    {
        $this->state->update(function (array &$state) use ($encomendas) {
            foreach ($encomendas as $encomenda) {
                $state['importadas'][$encomenda['num_enc_cli']] ??= $encomenda;
            }

            return null;
        });

        return ['mensagem' => 'Operacao realizada com sucesso.', 'arquivo' => null];
    }

    public function corpoDaImportacao(array $encomendas): string
    {
        return json_encode(array_values($encomendas), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, array<string, mixed>> as encomendas importadas, por número */
    public function importadas(): array
    {
        return $this->state->get()['importadas'] ?? [];
    }

    public function getTrackingMany(array $references): array
    {
        $resultado = [];

        foreach ($references as $ref) {
            $resultado[$ref] = $this->getTracking($ref);
        }

        return $resultado;
    }

    public function getTracking(string $reference): ?array
    {
        return $this->state->update(function (array &$state) use ($reference) {
            $state['shipments'][$reference] ??= [
                'tracking_code' => 'FL'.strtoupper(substr(hash('crc32b', $reference), 0, 8)),
                'polls' => 0,
                'first_seen_at' => now()->toIso8601String(),
            ];

            // Cada consulta avança um estágio, simulando o progresso real
            $state['shipments'][$reference]['polls']++;
            $shipment = $state['shipments'][$reference];

            // Vocabulário canônico (TrackingStatus), igual ao que o driver
            // `api` produz depois de normalizar a resposta da Flash.
            $timeline = [
                ['status' => TrackingStatus::Posted->value, 'description' => 'Postado', 'location' => 'SAO PAULO'],
                ['status' => TrackingStatus::InTransit->value, 'description' => 'Enviado para unidade entregadora', 'location' => 'SAO PAULO'],
                ['status' => TrackingStatus::Delivered->value, 'description' => 'Entrega efetuada com sucesso', 'location' => 'CURITIBA'],
            ];

            // Datas FIXAS a partir da primeira consulta: o mesmo evento volta
            // com o mesmo horário em toda rodada, como na API de verdade —
            // senão o firstOrCreate do job gravaria o evento de novo a cada vez.
            $inicio = Carbon::parse($shipment['first_seen_at']);
            $reached = min($shipment['polls'], count($timeline));
            $events = [];

            for ($i = 0; $i < $reached; $i++) {
                $events[] = $timeline[$i] + [
                    'occurred_at' => $inicio->copy()->addMinutes($i * 30)->toIso8601String(),
                ];
            }

            return ['tracking_code' => $shipment['tracking_code'], 'events' => $events];
        });
    }
}
