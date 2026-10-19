<?php

namespace App\Services\FluxoB;

use App\Models\AppnozImport;
use App\Models\User;
use App\Services\Support\SpreadsheetReader;
use Throwable;

/**
 * Módulo 3 — Fluxo B: carrega o relatório de resgates exportado da appnoz,
 * valida estruturalmente cada linha e processa (cadastro de cliente na Omie,
 * verificação de produto, benefício fiscal, criação do Pedido de Venda).
 *
 * Colunas esperadas (template appnoz): cpf, nome, email, telefone,
 * codigo_produto, produto, quantidade, cep, endereco, numero, complemento,
 * bairro, cidade, uf.
 *
 * Esta classe é só a porta do ARQUIVO. Validar e processar linha é trabalho
 * do IngestPartnerRows, compartilhado com a porta do webhook.
 */
class ImportAppnozFile
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly IngestPartnerRows $ingest,
    ) {}

    public function handle(string $filePath, string $source, ?User $importedBy = null): AppnozImport
    {
        $import = $this->ingest->abrir(
            basename($filePath),
            hash_file('sha256', $filePath),
            $source,
            $importedBy,
        );

        try {
            // A leitura fica DEPOIS de abrir: arquivo ilegível tem de deixar
            // uma importação `failed` na tela, não sumir sem rastro.
            $this->ingest->processar($import, $this->reader->read($filePath));
        } catch (Throwable $e) {
            $import->update(['status' => AppnozImport::STATUS_FAILED, 'error' => $e->getMessage()]);

            throw $e;
        }

        return $import;
    }
}
