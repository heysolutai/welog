<?php

namespace Tests\Feature;

use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\PartnerSku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Importações Parceiros como lista de RESGATES (imports.index) e a página de
 * cada um (imports.rows.show).
 *
 * A planilha saiu da operação: os resgates chegam pelo webhook, e a unidade
 * que se procura é o resgate, não o lote. Continua Fluxo B — a tela Resgates
 * do portal não traz estas linhas.
 */
class ImportResgatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function operador(): User
    {
        return User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function consulta(): User
    {
        return User::where('email', 'consulta@welog.local')->firstOrFail();
    }

    private function resgate(string $status = AppnozImportRow::STATUS_DISPATCHED, array $extra = []): AppnozImportRow
    {
        static $linha = 1;

        $import = AppnozImport::firstOrCreate(
            ['file_hash' => 'hash-webhook'],
            [
                'filename' => 'SUV-2026-10-07-001',
                'source' => AppnozImport::SOURCE_WEBHOOK,
                'status' => AppnozImport::STATUS_COMPLETED,
            ],
        );

        return AppnozImportRow::create(array_merge([
            'appnoz_import_id' => $import->id,
            'row_number' => ++$linha,
            'external_id' => '125635',
            'cpf' => '52998224725',
            'customer_name' => 'Bruno Parceiro',
            'email' => 'bruno@suvinil.com.br',
            'product_code' => 'MOLETOM-PRETO-G',
            'product_name' => 'Moletom com Capuz',
            'quantity' => 1,
            'zip' => '01310100', 'street' => 'Av Paulista', 'number' => '1000',
            'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP',
            'status' => $status,
            'omie_order_number' => '9107',
            'tracking_code' => 'FLASH-999',
        ], $extra));
    }

    public function test_lista_cada_resgate_com_a_etapa_e_a_variacao(): void
    {
        PartnerSku::create([
            'source' => PartnerSku::SOURCE_RECONHECE,
            'sku' => 'MOLETOM-PRETO-G',
            'parent_code' => 'MOLETOM',
            'name' => 'Moletom com Capuz',
            'color' => 'Preto',
            'size' => 'G',
        ]);
        $resgate = $this->resgate();

        $this->actingAs($this->operador())
            ->get(route('imports.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Imports/Index')
                ->has('rows.data', 1)
                ->where('rows.data.0.id', $resgate->id)
                ->where('rows.data.0.external_id', '125635')
                ->where('rows.data.0.variacao', 'Preto · G')
                ->where('rows.data.0.tracking_code', 'FLASH-999')
                ->where('contagens.dispatched', 1),
            );
    }

    /** O bloqueado entra na lista: é justamente o que pede ação. */
    public function test_filtro_bloqueados_junta_os_dois_bloqueios(): void
    {
        $this->resgate(AppnozImportRow::STATUS_BLOCKED_PRODUCT, ['omie_order_number' => null]);
        $this->resgate(AppnozImportRow::STATUS_BLOCKED_STRUCTURAL, ['omie_order_number' => null, 'external_id' => '125636', 'cpf' => '11144477735']);
        $this->resgate(AppnozImportRow::STATUS_DELIVERED, ['external_id' => '125637', 'cpf' => '39053344705']);

        $this->actingAs($this->operador())
            ->get(route('imports.index', ['etapa' => 'bloqueados']))
            ->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));

        $this->actingAs($this->operador())
            ->get(route('imports.index', ['etapa' => 'delivered']))
            ->assertInertia(fn (Assert $p) => $p
                ->has('rows.data', 1)
                ->where('rows.data.0.status', 'delivered'),
            );
    }

    public function test_busca_por_cpf_aceita_pontuacao_e_acha_pelo_numero_do_resgate(): void
    {
        $this->resgate();

        $this->actingAs($this->operador())
            ->get(route('imports.index', ['busca' => '529.982.247-25']))
            ->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));

        $this->actingAs($this->operador())
            ->get(route('imports.index', ['busca' => '125635']))
            ->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
    }

    public function test_pagina_do_resgate_traz_o_percurso_inteiro(): void
    {
        $resgate = $this->resgate(AppnozImportRow::STATUS_DISPATCHED, [
            'nf_key' => '35261012345678000190550010000091071000091079',
            'nf_issued_at' => now()->subDay(),
        ]);
        $resgate->trackingEvents()->create([
            'occurred_at' => now(),
            'status' => 'in_transit',
            'description' => 'Em trânsito',
            'location' => 'Curitiba/PR',
        ]);

        $this->actingAs($this->operador())
            ->get(route('imports.rows.show', $resgate))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Imports/Show')
                ->where('resgate.omie_order_number', '9107')
                ->where('resgate.lote', 'SUV-2026-10-07-001')
                // Como a Flash conhece a encomenda: WEBOX + nº da NF sem zeros.
                ->where('resgate.flash_reference', 'WEBOX9107')
                ->has('resgate.events', 1)
                ->where('resgate.events.0.description', 'Em trânsito')
                ->where('permissions.reprocess', false),
            );
    }

    /** Antes da NF a Flash não conhece a encomenda: não há número a mostrar. */
    public function test_sem_nf_nao_mostra_referencia_da_flash(): void
    {
        $resgate = $this->resgate(AppnozImportRow::STATUS_ORDER_CREATED, ['tracking_code' => null]);

        $this->actingAs($this->operador())
            ->get(route('imports.rows.show', $resgate))
            ->assertInertia(fn (Assert $p) => $p->where('resgate.flash_reference', null));
    }

    public function test_reprocessar_so_para_perfil_critico_e_produto_sem_vinculo(): void
    {
        $resgate = $this->resgate(AppnozImportRow::STATUS_BLOCKED_PRODUCT, ['omie_order_number' => null]);

        $this->actingAs($this->operador())
            ->get(route('imports.rows.show', $resgate))
            ->assertInertia(fn (Assert $p) => $p->where('permissions.reprocess', true));

        $this->actingAs($this->consulta())
            ->get(route('imports.rows.show', $resgate))
            ->assertInertia(fn (Assert $p) => $p->where('permissions.reprocess', false));
    }

    /**
     * Resgate parado em "Recebido" (Omie recusou e a fila desistiu): o
     * operador vê o motivo e destrava pelo botão.
     */
    public function test_resgate_parado_sem_pedido_pode_ser_reprocessado(): void
    {
        $resgate = $this->resgate(AppnozImportRow::STATUS_PENDING, [
            'product_code' => 'SUV-PINCEL-01',
            'omie_order_number' => null,
            'tracking_code' => null,
        ]);
        $resgate->import->update(['error' => 'Omie ListarClientes falhou: Consumo redundante detectado.']);

        // Recém-chegado ainda está no processamento normal: sem botão.
        $this->actingAs($this->operador())
            ->get(route('imports.rows.show', $resgate))
            ->assertInertia(fn (Assert $p) => $p->where('permissions.reprocess', false));

        $this->travel(5)->minutes();

        $this->actingAs($this->operador())
            ->get(route('imports.rows.show', $resgate))
            ->assertInertia(fn (Assert $p) => $p
                ->where('permissions.reprocess', true)
                ->where('resgate.erro', 'Omie ListarClientes falhou: Consumo redundante detectado.'),
            );

        $this->actingAs($this->operador())
            ->post(route('imports.rows.reprocess', $resgate))
            ->assertSessionHasNoErrors();

        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $resgate->fresh()->status);
        // Destravado: a recusa antiga sai da tela.
        $this->assertNull($resgate->import->fresh()->error);
    }

    /** A planilha saiu: não há mais envio de arquivo por esta tela. */
    public function test_nao_existe_mais_upload_de_planilha(): void
    {
        $this->actingAs($this->operador())->post('/imports')->assertMethodNotAllowed();
    }

    /** O endereço antigo da visão Envios cai na lista. */
    public function test_endereco_antigo_de_envios_redireciona_para_a_lista(): void
    {
        $this->actingAs($this->operador())->get('/imports/envios')->assertRedirect('/imports');
    }

    /** A separação dos processos: a tela Resgates não traz linhas do parceiro. */
    public function test_tela_resgates_do_portal_nao_traz_resgates_do_parceiro(): void
    {
        $this->resgate();

        $this->actingAs($this->operador())
            ->get(route('redemptions.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Redemptions/Index')
                ->has('redemptions.data', 0)
                ->where('redemptions.total', 0),
            );
    }
}
