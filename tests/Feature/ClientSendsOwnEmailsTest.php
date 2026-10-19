<?php

namespace Tests\Feature;

use App\Mail\AccountInvitationMail;
use App\Mail\ProjectReleasedMail;
use App\Mail\RedemptionConfirmedMail;
use App\Mail\ShipmentUpdateMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\Auth\InviteUser;
use App\Services\FluxoA\RedeemKit;
use App\Services\FluxoA\ReleaseProject;
use App\Services\FluxoA\ShipRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O Cliente grande que fala com o próprio pessoal pela comunicação interna.
 *
 * Ele não quer que a campanha chegue por um remetente que ninguém lá
 * reconhece — e, do lado de quem recebe, e-mail de fornecedor desconhecido
 * pedindo para criar senha é indistinguível de golpe. Desligada a
 * customização, a WeLog cala a comunicação de campanha.
 *
 * O que ela NÃO pode calar é o convite de acesso: sem ele não existe link de
 * criar senha, e o Cliente não tem como gerar um. Desligar tudo deixaria o
 * colaborador sem porta de entrada nenhuma.
 */
class ClientSendsOwnEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    private Product $kit;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->empresa = Company::create(['name' => 'Cliente Grande', 'cnpj' => '12345678000190']);

        $this->kit = Product::create([
            'omie_code' => 'KIT-X', 'name' => 'Kit X',
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);

        $this->projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => 'Campanha interna',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
        ]);
        $this->projeto->products()->attach($this->kit->id, ['quantity' => 10]);

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();
    }

    private function colaborador(): User
    {
        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
        ]);

        ProjectEntitlement::create([
            'project_id' => $this->projeto->id,
            'user_id' => $pessoa->id,
            'product_id' => $this->kit->id,
        ]);

        return $pessoa;
    }

    private function desligar(): void
    {
        $this->empresa->update(['customizations' => ['welog_sends_emails' => false]]);
    }

    // ------------------------------------------------------------------
    // O padrão
    // ------------------------------------------------------------------

    /**
     * Nasce LIGADA, e isso é o oposto do padrão das outras customizações.
     *
     * Com padrão `false`, subir este código calaria a plataforma inteira para
     * todos os Clientes no mesmo instante — sem erro nenhum para investigar,
     * só silêncio.
     */
    public function test_a_customizacao_nasce_ligada(): void
    {
        $this->assertTrue($this->empresa->customizacao('welog_sends_emails'));
        $this->assertArrayHasKey('welog_sends_emails', $this->empresa->customizacoes());
    }

    public function test_ligada_a_liberacao_avisa_todo_mundo(): void
    {
        $this->colaborador();

        app(ReleaseProject::class)->handle($this->projeto, $this->operador);

        Mail::assertQueued(ProjectReleasedMail::class, 1);
    }

    // ------------------------------------------------------------------
    // Desligada
    // ------------------------------------------------------------------

    public function test_desligada_a_liberacao_nao_manda_nada(): void
    {
        $this->colaborador();
        $this->desligar();

        app(ReleaseProject::class)->handle($this->projeto, $this->operador);

        Mail::assertNothingQueued();
    }

    /**
     * O carimbo de avisado é marcado mesmo sem e-mail.
     *
     * Ele é a fila de trabalho do job, não um registro de envio: sem o
     * carimbo, o job voltaria eternamente nas mesmas autorizações procurando
     * quem avisar e nunca chegaria ao fim.
     */
    public function test_desligada_a_liberacao_ainda_conclui(): void
    {
        $this->colaborador();
        $this->desligar();

        app(ReleaseProject::class)->handle($this->projeto, $this->operador);

        $this->assertTrue($this->projeto->fresh()->isReleased());
        $this->assertSame(0, ProjectEntitlement::whereNull('notified_at')->count());
    }

    public function test_desligada_o_resgate_nao_manda_confirmacao(): void
    {
        $pessoa = $this->colaborador();
        $this->projeto->update(['released_at' => now(), 'released_by' => $this->operador->id]);
        $this->desligar();

        Mail::fake();

        app(RedeemKit::class)->handle(
            ProjectEntitlement::where('user_id', $pessoa->id)->firstOrFail(),
            $this->enderecoValido(),
            $pessoa,
        );

        Mail::assertNotQueued(RedemptionConfirmedMail::class);
    }

    public function test_desligada_a_expedicao_nao_avisa_o_colaborador(): void
    {
        $pessoa = $this->colaborador();
        $this->projeto->update(['released_at' => now(), 'released_by' => $this->operador->id]);

        $resgate = app(RedeemKit::class)->handle(
            ProjectEntitlement::where('user_id', $pessoa->id)->firstOrFail(),
            $this->enderecoValido(),
            $pessoa,
        );

        $this->desligar();
        Mail::fake();

        app(ShipRedemption::class)->handle($resgate, 'correios', $this->operador, 'AA123456789BR');

        Mail::assertNotQueued(ShipmentUpdateMail::class);
    }

    /**
     * Desligada, o cadastro NÃO dispara convite.
     *
     * Era o único e-mail que ainda partia daqui sem ninguém ter pedido: nove
     * mil mensagens de um remetente que o pessoal do Cliente não reconhece,
     * exatamente o que desligar o interruptor deveria impedir.
     */
    public function test_desligada_cadastrar_colaborador_nao_manda_convite(): void
    {
        $this->desligar();
        Mail::fake();

        $this->actingAs($this->operador)
            ->post(route('projects.entitlements.store', $this->projeto), [
                'name' => 'Pessoa Nova',
                'email' => 'pessoa.nova@cliente.com.br',
                'product_id' => $this->kit->id,
            ])
            ->assertSessionHasNoErrors();

        Mail::assertNothingQueued();
    }

    /** Ligada, o cadastro convida como sempre. */
    public function test_ligada_cadastrar_colaborador_manda_convite(): void
    {
        Mail::fake();

        $this->actingAs($this->operador)
            ->post(route('projects.entitlements.store', $this->projeto), [
                'name' => 'Pessoa Nova',
                'email' => 'pessoa.nova@cliente.com.br',
                'product_id' => $this->kit->id,
            ])
            ->assertSessionHasNoErrors();

        Mail::assertQueued(AccountInvitationMail::class);
    }

    /**
     * Quem PEDE continua recebendo.
     *
     * É a diferença que sustenta o "nada sai": a WeLog não começa conversa
     * nenhuma, mas responde a quem bateu na porta pelo link que o próprio
     * Cliente publicou. Sem isso não existiria como criar senha.
     */
    public function test_desligada_quem_pede_pelo_link_ainda_recebe(): void
    {
        $pessoa = $this->colaborador();
        $this->desligar();

        Mail::fake();
        app(InviteUser::class)->handle($pessoa);

        Mail::assertQueued(AccountInvitationMail::class);
    }

    /**
     * Colaborador sem Cliente vinculado recebe: a ausência de empresa não é
     * escolha de ninguém, e silenciar por omissão de cadastro deixaria a
     * pessoa sem saber que tem brinde para resgatar.
     */
    public function test_colaborador_sem_empresa_continua_recebendo(): void
    {
        $pessoa = $this->colaborador();
        $pessoa->update(['company_id' => null]);
        $this->desligar();

        app(ReleaseProject::class)->handle($this->projeto, $this->operador);

        Mail::assertQueued(ProjectReleasedMail::class, 1);
    }

    private function enderecoValido(): array
    {
        return [
            'recipient_name' => 'Colaborador Teste',
            'cpf' => '52998224725',
            'zip' => '80010000',
            'street' => 'Rua das Tintas',
            'number' => '45',
            'complement' => '',
            'district' => 'Centro',
            'city' => 'Curitiba',
            'state' => 'PR',
        ];
    }
}
