<?php

namespace Tests\Feature;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Hierarquia Kit × Itens: kit dentro de kit (achado do UAT).
 *
 * A estrutura da Omie tem mais de um nível, mas só o kit consultado devolve os
 * filhos — netos exigem consulta própria. Enquanto o espelho parava no
 * primeiro nível, um sub-kit entrava no catálogo como peça solta e o conteúdo
 * dele não aparecia em lugar nenhum da tela.
 */
class KitHierarchyTest extends TestCase
{
    use RefreshDatabase;

    /** Catálogo remoto de mentira, no formato que o espelho consome. */
    private const REMOTO = [
        'KIT-CAMPANHA' => [
            'omie_code' => 'KIT-CAMPANHA', 'name' => 'Kit Campanha', 'is_kit' => true,
            'structure_checked' => true,
            'components' => ['KIT-ESCRITORIO' => 1, 'CAMISETA-M' => 2],
            'component_names' => ['KIT-ESCRITORIO' => 'Kit Escritório', 'CAMISETA-M' => 'Camiseta M'],
        ],
        'KIT-ESCRITORIO' => [
            'omie_code' => 'KIT-ESCRITORIO', 'name' => 'Kit Escritório', 'is_kit' => true,
            'structure_checked' => true,
            'components' => ['CANECA-350' => 1, 'CADERNO-A5' => 2],
            'component_names' => ['CANECA-350' => 'Caneca 350ml', 'CADERNO-A5' => 'Caderno A5'],
        ],
        'CAMISETA-M' => ['omie_code' => 'CAMISETA-M', 'name' => 'Camiseta M', 'is_kit' => false, 'structure_checked' => true],
        'CANECA-350' => ['omie_code' => 'CANECA-350', 'name' => 'Caneca 350ml', 'is_kit' => false, 'structure_checked' => true],
        'CADERNO-A5' => ['omie_code' => 'CADERNO-A5', 'name' => 'Caderno A5', 'is_kit' => false, 'structure_checked' => true],
    ];

    /** @var array<int, string> códigos que o espelho foi buscar na Omie */
    private array $consultados = [];

    /** @param array<string, mixed> $catalogo */
    private function espelho(array $catalogo): MirrorOmieProduct
    {
        $gateway = Mockery::mock(OmieGateway::class);
        $gateway->shouldReceive('findProductByCode')->andReturnUsing(function (string $code) use ($catalogo) {
            $this->consultados[] = $code;

            $remoto = $catalogo[$code] ?? null;

            if ($remoto instanceof RuntimeException) {
                throw $remoto;
            }

            return $remoto;
        });

        return new MirrorOmieProduct(app(OmieAccounts::class), $gateway);
    }

    public function test_sub_kit_e_espelhado_com_a_estrutura_dele(): void
    {
        $kit = $this->espelho(self::REMOTO)->handle('KIT-CAMPANHA');

        $this->assertNotNull($kit);
        $this->assertTrue($kit->is_kit);

        // Nível 1: o sub-kit entra como KIT, não como peça solta
        $subKit = $kit->components->firstWhere('omie_code', 'KIT-ESCRITORIO');
        $this->assertNotNull($subKit);
        $this->assertTrue($subKit->is_kit, 'Sub-kit precisa ser reconhecido como kit');
        $this->assertSame('Kit Escritório', $subKit->name);

        // Nível 2: o conteúdo do sub-kit, que antes não era espelhado
        $netos = $subKit->components()->pluck('omie_code')->sort()->values()->all();
        $this->assertSame(['CADERNO-A5', 'CANECA-350'], $netos);

        // A quantidade do BOM é por unidade do pai, em cada nível
        $this->assertEquals(2, $subKit->components()->where('omie_code', 'CADERNO-A5')->first()->pivot->quantity);
        $this->assertEquals(1, $kit->components()->where('omie_code', 'KIT-ESCRITORIO')->first()->pivot->quantity);
    }

