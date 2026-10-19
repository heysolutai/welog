<?php

namespace Tests\Feature;

use App\Mail\ProjectReleasedMail;
use App\Mail\RedemptionConfirmedMail;
use App\Mail\ShipmentUpdateMail;
use App\Mail\TemplatedMailable;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Services\FluxoA\RedeemKit;
use App\Services\FluxoA\ReleaseProject;
use App\Services\FluxoA\ShipRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A jornada do resgate de ponta a ponta, pelos caminhos reais: liberar o
 * Projeto, resgatar, expedir e marcar como entregue.
 *
 * O que se garante aqui é a promessa da tela Comunicações: etapa ligada é
 * e-mail que sai, com o texto escrito no Estúdio; etapa desligada é a única
 * que cala, sem levar as outras junto.
 */
class JornadaDeEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Project $projeto;

    private Product $kit;

    private User $operador;

    private User $pessoa;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
        Mail::fake();

        $this->empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);

        $this->kit = Product::create([
            'omie_code' => 'KIT-85', 'name' => 'Kit 85 anos',
            'stock_qty' => 100, 'stock_synced_at' => now(),
        ]);

        $this->projeto = Project::create([
            'company_id' => $this->empresa->id,
            'name' => '85 anos',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
        ]);
        $this->projeto->products()->attach($this->kit->id, ['quantity' => 10]);

        $this->operador = User::where('email', 'operador@welog.local')->firstOrFail();

        $this->pessoa = User::factory()->create([
            'name' => 'Maria Souza',
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->empresa->id,
        ]);

        ProjectEntitlement::create([
            'project_id' => $this->projeto->id,
            'user_id' => $this->pessoa->id,
            'product_id' => $this->kit->id,
        ]);

        // O texto de cada etapa escrito no Estúdio, na voz do Cliente.
        foreach (array_keys(EmailTemplate::JORNADA) as $chave) {
            EmailTemplate::create([
                'key' => $chave,
                'name' => $chave,
                'company_id' => $this->empresa->id,
                'subject' => "Sotreq {$chave} para {nome}",
                'title' => 'Olá',
                'body' => 'Oi, **{primeiro_nome}**. Texto *próprio* da Sotreq.',
            ]);
        }
    }

    /** Percorre a jornada inteira pelos mesmos caminhos da operação. */
    private function percorrerAJornada(): void
    {
        app(ReleaseProject::class)->handle($this->projeto, $this->operador);

        $resgate = app(RedeemKit::class)->handle(
            ProjectEntitlement::where('user_id', $this->pessoa->id)->firstOrFail(),
            [
                'recipient_name' => 'Maria Souza', 'cpf' => '52998224725', 'zip' => '80010000',
                'street' => 'Rua das Tintas', 'number' => '45', 'complement' => '',
                'district' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
            ],
            $this->pessoa,
        );

        app(ShipRedemption::class)->handle($resgate, 'correios', $this->operador, 'AA123456789BR');

        $this->actingAs($this->operador)
            ->post(route('redemptions.delivered', $resgate))
            ->assertSessionHasNoErrors();

        $this->assertSame(Redemption::STATUS_DELIVERED, $resgate->fresh()->status);
    }

    /** @return array<string, TemplatedMailable> chave da etapa => e-mail enfileirado */
    private function enviados(): array
    {
        return collect([ProjectReleasedMail::class, RedemptionConfirmedMail::class, ShipmentUpdateMail::class])
            ->flatMap(fn (string $classe) => Mail::queued($classe))
            ->mapWithKeys(fn (TemplatedMailable $m) => [$m->chaveDoModelo() => $m])
            ->all();
    }

    public function test_com_todas_as_etapas_ligadas_os_quatro_emails_saem_com_o_texto_do_estudio(): void
    {
        $this->percorrerAJornada();

        $enviados = $this->enviados();

        $this->assertEqualsCanonicalizing(array_keys(EmailTemplate::JORNADA), array_keys($enviados));

        foreach ($enviados as $chave => $email) {
            $this->assertTrue($email->hasTo($this->pessoa->email), "{$chave} não foi para a pessoa.");
            $this->assertSame("Sotreq {$chave} para Maria Souza", $email->envelope()->subject);

            $html = $email->render();
            $this->assertMatchesRegularExpression('#<strong[^>]*>Maria</strong>#', $html, "{$chave} sem o negrito.");
            $this->assertMatchesRegularExpression('#<em[^>]*>próprio</em>#u', $html, "{$chave} sem o itálico.");
        }
    }

    /**
     * Toda variável que o Estúdio oferece numa etapa sai trocada pelo valor no
     * envio de verdade. O "Pedido expedido" chegava com "Seu presente
     * {produto}" porque o e-mail de expedição não mandava o produto.
     */
    public function test_toda_variavel_oferecida_sai_com_valor_no_envio_real(): void
    {
        foreach (EmailTemplate::JORNADA as $chave => $_) {
            $tokens = EmailTemplate::definitions()[$chave]['variaveis'];

            EmailTemplate::where('key', $chave)->where('company_id', $this->empresa->id)->update([
                'subject' => collect($tokens)->map(fn ($t) => "{{$t}}")->implode(' | '),
                'body' => collect($tokens)->map(fn ($t) => "{$t}: {{$t}}")->implode("\n\n"),
            ]);
        }

        $this->percorrerAJornada();

        foreach ($this->enviados() as $chave => $email) {
            $assunto = $email->envelope()->subject;
            $texto = html_entity_decode(strip_tags($email->render()));

            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $assunto, "{$chave}: variável crua no assunto.");
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $texto, "{$chave}: variável crua no corpo.");
            $this->assertStringContainsString('Kit 85 anos', $texto, "{$chave}: sem o presente.");
            $this->assertStringContainsString('Sotreq', $texto, "{$chave}: sem a empresa.");
        }
    }

    /** @return array<string, array{0: string}> */
    public static function etapas(): array
    {
        return collect(array_keys(EmailTemplate::JORNADA))
            ->mapWithKeys(fn (string $chave) => [$chave => [$chave]])
            ->all();
    }

    #[DataProvider('etapas')]
    public function test_desligar_uma_etapa_cala_so_ela(string $desligada): void
    {
        $this->empresa->update(['customizations' => ['etapas_desligadas' => [$desligada]]]);

        $this->percorrerAJornada();

        $esperadas = array_values(array_diff(array_keys(EmailTemplate::JORNADA), [$desligada]));

        $this->assertEqualsCanonicalizing($esperadas, array_keys($this->enviados()));
    }

    /** Pela tela: a chave do Estúdio é a mesma que o envio respeita. */
    public function test_a_chave_do_estudio_liga_e_desliga_o_envio_de_verdade(): void
    {
        $admin = User::create([
            'name' => 'RH Sotreq', 'email' => 'rh@sotreq.com.br', 'password' => 'x',
            'role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->empresa->id, 'active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson(route('studio.toggle', [$this->empresa, EmailTemplate::KEY_REDEMPTION_SHIPPED]), ['ligada' => false])
            ->assertOk();

        $this->percorrerAJornada();

        $this->assertArrayNotHasKey(EmailTemplate::KEY_REDEMPTION_SHIPPED, $this->enviados());
        $this->assertArrayHasKey(EmailTemplate::KEY_REDEMPTION_DELIVERED, $this->enviados());
    }
}
