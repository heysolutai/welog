<?php

namespace Tests\Unit;

use App\Models\Redemption;
use Tests\TestCase;

/**
 * Formatação de endereço do resgate (Fluxo A, Módulo 8).
 *
 * A distinção "manual × automático" saiu daqui junto com as constantes de
 * transportadora: quem é integrada passou a ser propriedade do cadastro
 * (`carriers`), e está coberta em CarrierShippingTest.
 */
class RedemptionTest extends TestCase
{
    public function test_endereco_resumido(): void
    {
        $r = new Redemption;
        $r->street = 'Rua XV de Novembro';
        $r->number = '100';
        $r->city = 'Curitiba';
        $r->state = 'PR';

        $this->assertSame('Rua XV de Novembro, 100 - Curitiba/PR', $r->short_address);
    }

    public function test_endereco_completo_inclui_complemento_e_cep_quando_houver(): void
    {
        $r = new Redemption;
        $r->street = 'Rua XV';
        $r->number = '100';
        $r->complement = 'Sala 3';
        $r->district = 'Centro';
        $r->city = 'Curitiba';
        $r->state = 'PR';
        $r->zip = '80010-000';

        $completo = $r->full_address;
        $this->assertStringContainsString('Sala 3', $completo);
        $this->assertStringContainsString('Centro', $completo);
        $this->assertStringContainsString('CEP 80010-000', $completo);
    }

    public function test_endereco_completo_omite_complemento_e_cep_vazios(): void
    {
        $r = new Redemption;
        $r->street = 'Rua XV';
        $r->number = '100';
        $r->district = 'Centro';
        $r->city = 'Curitiba';
        $r->state = 'PR';

        $this->assertSame('Rua XV, 100, Centro, Curitiba/PR', $r->full_address);
    }
}
