<?php

namespace Tests\Feature\Mail;

use App\Mail\MessageStudioTestMail;
use App\Mail\RedemptionConfirmedMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O botão do e-mail sai onde o marcador {botao} está na mensagem, e o que
 * vem depois dele (agradecimento, assinatura) sai embaixo. Sem marcador, sem
 * botão. A assinatura é texto da mensagem, não do molde.
 */
class BotaoNoEmailTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Redemption $resgate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);
        $projeto = Project::create([
            'company_id' => $this->empresa->id, 'name' => '85 anos', 'quantity' => 1,
            'pipeline_status' => Project::STATUS_IN_STOCK,
        ]);
        $kit = Product::create(['omie_code' => 'KIT', 'name' => 'Kit 85 anos']);
        $pessoa = User::factory()->create([
            'name' => 'Maria Souza', 'role' => User::ROLE_END_CUSTOMER, 'company_id' => $this->empresa->id,
        ]);

        $this->resgate = Redemption::create([
            'project_id' => $projeto->id, 'user_id' => $pessoa->id, 'product_id' => $kit->id,
            'status' => Redemption::STATUS_CONFIRMED, 'recipient_name' => 'Maria Souza',
            'zip' => '80010000', 'street' => 'Rua A', 'number' => '1', 'district' => 'Centro',
            'city' => 'Curitiba', 'state' => 'PR',
        ]);
    }

    private function escrever(string $body): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_REDEMPTION_CONFIRMED, 'name' => 'x',
            'company_id' => $this->empresa->id, 'subject' => 'Assunto', 'body' => $body,
        ]);
    }

    private function html(): string
    {
        return (new RedemptionConfirmedMail($this->resgate))->render();
    }

    public function test_o_texto_depois_do_marcador_sai_embaixo_do_botao(): void
    {
        $this->escrever("Olá, {nome}!\n\n{botao}\n\nObrigado por fazer parte dos 85 anos!");

        $html = $this->html();

        $botao = strpos($html, 'Acompanhar meu pedido');
        $this->assertNotFalse($botao);
        $this->assertLessThan($botao, strpos($html, 'Olá, Maria Souza!'));
        $this->assertGreaterThan($botao, strpos($html, 'Obrigado por fazer parte dos 85 anos!'));

        // Nada fixo do molde: o "Abraços" só sai se estiver no texto.
        $this->assertStringNotContainsString('Abraços', $html);
        $this->assertStringNotContainsString('{botao}', $html);
    }

    public function test_sem_marcador_o_email_sai_sem_botao(): void
    {
        $this->escrever("Olá, {nome}!\n\nAté logo.");

        $this->assertStringNotContainsString('Acompanhar meu pedido', $this->html());
    }

    /** Os textos padrão terminam com o botão e a assinatura, como sempre saíram. */
    public function test_o_texto_padrao_mantem_botao_e_assinatura(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Acompanhar meu pedido', $html);
        $this->assertStringContainsString('Abraços,', $html);
        $this->assertLessThan(strpos($html, 'Abraços,'), strpos($html, 'Acompanhar meu pedido'));
    }

    /** O "Enviar teste para mim" respeita a posição do mesmo jeito. */
    public function test_o_email_de_teste_respeita_a_posicao(): void
    {
        $html = (new MessageStudioTestMail('Teste', "Antes\n\n{botao}\n\nDepois", 'Acompanhar meu pedido'))->render();

        $this->assertLessThan(strpos($html, 'Acompanhar meu pedido'), strpos($html, 'Antes'));
        $this->assertGreaterThan(strpos($html, 'Acompanhar meu pedido'), strpos($html, 'Depois'));
    }

    /** A migração dá a cada texto gravado o fim que o molde acrescentava. */
    public function test_a_migracao_completa_os_textos_antigos(): void
    {
        DB::table('email_templates')->insert([
            'key' => EmailTemplate::KEY_REDEMPTION_SHIPPED, 'name' => 'x', 'company_id' => $this->empresa->id,
            'subject' => 'Assunto', 'body' => "Texto antigo\n",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migracao = require database_path('migrations/2026_10_07_000001_add_button_marker_to_email_templates.php');
        $migracao->up();
        // Rodar de novo não duplica.
        $migracao->up();

        $this->assertSame(
            'Texto antigo'.EmailTemplate::FECHO,
            DB::table('email_templates')->where('body', 'like', 'Texto antigo%')->value('body'),
        );
    }
}
