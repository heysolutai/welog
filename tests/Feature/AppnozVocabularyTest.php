<?php

namespace Tests\Feature;

use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Services\FluxoB\ImportAppnozFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O relatório que a appnoz exporta de verdade, com os nomes de coluna dela.
 *
 * O template próprio nunca foi o que chega na prática: quem gera o arquivo é a
 * appnoz, e ela escreve "Usuário solicitante", "Rua", "ID Produto" e o estado
 * por extenso. Antes desta tradução, TODA linha do relatório real era
 * bloqueada por "UF deve ter 2 caracteres" — o campo certo apontado pelo
 * motivo errado, que é o tipo de bloqueio que ninguém resolve.
 *
 * O cabeçalho aqui é cópia fiel do export (aba not_despatched), inclusive a
 * coluna "Complemento" que aparece DUAS vezes.
 */
class AppnozVocabularyTest extends TestCase
{
    use RefreshDatabase;

    /** Cabeçalho literal do export da appnoz, na ordem em que ele sai. */
    private const CABECALHO = 'ID Produto,Nome do Produto,Valor do Produto,ID Solicitação,'
        .'Data de solicitação,Status,Usuário solicitante,CPF,Atividade,Complemento,'
        .'Telefone,Email,Data Nascimento,Rua,Número,Complemento,Bairro,CEP,Cidade,Estado,Tags';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed();
    }

    /**
     * Uma linha no formato da appnoz. Os valores acompanham o export real:
     * CPF e CEP pontuados, estado por extenso, sem coluna de quantidade.
     */
    private function linha(string $solicitacao = '125635', string $cpf = '314.159.265-90'): string
    {
        return implode(',', [
            'SUV-PINCEL-01', 'Kit Pincéis', ' 220.000', $solicitacao,
            '22/09/2026 às 23:09', 'solicitado', 'Fulano de Tal', $cpf,
            'Vendedores/Balconista', '',
            '(45)99999-1234', 'cliente@example.com', '31/01/1997', 'Rua Exemplo', '43',
            'casa 2', 'Morumbi', '80000-000', 'Cascavel', 'Paraná', 'Todos,Vendedores',
        ]);
    }

    private function importar(array $linhas): AppnozImport
    {
        $caminho = sys_get_temp_dir().'/appnoz-'.uniqid().'.csv';
        file_put_contents($caminho, implode("\n", [self::CABECALHO, ...$linhas])."\n");

        return app(ImportAppnozFile::class)->handle($caminho, AppnozImport::SOURCE_UPLOAD);
    }

    public function test_o_relatorio_real_da_appnoz_e_importado(): void
    {
        // A última coluna (Tags) tem vírgula no valor; o CSV do teste a corta,
        // e isso é aceitável: Tags é opcional e nada depende dela.
        $import = $this->importar([$this->linha()]);

        $this->assertSame(1, $import->rows_created, $import->rows()->first()?->block_reason ?? '');

        $row = $import->rows()->first();
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);

        // "Usuário solicitante" é o nome, "Rua" é o endereço, "ID Produto" é o código.
        $this->assertSame('Fulano de Tal', $row->customer_name);
        $this->assertSame('Rua Exemplo', $row->street);
        $this->assertSame('SUV-PINCEL-01', $row->product_code);

        // CPF e CEP chegam pontuados e entram limpos.
        $this->assertSame('31415926590', $row->cpf);
        $this->assertSame('80000000', $row->zip);

        // Sem coluna de quantidade: uma solicitação, um brinde.
        $this->assertSame(1, $row->quantity);
    }

    /** O estado vem por extenso e o cadastro da Omie quer a sigla. */
    public function test_estado_por_extenso_vira_sigla(): void
    {
        $import = $this->importar([$this->linha()]);

        $this->assertSame('PR', $import->rows()->first()->state);
    }

    /**
     * Duas colunas "Complemento": a da atividade, vazia, e a do endereço. Se a
     * vazia ganhar, a casa perde o "casa 2" e o brinde volta.
     */
    public function test_o_complemento_que_vale_e_o_do_endereco(): void
    {
        $import = $this->importar([$this->linha()]);

        $this->assertSame('casa 2', $import->rows()->first()->complement);
    }

    /**
     * O caso comum da operação: a mesma solicitação sai no relatório da manhã
     * e de novo no da tarde. Sem a trava do ID Solicitação, sai brinde duas
     * vezes — os dois arquivos são diferentes, a trava do lote não pega.
     */
    public function test_a_mesma_solicitacao_em_dois_relatorios_nao_vira_dois_pedidos(): void
    {
        $manha = $this->importar([$this->linha()]);
        $tarde = $this->importar([$this->linha(), $this->linha('125700', '113.421.316-65')]);

        $this->assertSame('125635', $manha->rows()->first()->external_id);

        // Do relatório da tarde entrou só a solicitação nova.
        $this->assertSame(1, $tarde->rows()->count());
        $this->assertSame('125700', $tarde->rows()->first()->external_id);

        $this->assertSame(2, AppnozImportRow::count());
    }

    /** O template próprio continua entrando sem mudar nada. */
    public function test_o_template_proprio_continua_valendo(): void
    {
        $caminho = sys_get_temp_dir().'/template-'.uniqid().'.csv';
        file_put_contents($caminho, implode("\n", [
            'cpf,nome,email,telefone,codigo_produto,produto,quantidade,cep,endereco,numero,complemento,bairro,cidade,uf',
            '52998224725,José Pintor,jose@pintores.com.br,41988887777,SUV-PINCEL-01,Kit Pincéis,2,80010000,Rua das Tintas,45,,Centro,Curitiba,PR',
        ])."\n");

        $import = app(ImportAppnozFile::class)->handle($caminho, AppnozImport::SOURCE_UPLOAD);

        $row = $import->rows()->first();
        $this->assertSame(AppnozImportRow::STATUS_ORDER_CREATED, $row->status);
        $this->assertSame(2, $row->quantity);
        $this->assertNull($row->external_id);
    }
}
