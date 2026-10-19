<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A vitrine do portal custa o mesmo número de consultas com três presentes ou
 * com oito.
 *
 * Presente a presente, ela buscava o resgate, as variações e as fotos de cada
 * item: quem tinha o Colete em sete tamanhos abria a tela com umas quarenta
 * consultas, e mil pessoas no portal ao mesmo tempo multiplicavam isso. Este
 * teste segura a volta de uma consulta por presente.
 */
class PortalCatalogQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private Project $projeto;

    /** @var array<int, Product> */
    private array $tamanhos = [];

    private Product $bone;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();

        $empresa = Company::create(['name' => 'Sotreq', 'cnpj' => '12345678000190']);
        $this->projeto = Project::create([
            'company_id' => $empresa->id,
            'name' => 'Campanha 85 anos',
            'quantity' => 30,
            'pipeline_status' => Project::STATUS_IN_STOCK,
            'stock_available_at' => now(),
            'released_at' => now(),
        ]);

        Product::create(['omie_code' => 'PRD11363', 'name' => 'Colete']);

        foreach (['PP', 'P', 'M', 'G', 'GG', 'G2', 'G4'] as $n => $tamanho) {
            $this->tamanhos[] = Product::create(['omie_code' => 'PRD11363_'.($n + 1), 'name' => "Colete {$tamanho}"]);
        }

        $this->bone = Product::create(['omie_code' => 'BONE', 'name' => 'Boné']);
    }

    /** @param  array<int, Product>  $produtos */
    private function pessoaCom(array $produtos): User
    {
        $pessoa = User::factory()->create([
            'role' => User::ROLE_END_CUSTOMER,
            'company_id' => $this->projeto->company_id,
            'password_set_at' => now(),
            'active' => true,
        ]);

        // Os tamanhos são um "escolhe 1", como o cadastro os grava.
        $grupo = (string) Str::uuid();

        foreach ($produtos as $produto) {
            ProjectEntitlement::create([
                'project_id' => $this->projeto->id,
                'user_id' => $pessoa->id,
                'product_id' => $produto->id,
                'choice_group' => $produto->is($this->bone) ? null : $grupo,
            ]);
        }

        return $pessoa;
    }

    private function consultasDaVitrine(User $pessoa): int
    {
        $this->actingAs($pessoa);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('portal.catalog'))->assertOk();

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    public function test_mesmo_numero_de_consultas_com_tres_ou_oito_presentes(): void
    {
        $poucos = $this->pessoaCom([$this->bone, $this->tamanhos[0], $this->tamanhos[1]]);
        $muitos = $this->pessoaCom([$this->bone, ...$this->tamanhos]);

        // Uma visita antes de cada uma, para nada de primeira vez (cache,
        // sessão, último acesso) entrar na conta de uma pessoa só.
        $this->consultasDaVitrine($poucos);
        $this->consultasDaVitrine($muitos);

        $this->assertSame($this->consultasDaVitrine($poucos), $this->consultasDaVitrine($muitos));
    }
}
