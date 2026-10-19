<?php

namespace Tests\Unit;

use App\Integrations\Support\OmieDate;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A API real da Omie devolve `dd/mm/aaaa`. `Carbon::parse()` lê isso como
 * `mm/dd/aaaa`: prazo de entrega errado (dia ≤ 12) ou exceção derrubando o
 * webhook (dia > 12). O gateway `fake` usa ISO, então nada aparecia em teste.
 */
class OmieDateTest extends TestCase
{
    public function test_le_data_brasileira_da_api_real(): void
    {
        $date = OmieDate::parse('05/12/2026');

        $this->assertNotNull($date);
        $this->assertSame('2026-12-05', $date->toDateString(), 'Deve ser 5 de dezembro, não 12 de maio');
    }

    public function test_dia_maior_que_12_nao_lanca_excecao(): void
    {
        // Carbon::parse('25/12/2026') explodiria e derrubaria o webhook inteiro
        $this->assertSame('2026-12-25', OmieDate::parse('25/12/2026')?->toDateString());
    }

    public function test_aceita_iso_usado_pelo_gateway_fake(): void
    {
        $this->assertSame('2026-12-05', OmieDate::parse('2026-12-05')?->toDateString());
    }

    public function test_aceita_data_com_hora(): void
    {
        $this->assertSame('2026-12-05', OmieDate::parse('05/12/2026 14:30:00')?->toDateString());
    }

    public function test_vazio_e_invalido_viram_null_sem_quebrar(): void
    {
        foreach ([null, '', '   ', '00/00/0000', 'ontem', '31/02/2026'] as $value) {
            $this->assertNull(OmieDate::parse($value), "Valor '{$value}' deveria virar null");
        }
    }

    public function test_filtro_usa_o_fuso_do_negocio_e_nao_utc(): void
    {
        config(['integrations.business_timezone' => 'America/Sao_Paulo']);

        // 22h em São Paulo = 01h do dia seguinte em UTC; o filtro tem de
        // perguntar pelo dia 5, não pelo 6.
        $instant = Carbon::parse('2026-12-06 01:00:00', 'UTC');

        $this->assertSame('05/12/2026', OmieDate::forFilter($instant));
    }
}
