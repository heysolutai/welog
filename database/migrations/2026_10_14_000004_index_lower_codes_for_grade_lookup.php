<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices para as buscas da grade de tamanhos.
 *
 * A grade é achada pelo código: `LOWER(omie_code) LIKE 'prd11363%'` (os
 * tamanhos de um pai) e `LOWER(parent_code) = '…'` (o catálogo do parceiro).
 * Nenhum índice servia a essas expressões, e cada busca varria o catálogo
 * inteiro — que cresce a cada "Importar da Omie". O portal faz essa busca
 * para cada pessoa que abre a vitrine; com mil abrindo ao mesmo tempo, era a
 * primeira coisa a pesar no banco.
 *
 * Só no Postgres: índice de expressão com `text_pattern_ops` (o que faz o
 * LIKE com prefixo usar o índice) é coisa dele. O SQLite dos testes segue sem,
 * e o resultado das consultas não muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS products_omie_code_lower_prefix ON products (LOWER(omie_code) text_pattern_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS partner_skus_parent_code_lower ON partner_skus (LOWER(parent_code))');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS products_omie_code_lower_prefix');
        DB::statement('DROP INDEX IF EXISTS partner_skus_parent_code_lower');
    }
};
