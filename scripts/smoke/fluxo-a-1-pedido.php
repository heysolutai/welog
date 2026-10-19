<?php

/**
 * Smoke test Fluxo A — passo 1: registra um Pedido de Venda no fake da Omie.
 * Uso: php artisan tinker scripts/smoke/fluxo-a-1-pedido.php
 * Em seguida, dispare o webhook (ver scripts/smoke/README.md).
 */

use App\Contracts\Integrations\OmieGateway;

$order = app(OmieGateway::class)->createOrder([
    'codigo_pedido_integracao' => 'smoke-a-'.time(),
    'cabecalho' => [
        'numero_pedido' => 'PV-SMOKE-1',
        'codigo_cliente' => 'CLI-SMOKE',
        'data_previsao' => now()->addDays(20)->toDateString(),
    ],
    'cliente' => ['cnpj' => '11222333000181', 'razao_social' => 'Empresa Demo LTDA'],
    'det' => [
        ['produto' => ['codigo' => 'KIT-ONBOARD-01', 'quantidade' => 25]],
    ],
]);

echo "OMIE_ORDER_ID={$order['omie_order_id']}\n";
