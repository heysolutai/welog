<?php

namespace App\Services\Support;

use App\Exceptions\DomainException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\Exception\ReaderException;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetIteratorInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Leitura de planilhas (XLSX/CSV) normalizada em linhas associativas,
 * usando o cabeçalho da primeira linha como chaves (minúsculas, sem acento).
 *
 * O formato é detectado pela assinatura do arquivo, não pela extensão: os
 * uploads chegam em caminhos temporários sem extensão (`getRealPath()`), e um
 * CSV lido como XLSX quebra a importação inteira.
 */
class SpreadsheetReader
{
    /** Assinatura ZIP — todo XLSX é um container OOXML compactado. */
    private const ZIP_SIGNATURE = "PK\x03\x04";

    /** Delimitadores aceitos em CSV, em ordem de preferência na desempate. */
    private const CSV_DELIMITERS = [',', ';', "\t"];

    /** Linhas vazias seguidas, depois dos dados, que encerram a leitura. */
    private const FIM_DOS_DADOS = 500;

    /**
     * @return array<int, array<string, string>> linhas (1-indexadas pela ordem no arquivo)
     */
    public function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new DomainException('Não foi possível ler o arquivo enviado.');
        }

        $reader = $this->isXlsx($path)
            ? new XlsxReader
            : new CsvReader(new CsvOptions(
                FIELD_DELIMITER: $this->detectDelimiter($path),
                ENCODING: $this->detectEncoding($path),
            ));

        try {
            $reader->open($path);

            return $this->extractRows($reader);
        } catch (IOException|ReaderException $e) {
            throw new DomainException(
                'Arquivo inválido ou corrompido. Envie uma planilha .xlsx ou .csv no formato do template.',
                previous: $e,
            );
        } finally {
            $reader->close();
        }
    }

    /**
     * Lê a primeira aba que tem conteúdo.
     *
     * Planilha do Google Sheets costuma chegar com uma "Página1" vazia na
     * frente e os dados na aba seguinte. Ler só a primeira aba devolvia
     * "planilha vazia" com os dados ali, uma aba ao lado.
     *
     * @param  ReaderInterface<SheetIteratorInterface>  $reader
     * @return array<int, array<string, string>>
     */
    private function extractRows(ReaderInterface $reader): array
    {
        $rows = [];
        $header = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            $vaziasSeguidas = 0;

            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(
                    fn ($value) => trim(is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value)),
                    $row->toArray(),
                );

                if (implode('', $cells) === '') {
                    // Linha totalmente vazia, antes ou depois do cabeçalho.
                    //
                    // Coluna formatada inteira no Excel grava até um milhão de
                    // linhas "vazias com formato", e ler todas levava minutos.
                    // Depois dos dados, uma sequência longa de vazias é o fim.
                    if ($header !== null && ++$vaziasSeguidas >= self::FIM_DOS_DADOS) {
                        break;
                    }

                    continue;
                }

                $vaziasSeguidas = 0;

                if ($header === null) {
                    $header = array_map(self::chaveDeCabecalho(...), $cells);

                    continue;
                }

                $rows[] = array_combine(
                    $header,
                    array_pad(array_slice($cells, 0, count($header)), count($header), ''),
                );
            }

            // Achou o cabeçalho: esta é a aba. As demais ficam de fora.
            if ($header !== null) {
                break;
            }
        }

        return $rows;
    }

    private function isXlsx(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException('Não foi possível ler o arquivo enviado.');
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === self::ZIP_SIGNATURE;
    }

    /**
     * Excel em pt-BR exporta CSV com `;`. Detecta pelo cabeçalho: vence o
     * delimitador que produz mais colunas na primeira linha não vazia.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException('Não foi possível ler o arquivo enviado.');
        }

        $firstLine = '';
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $firstLine = $line;
                break;
            }
        }
        fclose($handle);

        $best = self::CSV_DELIMITERS[0];
        $bestCount = 0;

        foreach (self::CSV_DELIMITERS as $delimiter) {
            $count = substr_count($firstLine, $delimiter);

            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * UTF-8 ou Windows-1252.
     *
     * O Excel em português salva "CSV (separado por vírgulas)" em
     * Windows-1252, não em UTF-8. Lido como UTF-8, "João" chegava com um byte
     * inválido no lugar do "ã", e o Postgres recusava gravar a linha. Arquivo
     * que não é UTF-8 válido é lido como Windows-1252, que é o que o Excel usa.
     */
    private function detectEncoding(string $path): string
    {
        $conteudo = file_get_contents($path);

        return $conteudo !== false && ! mb_check_encoding($conteudo, 'UTF-8') ? 'Windows-1252' : 'UTF-8';
    }

    /**
     * A chave que um texto de cabeçalho vira depois de lido.
     *
     * Pública porque quem gera cabeçalho a partir de dado (as colunas de item
     * do modelo, com o nome de cada produto) precisa saber exatamente em que
     * chave ele vai voltar — duas normalizações parecidas divergiriam no
     * primeiro acento que só uma delas conhece.
     */
    public static function chaveDeCabecalho(string $value): string
    {
        // BOM UTF-8 no início do arquivo contamina a primeira coluna do CSV
        $value = mb_strtolower(trim(str_replace("\u{FEFF}", '', $value)));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ]);

        return str_replace([' ', '-'], '_', $value);
    }
}
