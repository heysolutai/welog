<?php

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Services\Support\SpreadsheetReader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * O leitor recebe caminhos temporários de upload (`getRealPath()`), que **não
 * têm extensão** — daí a detecção de formato ser pela assinatura do arquivo.
 */
class SpreadsheetReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function tempFile(string $contents = ''): string
    {
        // Sem sufixo: reproduz exatamente o caminho de um upload do PHP.
        $path = tempnam(sys_get_temp_dir(), 'welog-sheet-');
        $this->tempFiles[] = $path;

        if ($contents !== '') {
            file_put_contents($path, $contents);
        }

        return $path;
    }

    public function test_le_csv_em_arquivo_temporario_sem_extensao(): void
    {
        $path = $this->tempFile(
            "nome,email,cpf,telefone,codigo_kit\n"
            ."Maria Silva,maria@acme.com.br,52998224725,41999990000,KIT-A\n"
        );

        $rows = (new SpreadsheetReader)->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('Maria Silva', $rows[0]['nome']);
        $this->assertSame('maria@acme.com.br', $rows[0]['email']);
        $this->assertSame('KIT-A', $rows[0]['codigo_kit']);
    }

    /** "CSV (separado por vírgulas)" do Excel em português sai em Windows-1252. */
    public function test_le_csv_em_windows_1252_do_excel(): void
    {
        $path = $this->tempFile(mb_convert_encoding("nome;email\nJoão Conceição;joao@acme.com.br\n", 'Windows-1252', 'UTF-8'));

        $rows = (new SpreadsheetReader)->read($path);

        $this->assertSame('João Conceição', $rows[0]['nome']);
    }

    /** Depois dos dados, uma sequência longa de linhas vazias é o fim da planilha. */
    public function test_para_de_ler_depois_de_muitas_linhas_vazias(): void
    {
        $path = $this->tempFile("nome;email\nAna;ana@acme.com.br\n".str_repeat(";\n", 600)."Fantasma;fantasma@acme.com.br\n");

        $this->assertSame(['Ana'], array_column((new SpreadsheetReader)->read($path), 'nome'));
    }

    public function test_le_csv_com_ponto_e_virgula_do_excel_pt_br(): void
    {
        $path = $this->tempFile(
            "nome;email;cpf;telefone;codigo_kit\n"
            ."Pedro Souza;pedro@acme.com.br;;;KIT-B\n"
        );

        $rows = (new SpreadsheetReader)->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('Pedro Souza', $rows[0]['nome']);
        $this->assertSame('KIT-B', $rows[0]['codigo_kit']);
    }

    public function test_normaliza_cabecalho_com_acento_bom_e_espaco(): void
    {
        $path = $this->tempFile(
            "\u{FEFF}Nome,E-mail,Código Kit\n"
            ."Ana,ana@acme.com.br,KIT-C\n"
        );

        $rows = (new SpreadsheetReader)->read($path);

        $this->assertSame(['nome', 'e_mail', 'codigo_kit'], array_keys($rows[0]));
        $this->assertSame('Ana', $rows[0]['nome']);
    }

    public function test_ignora_linhas_totalmente_vazias(): void
    {
        $path = $this->tempFile(
            "nome,email\n"
            ."Ana,ana@acme.com.br\n"
            .",\n"
            ."Bruno,bruno@acme.com.br\n"
        );

        $this->assertCount(2, (new SpreadsheetReader)->read($path));
    }

    public function test_le_xlsx_pela_assinatura_mesmo_sem_extensao(): void
    {
        $path = $this->tempFile();

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['nome', 'email', 'codigo_kit']));
        $writer->addRow(Row::fromValues(['Carla Dias', 'carla@acme.com.br', 'KIT-D']));
        $writer->close();

        $rows = (new SpreadsheetReader)->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('Carla Dias', $rows[0]['nome']);
        $this->assertSame('KIT-D', $rows[0]['codigo_kit']);
    }

    /** Google Sheets: "Página1" vazia na frente, dados na aba seguinte. */
    public function test_xlsx_com_primeira_aba_vazia_le_a_aba_com_dados(): void
    {
        $path = $this->tempFile();

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addNewSheetAndMakeItCurrent();
        $writer->addRow(Row::fromValues(['nome', 'email', 'funcao', 'telefone']));
        $writer->addRow(Row::fromValues(['Ana', 'ana@acme.com.br', 'Colaborador', '(41) 99999-0000']));
        $writer->close();

        $rows = (new SpreadsheetReader)->read($path);

        $this->assertCount(1, $rows);
        $this->assertSame('ana@acme.com.br', $rows[0]['email']);
    }

    public function test_arquivo_corrompido_vira_erro_de_dominio_e_nao_500(): void
    {
        // Assinatura ZIP sem conteúdo OOXML válido: parece XLSX, não abre.
        $path = $this->tempFile("PK\x03\x04lixo binário");

        $this->expectException(DomainException::class);

        (new SpreadsheetReader)->read($path);
    }

    public function test_arquivo_inexistente_vira_erro_de_dominio(): void
    {
        $this->expectException(DomainException::class);

        (new SpreadsheetReader)->read(sys_get_temp_dir().'/welog-nao-existe-'.uniqid());
    }
}