    /** Custo pago uma vez por produto: quem já foi conferido sai do banco. */
    public function test_componente_ja_conferido_nao_volta_a_consultar_a_omie(): void
    {
        Product::create([
            'omie_code' => 'KIT-ESCRITORIO', 'name' => 'Kit Escritório', 'is_kit' => true,
            'structure_synced_at' => now(),
        ]);

        $this->espelho(self::REMOTO)->handle('KIT-CAMPANHA');

        $this->assertNotContains('KIT-ESCRITORIO', $this->consultados);
        $this->assertContains('KIT-CAMPANHA', $this->consultados);
    }

    /** Kit que se contém existe em cadastro real; a recursão precisa parar. */
    public function test_ciclo_na_estrutura_nao_trava_o_espelho(): void
    {
        $ciclico = [
            'KIT-A' => [
                'omie_code' => 'KIT-A', 'name' => 'Kit A', 'is_kit' => true, 'structure_checked' => true,
                'components' => ['KIT-B' => 1], 'component_names' => ['KIT-B' => 'Kit B'],
            ],
            'KIT-B' => [
                'omie_code' => 'KIT-B', 'name' => 'Kit B', 'is_kit' => true, 'structure_checked' => true,
                'components' => ['KIT-A' => 1], 'component_names' => ['KIT-A' => 'Kit A'],
            ],
        ];

        $kit = $this->espelho($ciclico)->handle('KIT-A');

        $this->assertNotNull($kit);
        $this->assertSame('KIT-B', $kit->components->first()->omie_code);
    }

    /** Meia estrutura é melhor que Projeto nenhum: o componente vira folha. */
    public function test_falha_em_um_componente_nao_derruba_o_kit(): void
    {
        $catalogo = self::REMOTO;
        $catalogo['KIT-ESCRITORIO'] = new RuntimeException('Consumo redundante detectado. Aguarde 51 segundos');

        $kit = $this->espelho($catalogo)->handle('KIT-CAMPANHA');

        $this->assertNotNull($kit);
        $this->assertCount(2, $kit->components);
        $this->assertSame('Kit Escritório', $kit->components->firstWhere('omie_code', 'KIT-ESCRITORIO')->name);
    }

    /** A tela recebe a árvore, não uma lista de um nível. */
    public function test_a_tela_do_projeto_recebe_a_arvore_completa(): void
    {
        $this->seed();

        $company = Company::create(['name' => 'ACME', 'cnpj' => '12345678000190']);
        $peca = Product::create(['omie_code' => 'CADERNO-A5', 'name' => 'Caderno A5']);
        $subKit = Product::create(['omie_code' => 'KIT-ESCRITORIO', 'name' => 'Kit Escritório', 'is_kit' => true]);
        $kit = Product::create(['omie_code' => 'KIT-CAMPANHA', 'name' => 'Kit Campanha', 'is_kit' => true]);

        $subKit->components()->attach($peca->id, ['quantity' => 2]);
        $kit->components()->attach($subKit->id, ['quantity' => 1]);

        $project = Project::create([
            'company_id' => $company->id,
            'name' => '66906 - ACME',
            'quantity' => 10,
            'pipeline_status' => Project::STATUS_ASSEMBLY,
        ]);
        $project->products()->attach($kit->id, ['quantity' => 10]);

        $this->actingAs(User::where('email', 'operador@welog.local')->firstOrFail())
            ->get(route('projects.show', $project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project.products.0.omie_code', 'KIT-CAMPANHA')
                ->where('project.products.0.components.0.omie_code', 'KIT-ESCRITORIO')
                ->where('project.products.0.components.0.is_kit', true)
                ->where('project.products.0.components.0.components.0.omie_code', 'CADERNO-A5')
                ->where('project.products.0.components.0.components.0.quantity', 2)
                ->etc()
            );
    }
}
