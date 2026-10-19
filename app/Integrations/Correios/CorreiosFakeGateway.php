<?php

namespace App\Integrations\Correios;

use App\Contracts\Integrations\CorreiosGateway;
use App\Integrations\Support\FakeState;
use App\Integrations\Support\TrackingStatus;

/**
 * Simulação dos Correios (driver 'fake'). Prepostagem gera código BR válido
 * em formato e o rastreio evolui a cada consulta, como no FlashFakeGateway.
 */
class CorreiosFakeGateway implements CorreiosGateway
{
    private FakeState $state;

    public function __construct()
    {
        $this->state = new FakeState('correios');
    }

    public function createPrePostagem(array $payload): array
    {
        return $this->state->update(function (array &$state) {
            $seq = ($state['seq'] ?? 0) + 1;
            $state['seq'] = $seq;

            $code = 'BR'.str_pad((string) $seq, 9, '0', STR_PAD_LEFT).'WL';
            $state['objects'][$code] = ['polls' => 0];

            return ['tracking_code' => $code];
        });
    }

    public function track(string $trackingCode): array
    {
        return $this->state->update(function (array &$state) use ($trackingCode) {
            $state['objects'][$trackingCode] ??= ['polls' => 0];
            $state['objects'][$trackingCode]['polls']++;
            $polls = $state['objects'][$trackingCode]['polls'];

            // Mesmo vocabulário canônico que o driver `api` produz ao
            // normalizar os códigos SRO — fake e api não podem divergir.
            $timeline = [
                ['status' => TrackingStatus::Posted->value, 'description' => 'Objeto postado', 'location' => 'Curitiba/PR'],
                ['status' => TrackingStatus::InTransit->value, 'description' => 'Objeto em transferência', 'location' => 'São Paulo/SP'],
                ['status' => TrackingStatus::OutForDelivery->value, 'description' => 'Objeto saiu para entrega', 'location' => null],
                ['status' => TrackingStatus::Delivered->value, 'description' => 'Objeto entregue ao destinatário', 'location' => null],
            ];

            $reached = min($polls, count($timeline));
            $events = [];
            for ($i = 0; $i < $reached; $i++) {
                $events[] = array_merge($timeline[$i], [
                    'occurred_at' => now()->subMinutes((count($timeline) - $i) * 45)->toIso8601String(),
                ]);
            }

            return $events;
        });
    }
}
