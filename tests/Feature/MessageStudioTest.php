<?php

namespace Tests\Feature;

use App\Mail\AccountInvitationMail;
use App\Mail\MessageStudioTestMail;
use App\Mail\NotificaColaborador;
use App\Mail\ProjectReleasedMail;
use App\Mail\RedemptionConfirmedMail;
use App\Mail\ShipmentUpdateMail;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Estúdio de mensagens: os e-mails da jornada do resgate na voz do Cliente.
 *
 * O que estes testes guardam: quem pode editar o quê (a WeGift em qualquer
 * Cliente, o Admin Cliente só na própria empresa), que o texto salvo é o que
 * sai no envio — cada etapa com o SEU modelo —, e que as variáveis em
 * português (`{nome}`) funcionam sem quebrar os modelos antigos.
 */
class MessageStudioTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    private User $adminCliente;

    private Redemption $resgate;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);
        $kit = Product::create(['omie_code' => 'KIT', 'name' => 'Kit 85 anos', 'stock_qty' => 10, 'stock_synced_at' => now()]);

        $this->projeto = Project::create([
            'company_id' => $this->empresa->id, 'name' => '85 anos', 'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK, 'released_at' => now(),
        ]);
        $this->projeto->products()->attach($kit->id, ['quantity' => 10]);

        $this->adminCliente = User::factory()->create([
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->empresa->id, 'password_set_at' => now(),
        ]);

        $colaborador = User::factory()->create([
            'name' => 'Maria Souza', 'role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresa->id,
        ]);

        $this->resgate = Redemption::create([
            'project_id' => $this->projeto->id, 'user_id' => $colaborador->id, 'product_id' => $kit->id,
            'status' => Redemption::STATUS_CONFIRMED, 'recipient_name' => 'Maria', 'zip' => '80010000',
            'street' => 'Rua XV', 'number' => '10', 'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);
    }

    private function adminWeGift(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    private function texto(array $extra = []): array
    {
        return [
            'subject' => 'Seu presente dos 85 anos está a caminho, {primeiro_nome}! 🎁',
            'title' => 'Tem comemoração chegando por aí!',
            'body' => "Olá, {nome}!\n\nCódigo de rastreio: {codigo_rastreio} ({transportadora})\n\nEssa história também é sua!",
        ] + $extra;
    }

    // ── Abrir ───────────────────────────────────────────────────────────────

    /** A jornada, na ordem — e o texto antigo já no vocabulário do Estúdio. */
    public function test_abre_a_jornada_em_ordem_com_variaveis_em_portugues(): void
    {
        $resposta = $this->actingAs($this->adminWeGift())
            ->getJson(route('studio.index', $this->empresa))
            ->assertOk();

        $this->assertSame(
            ['project_released', 'redemption_confirmed', 'redemption_shipped', 'redemption_delivered'],
            array_column($resposta->json('mensagens'), 'key'),
        );

        // O padrão antigo de "Resgate confirmado" tinha "# Resgate confirmado!"
        // no corpo e {{ userName }}: o Estúdio separa o título e traduz.
        $confirmado = $resposta->json('mensagens.1');
        $this->assertSame('Resgate confirmado!', $confirmado['title']);
        $this->assertStringContainsString('{nome}', $confirmado['body']);
        $this->assertStringNotContainsString('{{', $confirmado['body']);
    }

    public function test_admin_cliente_abre_a_propria_empresa_e_nao_a_de_outro(): void
    {
        $this->actingAs($this->adminCliente)->getJson(route('studio.index', $this->empresa))->assertOk();

        $outra = Company::create(['name' => 'Outra', 'cnpj' => '11222333000181']);
        $this->actingAs($this->adminCliente)->getJson(route('studio.index', $outra))->assertForbidden();
    }

    public function test_colaborador_nao_abre_o_estudio(): void
    {
        $this->actingAs($this->resgate->user)->getJson(route('studio.index', $this->empresa))->assertForbidden();
    }

    // ── Salvar e enviar ─────────────────────────────────────────────────────

    /** O texto salvo para o Projeto é o que sai na expedição — com as variáveis trocadas. */
    public function test_texto_do_projeto_sai_na_expedicao(): void
    {
        $this->actingAs($this->adminCliente)
            ->putJson(route('studio.update', [$this->empresa, EmailTemplate::KEY_REDEMPTION_SHIPPED]).'?projeto='.$this->projeto->id, $this->texto())
            ->assertOk();

        $this->assertDatabaseHas('email_templates', [
            'key' => EmailTemplate::KEY_REDEMPTION_SHIPPED,
            'project_id' => $this->projeto->id,
            'title' => 'Tem comemoração chegando por aí!',
        ]);

        $correios = Carrier::where('slug', Redemption::CARRIER_CORREIOS)->firstOrFail();

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('redemptions.ship', $this->resgate), ['carrier' => $correios->slug, 'tracking_code' => 'BR111222333BR'])
            ->assertSessionHasNoErrors();

        Mail::assertQueued(ShipmentUpdateMail::class, function (ShipmentUpdateMail $mail) {
            $html = $mail->render();

            return $mail->chave === EmailTemplate::KEY_REDEMPTION_SHIPPED
                && $mail->envelope()->subject === 'Seu presente dos 85 anos está a caminho, Maria! 🎁'
                && str_contains($html, 'Tem comemoração chegando por aí!')
                && str_contains($html, 'BR111222333BR (Correios)');
        });
    }

    /** Entregue tem o próprio modelo — não divide mais o texto com "expedido". */
    public function test_entrega_usa_o_modelo_de_pedido_entregue(): void
    {
        $this->resgate->update(['status' => Redemption::STATUS_SHIPPED, 'carrier' => 'mrb', 'tracking_code' => 'X1']);

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->post(route('redemptions.delivered', $this->resgate))
            ->assertSessionHasNoErrors();

        Mail::assertQueued(ShipmentUpdateMail::class, fn (ShipmentUpdateMail $mail) => $mail->chave === EmailTemplate::KEY_REDEMPTION_DELIVERED
            && $mail->envelope()->subject === 'Seu presente chegou!');
    }

    public function test_voltar_ao_padrao_apaga_o_texto_proprio(): void
    {
        $url = route('studio.update', [$this->empresa, EmailTemplate::KEY_REDEMPTION_DELIVERED]);
        $this->actingAs($this->adminCliente)->putJson($url, $this->texto())->assertOk();

        $this->actingAs($this->adminCliente)
            ->deleteJson(route('studio.restore', [$this->empresa, EmailTemplate::KEY_REDEMPTION_DELIVERED]))
            ->assertOk();

        $this->assertDatabaseMissing('email_templates', [
            'key' => EmailTemplate::KEY_REDEMPTION_DELIVERED, 'company_id' => $this->empresa->id,
        ]);
    }

    public function test_salvar_valida_assunto_e_mensagem(): void
    {
        $this->actingAs($this->adminCliente)
            ->putJson(route('studio.update', [$this->empresa, EmailTemplate::KEY_REDEMPTION_SHIPPED]), ['subject' => '', 'body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'body']);
    }

    /** O Estúdio só edita a jornada do resgate. */
    public function test_chave_fora_da_jornada_e_recusada(): void
    {
        $this->actingAs($this->adminCliente)
            ->putJson(route('studio.update', [$this->empresa, EmailTemplate::KEY_STAGE_CHANGED]), $this->texto())
            ->assertNotFound();
    }

    // ── Teste por e-mail ────────────────────────────────────────────────────

    /** "Enviar teste para mim": o texto da tela, sem salvar, com dados de exemplo. */
    public function test_envia_teste_para_quem_pediu_sem_salvar(): void
    {
        $this->actingAs($this->adminCliente)
            ->postJson(route('studio.test', [$this->empresa, EmailTemplate::KEY_REDEMPTION_SHIPPED]), $this->texto())
            ->assertOk()
            ->assertJsonPath('email', $this->adminCliente->email);

        Mail::assertSent(MessageStudioTestMail::class, fn (MessageStudioTestMail $mail) => $mail->hasTo($this->adminCliente->email)
            && str_starts_with($mail->assunto, '[Teste] Seu presente dos 85 anos')
            && str_contains($mail->corpo, 'BR123456789BR'));

        $this->assertDatabaseMissing('email_templates', ['company_id' => $this->empresa->id]);
    }

    // ── Variáveis ───────────────────────────────────────────────────────────

    public function test_variaveis_em_portugues_e_globais(): void
    {
        $email = EmailTemplate::compor(
            '{primeiro_nome}, chegou!',
            'Oi {nome}',
            "Em {data} às {hora}.\n\n{resumo_do_pedido}\n\n{chave_que_nao_existe}",
            EmailTemplate::dados(['userName' => 'Maria Souza', 'productName' => 'Kit <b>', 'address' => 'Rua XV, 10']),
        );

        $this->assertSame('Maria, chegou!', $email['subject']);
        $this->assertStringContainsString('# Oi Maria Souza', $email['body']);
        $this->assertMatchesRegularExpression('#Em \d{2}/\d{2}/\d{4} às \d{2}:\d{2}#', $email['body']);
        // Resumo com negrito e endereço; o valor vem escapado.
        $this->assertStringContainsString('**Kit &lt;b&gt;**', $email['body']);
        $this->assertStringContainsString('Entrega em: Rua XV, 10', $email['body']);
        // Chave desconhecida fica à vista, em vez de sumir com o texto.
        $this->assertStringContainsString('{chave_que_nao_existe}', $email['body']);
    }

    /** O e-mail de atendimento do Cliente vira o {contato} das mensagens dele. */
    public function test_contato_usa_o_atendimento_do_cliente(): void
    {
        $this->empresa->update(['customizations' => ['portal_support' => true, 'support_email' => 'atendimento.sotreq@wegift.com.br']]);

        $dados = EmailTemplate::dados([], $this->empresa->id);

        $this->assertSame('atendimento.sotreq@wegift.com.br', $dados['contato']);
    }

    // ── Envio por etapa ─────────────────────────────────────────────────────

    /**
     * O caso da Sotreq: e-mails a partir do resgate, sem o primeiro aviso.
     * Desligar uma etapa barra só ela.
     */
    public function test_desligar_uma_etapa_barra_so_ela(): void
    {
        $this->actingAs($this->adminCliente)
            ->putJson(route('studio.toggle', [$this->empresa, EmailTemplate::KEY_PROJECT_RELEASED]), ['ligada' => false])
            ->assertOk();

        $this->actingAs($this->adminCliente)->getJson(route('studio.index', $this->empresa))
            ->assertJsonPath('mensagens.0.ligada', false)
            ->assertJsonPath('mensagens.1.ligada', true);

        $colaborador = $this->resgate->user->fresh();
        $autorizacao = ProjectEntitlement::create([
            'project_id' => $this->projeto->id, 'user_id' => $colaborador->id, 'product_id' => $this->resgate->product_id,
        ]);

        $this->assertFalse(NotificaColaborador::enfileirar($colaborador, new ProjectReleasedMail($autorizacao, null)));
        $this->assertTrue(NotificaColaborador::enfileirar($colaborador, new RedemptionConfirmedMail($this->resgate)));

        // Religar volta a enviar.
        $this->actingAs($this->adminCliente)
            ->putJson(route('studio.toggle', [$this->empresa, EmailTemplate::KEY_PROJECT_RELEASED]), ['ligada' => true]);

        $this->assertTrue(NotificaColaborador::enfileirar($colaborador->fresh(), new ProjectReleasedMail($autorizacao, null)));
    }

    /**
     * "Presente liberado" desligado também segura o convite de acesso, o outro
     * primeiro envio. A porta passa a ser o link de primeiro acesso.
     */
    public function test_sem_o_primeiro_aviso_o_convite_nao_sai_e_o_link_existe(): void
    {
        $this->empresa->update(['customizations' => ['etapas_desligadas' => [EmailTemplate::KEY_PROJECT_RELEASED]]]);
        $this->projeto->update(['released_at' => null]);

        $this->actingAs($this->adminCliente)
            ->post(route('projects.entitlements.store', $this->projeto), [
                'name' => 'Nova Pessoa', 'email' => 'nova@sotreq.com.br',
            ])
            ->assertSessionHasNoErrors();

        Mail::assertNotQueued(AccountInvitationMail::class);
        $this->assertStringContainsString('/primeiro-acesso/', $this->empresa->fresh()->linkDePrimeiroAcesso());
    }

    public function test_admin_de_outro_cliente_nao_desliga_etapas(): void
    {
        $outra = Company::create(['name' => 'Outra', 'cnpj' => '11222333000181']);

        $this->actingAs($this->adminCliente)
            ->putJson(route('studio.toggle', [$outra, EmailTemplate::KEY_PROJECT_RELEASED]), ['ligada' => false])
            ->assertForbidden();
    }

    // ── Menu do Admin Cliente ───────────────────────────────────────────────

    public function test_menu_mensagens_so_com_os_emails_ligados(): void
    {
        $this->actingAs($this->adminCliente)->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.user.can_edit_messages', true));

        $this->empresa->update(['customizations' => ['welog_sends_emails' => false]]);

        // Recarregado: o mesmo objeto guardaria a empresa da requisição anterior.
        $this->actingAs($this->adminCliente->fresh())->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->where('auth.user.can_edit_messages', false));
    }
}
