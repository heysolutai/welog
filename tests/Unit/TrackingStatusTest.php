<?php

namespace Tests\Unit;

use App\Integrations\Support\TrackingStatus;
use PHPUnit\Framework\TestCase;

/**
 * A normalização é o que faz o e-mail de entrega existir em produção: os
 * gateways reais falam SRO/rótulos próprios, o app fala TrackingStatus.
 */
class TrackingStatusTest extends TestCase
{
    public function test_entrega_dos_correios_e_reconhecida(): void
    {
        foreach (['BDE', 'BDI', 'BDR'] as $codigo) {
            $this->assertTrue(
                TrackingStatus::fromCorreios($codigo, '01')->isDelivered(),
                "{$codigo} tipo 01 deveria ser entrega",
            );
        }
    }

    public function test_tentativa_frustrada_nao_marca_como_entregue(): void
    {
        // BDE com tipo != 01 é "entrega não efetuada" (ausente, endereço errado)
        $status = TrackingStatus::fromCorreios('BDE', '02');

        $this->assertSame(TrackingStatus::DeliveryFailed, $status);
        $this->assertFalse($status->isDelivered());
    }

    public function test_mapeia_demais_eventos_sro(): void
    {
        $this->assertSame(TrackingStatus::Posted, TrackingStatus::fromCorreios('PO'));
        $this->assertSame(TrackingStatus::InTransit, TrackingStatus::fromCorreios('RO'));
        $this->assertSame(TrackingStatus::OutForDelivery, TrackingStatus::fromCorreios('OEC'));
        $this->assertSame(TrackingStatus::Other, TrackingStatus::fromCorreios('XYZ'));
        $this->assertSame(TrackingStatus::Other, TrackingStatus::fromCorreios(null));
    }

    public function test_codigo_sro_e_case_insensitive(): void
    {
        $this->assertTrue(TrackingStatus::fromCorreios('bde', '01')->isDelivered());
        $this->assertTrue(TrackingStatus::fromCorreios(' BDE ', ' 01 ')->isDelivered());
    }

    public function test_flash_aceita_grafias_usuais(): void
    {
        $this->assertTrue(TrackingStatus::fromFlash('entregue')->isDelivered());
        $this->assertTrue(TrackingStatus::fromFlash('DELIVERED')->isDelivered());
        $this->assertSame(TrackingStatus::InTransit, TrackingStatus::fromFlash('in transit'));
        $this->assertSame(TrackingStatus::Posted, TrackingStatus::fromFlash('coletado'));
    }

    public function test_status_desconhecido_da_flash_nunca_vira_entrega(): void
    {
        foreach (['status_novo', '', null, 'devolvido ao remetente'] as $status) {
            $this->assertFalse(
                TrackingStatus::fromFlash($status)->isDelivered(),
                'Status desconhecido não pode fechar o pedido como entregue',
            );
        }
    }
}
