<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use App\Services\FluxoA\ShipRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Transportadora é cadastro, e cada uma diz como entrega o rastreio.
 *
 * Eram três escritas no código, em quatro lugares diferentes: constante no
 * modelo, `in:correios,mrb,ddm` na validação, mapa de rótulos no front e lista
 * de "sem API" no componente de expedição. Contratar a quarta exigia acertar
 * os quatro — e errar um fazia ela aparecer na tela e ser recusada ao expedir.
 *
 * O que estes testes fixam é a regra nova: quem entrega CÓDIGO pede código,
 * quem entrega LINK pede a URL, e o campo que sobra não é gravado.
 */
class CarrierShippingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $kit = Product::create(['omie_code' => 'KIT-01', 'name' => 'Kit']);

        $this->project = Project::create([
            'company_id' => $empresa->id,
            'name' => '70001 - ACME',
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $this->project->products()->attach($kit->id, ['quantity' => 10]);

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function resgate(): Redemption
    {
        $pessoa = User::factory()->create(['role' => User::ROLE_END_CUSTOMER]);

        return Redemption::create([
            'project_id' => $this->project->id,
            'user_id' => $pessoa->id,
            'product_id' => $this->project->products()->value('products.id'),
            'status' => Redemption::STATUS_CONFIRMED,
            'recipient_name' => 'Bruno',
            'cpf' => '52998224725',
            'zip' => '80010000', 'street' => 'Rua XV', 'number' => '10',
            'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);
    }

    /** As três que existiam no código continuam existindo, agora como linhas. */
    public function test_a_migracao_traz_as_transportadoras_que_ja_operavam(): void
    {
        $this->assertSame(
            ['Correios', 'DDM Log', 'MRB'],
            Carrier::orderBy('name')->pluck('name')->all(),
        );

        $this->assertTrue(Carrier::where('slug', 'correios')->value('active'));
    }

    public function test_transportadora_de_link_grava_a_url_e_nao_o_codigo(): void
    {
        $carrier = Carrier::create([
            'name' => 'Jadlog',
            'slug' => 'jadlog',
            'tracking_field' => Carrier::FIELD_LINK,
        ]);

        $resgate = $this->resgate();

        $this->actingAs($this->operador)
            ->post(route('redemptions.ship', $resgate), [
                'carrier' => $carrier->slug,
                'tracking_url' => 'https://rastreio.jadlog.com.br/AB123',
            ])
            ->assertSessionHasNoErrors();

        $resgate->refresh();

        $this->assertSame('jadlog', $resgate->carrier);
        $this->assertSame('https://rastreio.jadlog.com.br/AB123', $resgate->tracking_url);
        $this->assertNull($resgate->tracking_code);
        $this->assertSame(Redemption::STATUS_SHIPPED, $resgate->status);
        $this->assertSame('https://rastreio.jadlog.com.br/AB123', $resgate->trackingLink());
    }

    /** Sem o link, a transportadora de link não tem como ser acompanhada. */
    public function test_transportadora_de_link_exige_o_link(): void
    {
        Carrier::create(['name' => 'Jadlog', 'slug' => 'jadlog', 'tracking_field' => Carrier::FIELD_LINK]);

        $resgate = $this->resgate();

        $this->actingAs($this->operador)
            ->post(route('redemptions.ship', $resgate), ['carrier' => 'jadlog'])
            ->assertSessionHasErrors('ship');

        $this->assertSame(Redemption::STATUS_CONFIRMED, $resgate->fresh()->status);
    }

    /** Transportadora de código sem API: o código é a única forma de acompanhar. */
    public function test_transportadora_de_codigo_exige_o_codigo(): void
    {
        $resgate = $this->resgate();

        $this->actingAs($this->operador)
            ->post(route('redemptions.ship', $resgate), ['carrier' => 'mrb'])
            ->assertSessionHasErrors('ship');

        $this->assertSame(Redemption::STATUS_CONFIRMED, $resgate->fresh()->status);
    }

    /**
     * Correios sem prepostagem é o único caso em que expedir sem código é
     * legítimo: o robô de rastreio descobre depois.
     */
    public function test_correios_expede_sem_codigo(): void
    {
        config(['integrations.correios.prepostagem_enabled' => false]);

        $resgate = $this->resgate();

        $this->actingAs($this->operador)
            ->post(route('redemptions.ship', $resgate), ['carrier' => 'correios'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Redemption::STATUS_SHIPPED, $resgate->fresh()->status);
    }

    /** O molde do cadastro transforma o código em link de acompanhamento. */
    public function test_o_molde_monta_o_link_a_partir_do_codigo(): void
    {
        $resgate = $this->resgate();

        $this->actingAs($this->operador)
            ->post(route('redemptions.ship', $resgate), [
                'carrier' => 'mrb',
                'tracking_code' => 'MRB123456789BR',
            ])
            ->assertSessionHasNoErrors();

        // MRB nasce sem molde: o código existe e não vira link.
        $this->assertNull($resgate->fresh()->trackingLink());

        Carrier::where('slug', 'mrb')->first()
            ->update(['tracking_url_template' => 'https://mrb.com.br/rastreio?cod={codigo}']);

        $this->assertSame(
            'https://mrb.com.br/rastreio?cod=MRB123456789BR',
            $resgate->fresh()->trackingLink(),
        );
    }

    /** Desativada some da expedição, e o servidor recusa quem insistir. */
    public function test_transportadora_desativada_nao_expede(): void
    {
        Carrier::where('slug', 'mrb')->update(['active' => false]);

        $resgate = $this->resgate();

        $this->actingAs($this->operador)
            ->post(route('redemptions.ship', $resgate), [
                'carrier' => 'mrb',
                'tracking_code' => 'MRB123',
            ])
            ->assertSessionHasErrors('carrier');
    }

    public function test_transportadora_inexistente_e_recusada(): void
    {
        $resgate = $this->resgate();

        $this->actingAs($this->operador)
            ->post(route('redemptions.ship', $resgate), [
                'carrier' => 'nao-existe',
                'tracking_code' => 'X1',
            ])
            ->assertSessionHasErrors('carrier');
    }

    /**
     * O serviço recusa a transportadora desativada mesmo chamado direto: a
     * validação do controller é a primeira porta, não a única.
     */
    public function test_o_servico_recusa_transportadora_desativada(): void
    {
        Carrier::where('slug', 'ddm')->update(['active' => false]);

        $this->expectException(DomainException::class);

        app(ShipRedemption::class)->handle($this->resgate(), 'ddm', $this->operador, 'DDM1');
    }

    // ------------------------------------------------------------------
    // Cadastro (Admin WeGift)
    // ------------------------------------------------------------------

    public function test_admin_cadastra_transportadora_e_o_slug_nasce_do_nome(): void
    {
        $admin = User::where('email', 'admin@welog.local')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.carriers.store'), [
                'name' => 'Loggi Express',
                'tracking_field' => Carrier::FIELD_LINK,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('carriers', [
            'name' => 'Loggi Express',
            'slug' => 'loggi-express',
            'tracking_field' => Carrier::FIELD_LINK,
            // Transportadora de link não guarda molde: não há código a encaixar.
            'tracking_url_template' => null,
        ]);
    }

    /** O molde precisa dizer onde o código entra, senão o link é o mesmo para todos. */
    public function test_molde_sem_marcador_de_codigo_e_recusado(): void
    {
        $admin = User::where('email', 'admin@welog.local')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.carriers.store'), [
                'name' => 'Sem Marcador',
                'tracking_field' => Carrier::FIELD_CODE,
                'tracking_url_template' => 'https://exemplo.com.br/rastreio',
            ])
            ->assertSessionHasErrors('tracking_url_template');
    }

    /** Já expediu: apagar deixaria o histórico sem dizer por quem o kit foi. */
    public function test_transportadora_com_expedicao_nao_e_removida(): void
    {
        $resgate = $this->resgate();

        $this->actingAs($this->operador)
            ->post(route('redemptions.ship', $resgate), ['carrier' => 'mrb', 'tracking_code' => 'MRB1']);

        $mrb = Carrier::where('slug', 'mrb')->firstOrFail();

        $this->actingAs(User::where('email', 'admin@welog.local')->firstOrFail())
            ->delete(route('admin.carriers.destroy', $mrb))
            ->assertSessionHasErrors('carrier');

        $this->assertDatabaseHas('carriers', ['slug' => 'mrb']);
    }

    public function test_n1_nao_acessa_o_cadastro_de_transportadoras(): void
    {
        $this->actingAs($this->operador)
            ->get(route('admin.carriers.index'))
            ->assertForbidden();
    }
}
