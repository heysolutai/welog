<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Customização "Página de primeiro acesso": o endereço (/primeiro-acesso/sotreq)
 * e o aviso de "Não achou?" de cada Cliente.
 *
 * O endereço está publicado na intranet do Cliente: trocá-lo não pode deixar
 * quem clica no antigo numa página de erro.
 */
class FirstAccessCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $sotreq;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();

        $this->sotreq = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);
        $this->sotreq->slugDePrimeiroAcesso(); // "sotreq", como já publicado
    }

    private function admin(): User
    {
        return User::where('email', 'admin@welog.local')->firstOrFail();
    }

    private function salvar(array $dados, ?Company $empresa = null)
    {
        $resposta = $this->actingAs($this->admin())
            ->put(route('admin.companies.first-access.update', $empresa ?? $this->sotreq), $dados);

        // A página de primeiro acesso é só para quem não entrou: logado, ela
        // redireciona. As visitas seguintes do teste são as do colaborador.
        $this->app['auth']->forgetGuards();

        return $resposta;
    }

    private function ligar(): void
    {
        $this->sotreq->update(['customizations' => [...($this->sotreq->customizations ?? []), 'first_access_page' => true]]);
    }

    public function test_aviso_do_cliente_aparece_na_pagina(): void
    {
        $this->ligar();
        $this->salvar(['slug' => 'sotreq', 'dica' => 'Não achou? Confira o seu "Lixo eletrônico" ou os e-mails em "Outros".'])
            ->assertSessionHasNoErrors();

        $this->get('/primeiro-acesso/sotreq')
            ->assertInertia(fn ($p) => $p->where('company.dica', 'Não achou? Confira o seu "Lixo eletrônico" ou os e-mails em "Outros".'));
    }

    /** Desligada, volta o aviso padrão (o texto fica guardado para religar). */
    public function test_desligada_vale_o_aviso_padrao(): void
    {
        $this->salvar(['slug' => 'sotreq', 'dica' => 'Confira o Lixo eletrônico.']);

        $this->get('/primeiro-acesso/sotreq')
            ->assertInertia(fn ($p) => $p->where('company.dica', null));
    }

    public function test_novo_endereco_e_o_antigo_leva_ate_ele(): void
    {
        $this->salvar(['slug' => 'Sotreq Brindes'])->assertSessionHasNoErrors();

        $this->sotreq->refresh();
        $this->assertSame('sotreq-brindes', $this->sotreq->first_access_slug);
        $this->assertStringEndsWith('/primeiro-acesso/sotreq-brindes', $this->sotreq->linkDePrimeiroAcesso());

        $this->get('/primeiro-acesso/sotreq-brindes')->assertOk();
        // O link antigo, ainda na intranet, leva ao novo.
        $this->get('/primeiro-acesso/sotreq')->assertRedirect('/primeiro-acesso/sotreq-brindes');
    }

    /** Endereço de outro Cliente — atual ou antigo — não pode ser tomado. */
    public function test_endereco_de_outro_cliente_e_recusado(): void
    {
        $outra = Company::create(['name' => 'Outra Empresa', 'cnpj' => '98765432000110']);
        $outra->slugDePrimeiroAcesso(); // "outra-empresa"

        $this->salvar(['slug' => 'outra-empresa'])->assertSessionHasErrors('slug');

        // A Sotreq troca de endereço; o antigo continua sendo dela.
        $this->salvar(['slug' => 'sotreq-brindes']);
        $this->salvar(['slug' => 'sotreq'], $outra)->assertSessionHasErrors('slug');
    }

    /** Voltar ao endereço anterior funciona e não deixa redirecionamento circular. */
    public function test_voltar_ao_endereco_anterior(): void
    {
        $this->salvar(['slug' => 'sotreq-brindes']);
        $this->salvar(['slug' => 'sotreq'])->assertSessionHasNoErrors();

        $this->sotreq->refresh();
        $this->assertSame('sotreq', $this->sotreq->first_access_slug);
        $this->assertSame(['sotreq-brindes'], $this->sotreq->slugsAntigosDePrimeiroAcesso());

        $this->get('/primeiro-acesso/sotreq')->assertOk();
        $this->get('/primeiro-acesso/sotreq-brindes')->assertRedirect('/primeiro-acesso/sotreq');
    }

    /** Só o Admin WeGift muda a página de um Cliente. */
    public function test_operador_nao_altera(): void
    {
        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->put(route('admin.companies.first-access.update', $this->sotreq), ['slug' => 'x-y'])
            ->assertForbidden();
    }
}
