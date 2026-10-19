<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Número do pedido dos resgates sem os zeros à esquerda.
 *
 * A resposta do IncluirPedido traz o número preenchido com zeros
 * ("000000000070669") e ele era gravado assim; a tela da Omie mostra 70669, e
 * a busca por esse número não achava o resgate. O gateway já grava sem os
 * zeros; aqui ficam iguais os que entraram antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('appnoz_import_rows')
            ->where('omie_order_number', 'like', '0%')
            ->orderBy('id')
            ->each(function (object $row) {
                $numero = ltrim((string) $row->omie_order_number, '0');

                if ($numero !== '') {
                    DB::table('appnoz_import_rows')->where('id', $row->id)->update(['omie_order_number' => $numero]);
                }
            });
    }

    public function down(): void
    {
        // Sem volta: os zeros não carregavam informação nenhuma.
    }
};
