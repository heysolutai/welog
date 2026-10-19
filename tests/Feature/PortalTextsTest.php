<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tela de resgate da campanha (Itens do pedido > Tela de resgate): saudação,
 * chamada da escolha, título das entregas e o nome e a descrição de cada
 * item, escritos no PROJETO. Cada campanha tem a sua voz.
 */
class PortalTextsTest extends TestCase
{
    use RefreshDatabase;

    private Company $sotreq;

    private Project $projeto;

    private Product $mochila;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();

        $this->sotreq = Company::create(['name' => 'Sotreq S.A.', 'cnpj' => '12345678000190']);
        $this->projeto = Project::create([
            'company_id' => $this->sotreq->id,
            'name' => 'Sotreq 85 anos',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);

        $this->mochila = Product::create([
            'omie_code' => 'PRD11355', 'name' => 'Mochila em Nylon + Estojo Organizador',
            'description' => 'Texto técnico da Omie.',
        ]);
        $this->projeto->products()->attach($this->mochila->id, ['quantity' => 10]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    private function colaborador(): User
    {
        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->sotreq->id,
            'password_set_at' => now(),
        ]);

        ProjectEntitlement::create(['project_id' => $this->projeto->id, 'user_id' => $pessoa->id, 'product_id' => $this->mochila->id]);

        return $pessoa;
    }

    private function salvar(array $dados)
    {
        return $this->actingAs($this->admin())->put(route('projects.portal-texts.update', $this->projeto), $dados);
    }

    public function test_os_textos_da_campanha_chegam_ao_portal(): void
    {
        $this->salvar([
            'saudacao' => 'Neste aniversário, o presente é para você!',
            'escolha' => 'Escolha 1 das opções que mais combina com você',
            'entregas' => 'Acompanhe o seu presente',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->colaborador())
            ->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('auth.user.portal_texts.saudacao', 'Neste aniversário, o presente é para você!')
                ->where('auth.user.portal_texts.escolha', 'Escolha 1 das opções que mais combina com você')
                ->where('auth.user.portal_texts.entregas', 'Acompanhe o seu presente')
                ->where('gifts.0.escolha', 'Escolha 1 das opções que mais combina com você'));
    }

    /** O nome e a descrição do item como a campanha escreveu, na vitrine e no resgate. */
    public function test_nome_e_descricao_do_item(): void
    {
        $this->salvar(['itens' => ['PRD11355' => ['nome' => 'Mochila 85 anos', 'descricao' => 'Para levar o notebook.']]])
            ->assertSessionHasNoErrors();

        $pessoa = $this->colaborador();

        $this->actingAs($pessoa)
            ->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('gifts.0.product.name', 'Mochila 85 anos')
                ->where('gifts.0.product.summary', 'Para levar o notebook.'));

        $entitlement = ProjectEntitlement::where('user_id', $pessoa->id)->firstOrFail();

        $this->actingAs($pessoa)
            ->get(route('portal.redeem.form', $entitlement))
            ->assertInertia(fn (Assert $p) => $p
                ->where('entitlement.product.name', 'Mochila 85 anos')
                ->where('entitlement.product.description', 'Para levar o notebook.'));
    }

    /** Sem texto da campanha, vale o do catálogo, e a vitrine não mostra o da Omie. */
    public function test_sem_texto_vale_o_do_catalogo(): void
    {
        $this->actingAs($this->colaborador())
            ->get(route('portal.catalog'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('gifts.0.product.name', 'Mochila em Nylon + Estojo Organizador')
                ->where('gifts.0.product.summary', null)
                ->where('gifts.0.escolha', null));
    }

    /** Campo vazio não é gravado, e item de fora do Projeto é descartado. */
    public function test_vazio_e_item_de_fora_nao_sao_gravados(): void
    {
        $this->salvar([
            'saudacao' => '   ',
            'escolha' => 'Escolha a sua',
            'itens' => ['OUTRO' => ['nome' => 'Intruso'], 'PRD11355' => ['nome' => '', 'descricao' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['escolha' => 'Escolha a sua'], $this->projeto->fresh()->portal_texts);
    }

    public function test_texto_comprido_demais_e_recusado(): void
    {
        $this->salvar(['entregas' => str_repeat('a', 61)])->assertSessionHasErrors('entregas');
        $this->salvar(['itens' => ['PRD11355' => ['descricao' => str_repeat('a', 601)]]])
            ->assertSessionHasErrors('itens.PRD11355.descricao');
    }

    /** Cada campanha tem a sua voz: os textos de um Projeto não vazam para outro. */
    public function test_textos_sao_do_projeto(): void
    {
        $this->salvar(['saudacao' => 'Só da campanha de 85 anos'])->assertSessionHasNoErrors();

        $outro = Project::create(['company_id' => $this->sotreq->id, 'name' => 'Outra campanha']);

        $this->assertSame([], $outro->textosDoPortal());
        $this->assertSame(['saudacao' => 'Só da campanha de 85 anos'], $this->projeto->fresh()->textosDoPortal());
    }

    /** Só a equipe WeGift (crítica) altera a tela de resgate. */
    public function test_admin_cliente_nao_altera(): void
    {
        $adminCliente = User::factory()->create(['role' => User::ROLE_ADMIN_CLIENT, 'company_id' => $this->sotreq->id]);

        $this->actingAs($adminCliente)
            ->put(route('projects.portal-texts.update', $this->projeto), ['saudacao' => 'x'])
            ->assertForbidden();
    }
}
