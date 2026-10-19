<?php

namespace Tests\Feature;

use App\Mail\AccountInvitationMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A porta que o Cliente publica na intranet dele.
 *
 * O colaborador chega por um canal interno da própria empresa, digita o e-mail
 * corporativo e recebe o link de criar senha. A WeLog não escreve para nove mil
 * pessoas: entrega um endereço, e o RH distribui.
 *
 * O que estes testes guardam é o que um link de intranet garante que vai
 * acontecer — ele sai da empresa. Vai para grupo de mensagem, para print, para
 * o e-mail pessoal de quem já saiu. A partir daí, o endereço está nas mãos de
 * gente que não deveria ter, e é aí que ele precisa continuar não entregando
 * nada.
 */
class FirstAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $sotreq;

    private Company $outra;

    private Project $projeto;

    private Product $kit;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->sotreq = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);
        $this->outra = Company::create(['name' => 'Outra Empresa', 'cnpj' => '98765432000110']);

        $this->kit = Product::create([
            'omie_code' => 'KIT-S', 'name' => 'Kit Sotreq',
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);

        $this->projeto = Project::create([
            'company_id' => $this->sotreq->id,
            'name' => 'Campanha Sotreq',
            'quantity' => 50,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);
        $this->projeto->products()->attach($this->kit->id, ['quantity' => 50]);
    }

    private function token(): string
    {
        return $this->sotreq->slugDePrimeiroAcesso();
    }

    /** Colaborador pronto para entrar: da empresa, autorizado, sem senha. */
    private function colaborador(?Company $empresa = null, bool $autorizado = true): User
    {
        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => ($empresa ?? $this->sotreq)->id,
            'password_set_at' => null,
        ]);

        if ($autorizado) {
            ProjectEntitlement::create([
                'project_id' => $this->projeto->id,
                'user_id' => $pessoa->id,
                'product_id' => $this->kit->id,
            ]);
        }

        return $pessoa;
    }

    private function pedir(string $email, ?string $token = null)
    {
        return $this->post('/primeiro-acesso/'.($token ?? $this->token()), ['email' => $email]);
    }

    // ------------------------------------------------------------------
    // A tela
    // ------------------------------------------------------------------

    public function test_a_tela_abre_com_o_nome_do_cliente(): void
    {
        $this->get("/primeiro-acesso/{$this->token()}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Auth/FirstAccess')
                ->where('company.name', 'Sotreq'));
    }

    /** Token inválido é 404, e não uma tela dizendo "link errado". */
    public function test_token_invalido_e_404(): void
    {
        $this->get('/primeiro-acesso/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/primeiro-acesso/curto')->assertNotFound();
    }

    /**
     * Um link só por Cliente, com o nome dele no fim: é o endereço que o RH
     * cola na intranet e que o colaborador consegue ler e repetir.
     */
    public function test_o_link_e_o_nome_do_cliente(): void
    {
        $this->assertStringEndsWith('/primeiro-acesso/sotreq', $this->sotreq->linkDePrimeiroAcesso());
    }

    /** Sufixo societário não entra no endereço. */
    public function test_sufixo_da_razao_social_fica_de_fora(): void
    {
        $empresa = Company::create(['name' => 'Banco Ação S.A.', 'cnpj' => '11222333000181']);

        $this->assertSame('banco-acao', $empresa->slugDePrimeiroAcesso());
    }

    /** Dois Clientes com o mesmo nome não dividem a porta. */
    public function test_nomes_iguais_ganham_links_diferentes(): void
    {
        $this->token();
        $xara = Company::create(['name' => 'Sotreq', 'cnpj' => '11222333000181']);

        $this->assertSame('sotreq-2', $xara->slugDePrimeiroAcesso());
    }

    /**
     * O link não muda depois de publicado: trocar o nome do Cliente no
     * cadastro não pode derrubar o endereço que já está na intranet.
     */
    public function test_o_link_nao_muda_com_o_nome(): void
    {
        $antes = $this->token();
        $this->sotreq->update(['name' => 'Sotreq Máquinas']);

        $this->assertSame($antes, $this->sotreq->fresh()->slugDePrimeiroAcesso());
        $this->get('/primeiro-acesso/sotreq')->assertOk();
    }

    /** Maiúscula no endereço digitado à mão não vira 404. */
    public function test_maiuscula_no_link_abre_a_mesma_tela(): void
    {
        $this->token();

        $this->get('/primeiro-acesso/Sotreq')->assertOk();
    }

    /** O endereço antigo, de token longo, continua abrindo para quem já o publicou. */
    public function test_link_antigo_continua_valendo(): void
    {
        $antigo = str_repeat('b', 48);
        $this->sotreq->forceFill(['first_access_token' => $antigo])->save();

        $this->get("/primeiro-acesso/{$antigo}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('company.name', 'Sotreq'));
    }

    /**
     * A tela do Projeto entrega o link quando é o Cliente quem divulga a
     * campanha (a WeLog não manda o primeiro aviso).
     */
    public function test_a_tela_do_projeto_mostra_o_link_quando_o_cliente_divulga(): void
    {
        $this->sotreq->update(['customizations' => ['welog_sends_emails' => false]]);

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn ($p) => $p->where('project.company.first_access_url', url('/primeiro-acesso/sotreq')));
    }

    /** Com a WeLog avisando, o convite chega por e-mail e o link nem aparece. */
    public function test_com_a_welog_avisando_o_link_nao_aparece_no_projeto(): void
    {
        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('projects.show', $this->projeto))
            ->assertInertia(fn ($p) => $p->where('project.company.first_access_url', null));
    }

    // ------------------------------------------------------------------
    // O envio
    // ------------------------------------------------------------------

    public function test_colaborador_autorizado_recebe_o_convite(): void
    {
        $pessoa = $this->colaborador();

        $this->pedir($pessoa->email)->assertSessionHas('status');

        Mail::assertQueued(AccountInvitationMail::class, 1);
        $this->assertNotNull($pessoa->fresh()->invited_at);
    }

    /**
     * Maiúscula e espaço são o caso comum, não o excepcional: teclado de
     * celular capitaliza sozinho e colar do crachá traz espaço no fim. Sem
     * normalizar, essas pessoas veem "não encontrado" olhando para o próprio
     * e-mail correto, e a conta chega inteira no suporte.
     */
    public function test_maiuscula_e_espaco_nao_impedem_o_envio(): void
    {
        $pessoa = $this->colaborador();

        $this->pedir('  '.mb_strtoupper($pessoa->email).' ');

        Mail::assertQueued(AccountInvitationMail::class, 1);
    }

    /**
     * O link só vale para e-mail cadastrado, e a tela diz isso: quem digita
     * errado não pode ler "Pronto" e ficar esperando um e-mail que não vem.
     */
    public function test_email_nao_cadastrado_e_recusado_com_erro(): void
    {
        $this->pedir('ninguem@sotreq.com.br')
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('status');

        $this->assertErroNoEmail('não está cadastrado');
        Mail::assertNothingQueued();
    }

    private function assertErroNoEmail(string $trecho): void
    {
        $this->assertStringContainsString($trecho, session('errors')->first('email'));
    }

    // ------------------------------------------------------------------
    // O que o link NÃO abre
    // ------------------------------------------------------------------

    /**
     * O token é o escopo. Sem esta trava, o link de um Cliente dispararia
     * convite para colaborador de qualquer outro — e bastaria um link vazado
     * para alcançar a base inteira.
     */
    public function test_o_link_de_um_cliente_nao_alcanca_o_outro(): void
    {
        $deOutra = $this->colaborador($this->outra);

        $this->pedir($deOutra->email)->assertSessionHasErrors('email');

        $this->assertErroNoEmail('não está cadastrado');
        Mail::assertNothingQueued();
    }

    /** Sem autorização em projeto liberado, não há o que resgatar. */
    public function test_quem_nao_tem_direito_nao_recebe(): void
    {
        $semDireito = $this->colaborador(autorizado: false);

        $this->pedir($semDireito->email)->assertSessionHasErrors('email');

        $this->assertErroNoEmail('ainda não foi liberada');
        Mail::assertNothingQueued();
    }

    /** Projeto ainda não liberado: convite levaria a uma tela vazia. */
    public function test_projeto_nao_liberado_nao_gera_convite(): void
    {
        $pessoa = $this->colaborador();
        $this->projeto->forceFill(['released_at' => null])->save();

        $this->pedir($pessoa->email)->assertSessionHasErrors('email');

        $this->assertErroNoEmail('ainda não foi liberada');
        Mail::assertNothingQueued();
    }

    /**
     * Quem já tem senha usa "esqueci minha senha".
     *
     * Reenviar convite aqui seria um jeito de derrubar a senha de outra
     * pessoa sem nenhum acesso à caixa dela — basta saber o e-mail e ter o
     * link da intranet.
     */
    public function test_quem_ja_tem_senha_nao_e_convidado_de_novo(): void
    {
        $pessoa = $this->colaborador();
        $pessoa->forceFill(['password_set_at' => now()])->save();

        $this->pedir($pessoa->email)->assertSessionHasErrors('email');

        $this->assertErroNoEmail('já tem senha');
        Mail::assertNothingQueued();
    }

    /** Operador e Admin Cliente entram pelo caminho deles. */
    public function test_operador_nao_entra_por_esta_porta(): void
    {
        $operador = User::where('email', 'operador@welog.local')->firstOrFail();
        $operador->forceFill(['company_id' => $this->sotreq->id, 'password_set_at' => null])->save();

        $this->pedir($operador->email)->assertSessionHasErrors('email');

        $this->assertErroNoEmail('não está cadastrado');
        Mail::assertNothingQueued();
    }

    public function test_colaborador_inativo_nao_recebe(): void
    {
        $pessoa = $this->colaborador();
        $pessoa->forceFill(['active' => false])->save();

        $this->pedir($pessoa->email)->assertSessionHasErrors('email');

        $this->assertErroNoEmail('não está cadastrado');
        Mail::assertNothingQueued();
    }

    // ------------------------------------------------------------------
    // Abuso
    // ------------------------------------------------------------------

    /**
     * Bombardear UMA pessoa é o abuso mais fácil daqui: o link é público
     * dentro da empresa e o e-mail do colega é conhecido.
     */
    public function test_nao_da_para_encher_a_caixa_de_uma_pessoa(): void
    {
        $pessoa = $this->colaborador();

        foreach (range(1, 3) as $n) {
            $this->pedir($pessoa->email)->assertSessionHas('status');
        }

        // O limite chega como frase no campo, não como a página de erro crua.
        $this->pedir($pessoa->email)
            ->assertRedirect()
            ->assertSessionHasErrors(['email' => 'Muitos pedidos para este e-mail. Espere alguns minutos e tente de novo.']);

        Mail::assertQueued(AccountInvitationMail::class, 3);
    }
}
