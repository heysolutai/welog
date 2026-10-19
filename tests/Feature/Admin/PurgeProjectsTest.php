<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Limpeza dos Projetos que entraram por omissão.
 *
 * Comando destrutivo e irreversível: o que estes testes protegem não é o
 * "apagou", é o QUE SOBRA — resgate que bloqueia a FK, evento de rastreio
 * órfão (a tabela é polimórfica, sem chave estrangeira), operador apagado por
 * engano e admin cliente com `company_id` anulado.
 */
class PurgeProjectsTest extends TestCase
{
    use RefreshDatabase;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->produto = Product::create(['omie_code' => 'KIT-1', 'name' => 'Kit', 'is_kit' => true]);
    }

    private function projeto(string $nome, ?Company $empresa = null, ?string $pedido = null): Project
    {
        $empresa ??= Company::create(['name' => $nome.' Ltda', 'cnpj' => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT)]);

        $projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => $nome,
            'quantity' => 1,
            'omie_order_number' => $pedido,
        ]);
        $projeto->products()->attach($this->produto->id, ['quantity' => 1]);

        return $projeto;
    }

    private function clienteFinal(Project $projeto): User
    {
        $user = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $projeto->company_id,
        ]);

        ProjectEntitlement::create([
            'project_id' => $projeto->id,
            'user_id' => $user->id,
            'product_id' => $this->produto->id,
        ]);

        return $user;
    }

    private function resgate(Project $projeto, User $user): Redemption
    {
        // O endereço de entrega é obrigatório no schema — o resgate só existe
        // depois que a pessoa o informa.
        $resgate = Redemption::create([
            'project_id' => $projeto->id,
            'user_id' => $user->id,
            'product_id' => $this->produto->id,
            'status' => 'confirmed',
            'recipient_name' => $user->name,
            'zip' => '80010000',
            'street' => 'Rua XV de Novembro',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'Curitiba',
            'state' => 'PR',
        ]);

        DB::table('tracking_events')->insert([
            'trackable_type' => Redemption::class,
            'trackable_id' => $resgate->id,
            'occurred_at' => now(),
            'status' => 'shipped',
            'description' => 'Postado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $resgate;
    }

    public function test_simulacao_nao_apaga_nada(): void
    {
        $this->projeto('A');
        $this->projeto('B');
        $this->projeto('C');
        $this->projeto('D');

        $this->artisan('welog:projects:purge', ['--keep' => 2])
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();

        $this->assertSame(4, Project::count());
    }

    /**
     * `--force` sozinho NÃO apaga.
     *
     * As duas travas fazem coisas diferentes: `--execute` liga a exclusão e
     * `--force` pula a confirmação de produção (é a flag que o
     * `ConfirmableTrait` lê — o nome não é escolha nossa). Elas já foram a
     * mesma opção, e assim quem passasse uma ganhava as duas permissões de
     * uma vez, apagando sem que nada perguntasse.
     */
    public function test_pular_a_confirmacao_nao_e_o_mesmo_que_executar(): void
    {
        $this->projeto('A');
        $this->projeto('B');

        $this->artisan('welog:projects:purge', ['--keep' => 1, '--force' => true])
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();

        $this->assertSame(2, Project::count());
    }

    public function test_mantem_os_mais_recentes_e_apaga_o_resto(): void
    {
        $this->projeto('A');
        $this->projeto('B');
        $manter1 = $this->projeto('C');
        $manter2 = $this->projeto('D');

        $this->artisan('welog:projects:purge', ['--keep' => 2, '--execute' => true])->assertSuccessful();

        $this->assertSame(
            [$manter1->id, $manter2->id],
            Project::orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_mantem_ids_escolhidos_a_mao(): void
    {
        $a = $this->projeto('A');
        $this->projeto('B');
        $this->projeto('C');

        $this->artisan('welog:projects:purge', ['--keep-ids' => (string) $a->id, '--execute' => true])
            ->assertSuccessful();

        $this->assertSame([$a->id], Project::pluck('id')->all());
    }

    /**
     * O número que a lista mostra ("66906 RODRIGO…") é o do PEDIDO na Omie, não
     * o id do WeLog — o mesmo registro é 66906 na tela e 1595 na URL. Manter
     * pelo número visível é o caminho que não exige abrir Projeto por Projeto.
     */
    public function test_mantem_pelo_numero_do_pedido_omie(): void
    {
        $manter = $this->projeto('A', null, '66906');
        $this->projeto('B', null, '66905');
        $this->projeto('C', null, '66904');

        $this->artisan('welog:projects:purge', ['--keep-orders' => '66906', '--execute' => true])
            ->assertSuccessful();

        $this->assertSame([$manter->id], Project::pluck('id')->all());
    }

    public function test_pedido_inexistente_aborta_sem_apagar(): void
    {
        $this->projeto('A', null, '66906');

        $this->artisan('welog:projects:purge', ['--keep-orders' => '99999', '--execute' => true])
            ->expectsOutputToContain('Pedidos Omie não encontrados')
            ->assertFailed();

        $this->assertSame(1, Project::count());
    }

    /** Id errado no `--keep-ids` viraria exclusão a mais sem ninguém notar. */
    public function test_id_inexistente_aborta_sem_apagar(): void
    {
        $this->projeto('A');

        $this->artisan('welog:projects:purge', ['--keep-ids' => '999999', '--execute' => true])
            ->expectsOutputToContain('Ids inexistentes')
            ->assertFailed();

        $this->assertSame(1, Project::count());
    }

    public function test_recusa_esvaziar_a_base_inteira(): void
    {
        $this->projeto('A');

        $this->artisan('welog:projects:purge', ['--keep' => 0, '--execute' => true])
            ->expectsOutputToContain('Nada a manter')
            ->assertFailed();

        $this->assertSame(1, Project::count());
    }

    /**
     * `redemptions.project_id` é `constrained()` sem cascade: sem apagar o
     * resgate antes, o banco recusa e a limpeza morre no meio.
     */
    public function test_projeto_com_resgate_e_apagado_junto_com_ele(): void
    {
        $alvo = $this->projeto('Alvo');
        $user = $this->clienteFinal($alvo);
        $this->resgate($alvo, $user);
        $manter = $this->projeto('Fica');

        $this->artisan('welog:projects:purge', ['--keep-ids' => (string) $manter->id, '--execute' => true])
            ->assertSuccessful();

        $this->assertSame(0, Project::whereKey($alvo->id)->count());
        $this->assertSame(0, Redemption::count());
    }

    /** A tabela é polimórfica e SEM chave estrangeira: ninguém limpa por nós. */
    public function test_eventos_de_rastreio_nao_ficam_orfaos(): void
    {
        $alvo = $this->projeto('Alvo');
        $user = $this->clienteFinal($alvo);
        $this->resgate($alvo, $user);
        $manter = $this->projeto('Fica');

        $this->assertSame(1, DB::table('tracking_events')->count());

        $this->artisan('welog:projects:purge', ['--keep-ids' => (string) $manter->id, '--execute' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('tracking_events')->count());
    }

    public function test_cliente_final_orfao_e_apagado(): void
    {
        $alvo = $this->projeto('Alvo');
        $orfao = $this->clienteFinal($alvo);
        $manter = $this->projeto('Fica');

        $this->artisan('welog:projects:purge', ['--keep-ids' => (string) $manter->id, '--execute' => true])
            ->assertSuccessful();

        $this->assertNull(User::find($orfao->id));
    }

    /** Quem ainda tem autorização em Projeto mantido NÃO é órfão. */
    public function test_cliente_final_com_projeto_mantido_sobrevive(): void
    {
        $alvo = $this->projeto('Alvo');
        $manter = $this->projeto('Fica');

        $user = $this->clienteFinal($alvo);
        ProjectEntitlement::create([
            'project_id' => $manter->id,
            'user_id' => $user->id,
            'product_id' => $this->produto->id,
        ]);

        $this->artisan('welog:projects:purge', ['--keep-ids' => (string) $manter->id, '--execute' => true])
            ->assertSuccessful();

        $this->assertNotNull(User::find($user->id));
    }

    /** O filtro de papel é o que impede a varredura de apagar quem opera. */
    public function test_operadores_e_admins_nunca_sao_apagados(): void
    {
        $this->projeto('Alvo');
        $manter = $this->projeto('Fica');
        $antes = User::whereIn('role', [
            User::ROLE_ADMIN_WEGIFT, User::ROLE_WEGIFT_N1, User::ROLE_WEGIFT_N2, User::ROLE_ADMIN_CLIENT,
        ])->count();

        $this->artisan('welog:projects:purge', ['--keep-ids' => (string) $manter->id, '--execute' => true])
            ->assertSuccessful();

        $this->assertSame($antes, User::whereIn('role', [
            User::ROLE_ADMIN_WEGIFT, User::ROLE_WEGIFT_N1, User::ROLE_WEGIFT_N2, User::ROLE_ADMIN_CLIENT,
        ])->count());
    }

    public function test_empresa_sem_projeto_e_sem_usuario_e_apagada(): void
    {
        $alvo = $this->projeto('Alvo');
        $vazia = $alvo->company;
        $manter = $this->projeto('Fica');

        $this->artisan('welog:projects:purge', ['--keep-ids' => (string) $manter->id, '--execute' => true])
            ->assertSuccessful();

        $this->assertNull(Company::find($vazia->id));
        $this->assertNotNull(Company::find($manter->company_id));
    }

    /**
     * `users.company_id` é `nullOnDelete`: apagar a empresa de um admin cliente
     * deixaria a conta apontando para lugar nenhum, e o middleware manda essa
     * pessoa para o 403 sem saída.
     */
    public function test_empresa_com_admin_cliente_e_preservada(): void
    {
        $alvo = $this->projeto('Alvo');
        $empresa = $alvo->company;
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT,
            'company_id' => $empresa->id,
        ]);
        $manter = $this->projeto('Fica');

        $this->artisan('welog:projects:purge', ['--keep-ids' => (string) $manter->id, '--execute' => true])
            ->expectsOutputToContain('NÃO foram apagadas')
            ->assertSuccessful();

        $this->assertNotNull(Company::find($empresa->id));
        $this->assertSame($empresa->id, User::find($admin->id)->company_id);
    }
}
