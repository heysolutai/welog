<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A WeGift corrige o endereço de entrega de um resgate, em qualquer momento.
 *
 * Depois de expedir ela pode já ter combinado a troca com a transportadora:
 * a tela pede confirmação, e o servidor aceita. Só o cancelado fica de fora.
 * O endereço anterior fica no registro.
 */
class RedemptionAddressTest extends TestCase
{
    use RefreshDatabase;

    private const NOVO = [
        'recipient_name' => 'Recepção Matriz',
        'zip' => '01310-100',
        'street' => 'Av. Paulista',
        'number' => '1000',
        'complement' => '5º andar',
        'district' => 'Bela Vista',
        'city' => 'São Paulo',
        'state' => 'sp',
    ];

    private Redemption $resgate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $empresa = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $projeto = Project::create(['company_id' => $empresa->id, 'name' => 'Campanha', 'quantity' => 5]);
        $produto = Product::create(['omie_code' => 'KIT', 'name' => 'Kit']);
        $pessoa = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $empresa->id]);

        $this->resgate = Redemption::create([
            'project_id' => $projeto->id, 'user_id' => $pessoa->id, 'product_id' => $produto->id,
            'status' => Redemption::STATUS_CONFIRMED, 'recipient_name' => 'Ana', 'zip' => '80010000',
            'street' => 'Rua XV', 'number' => '10', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);
    }

    private function wegift(string $email = 'admin@welog.local'): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function alterar(User $ator, array $dados = self::NOVO)
    {
        return $this->actingAs($ator)->patch(route('redemptions.address', $this->resgate), $dados);
    }

    public function test_wegift_altera_o_endereco_antes_de_expedir(): void
    {
        $this->alterar($this->wegift())->assertSessionHasNoErrors()->assertSessionHas('status');

        $r = $this->resgate->fresh();
        $this->assertSame('Av. Paulista', $r->street);
        $this->assertSame('01310100', $r->zip);
        $this->assertSame('SP', $r->state);
        $this->assertSame('Recepção Matriz', $r->recipient_name);

        // O endereço anterior fica no registro.
        $log = AuditLog::where('action', AuditLog::REDEMPTION_ADDRESS_CHANGED)->firstOrFail();
        $this->assertSame('Rua XV', $log->properties['anterior']['street']);
    }

    /** Depois de expedir, a WeGift continua podendo: ela combinou com a transportadora. */
    public function test_wegift_altera_mesmo_depois_de_expedido(): void
    {
        $this->resgate->update(['status' => Redemption::STATUS_SHIPPED, 'carrier' => 'correios', 'tracking_code' => 'BR123']);

        $this->alterar($this->wegift('operador@welog.local'))->assertSessionHasNoErrors();

        $this->assertSame('Av. Paulista', $this->resgate->fresh()->street);
        $this->assertStringContainsString(
            'já expedido',
            AuditLog::where('action', AuditLog::REDEMPTION_ADDRESS_CHANGED)->firstOrFail()->description,
        );
    }

    public function test_cancelado_nao_muda(): void
    {
        $this->resgate->update(['status' => Redemption::STATUS_CANCELED]);

        $this->alterar($this->wegift())->assertSessionHasErrors('address');
        $this->assertSame('Rua XV', $this->resgate->fresh()->street);
    }

    public function test_so_a_wegift_critica_altera(): void
    {
        $adminCliente = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->resgate->project->company_id,
        ]);

        foreach ([$adminCliente, $this->wegift('consulta@welog.local'), $this->resgate->user] as $ator) {
            $this->assertContains($this->alterar($ator)->status(), [403, 302]);
            $this->assertSame('Rua XV', $this->resgate->fresh()->street);
        }
    }

    public function test_endereco_incompleto_e_recusado(): void
    {
        $this->alterar($this->wegift(), ['zip' => '123'] + self::NOVO)->assertSessionHasErrors('zip');
        $this->alterar($this->wegift(), ['street' => ''] + self::NOVO)->assertSessionHasErrors('street');
        $this->alterar($this->wegift(), ['state' => 'SPX'] + self::NOVO)->assertSessionHasErrors('state');

        $this->assertSame('Rua XV', $this->resgate->fresh()->street);
    }

    /** A lista de Resgates traz o endereço campo a campo, para o painel abrir preenchido. */
    public function test_a_lista_traz_o_endereco_para_editar(): void
    {
        $this->actingAs($this->wegift())
            ->get(route('redemptions.index'))
            ->assertInertia(fn ($p) => $p
                ->where('redemptions.data.0.endereco.campos.recipient_name', 'Ana')
                ->where('redemptions.data.0.endereco.campos.zip', '80010-000')
                ->where('redemptions.data.0.endereco.campos.street', 'Rua XV')
                ->etc());
    }
}
