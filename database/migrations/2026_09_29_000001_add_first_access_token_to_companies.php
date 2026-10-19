<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O link que o Cliente publica na intranet dele.
 *
 * Empresa grande quer falar com o próprio pessoal pelos canais dela, e não por
 * um remetente que ninguém lá reconhece. Em vez de a WeLog escrever para nove
 * mil pessoas, ela entrega UM endereço; o RH publica, e quem tem brinde entra
 * por ali e pede o link de criar senha.
 *
 * Por Cliente e não por Projeto: a intranet publica uma vez e serve para todas
 * as campanhas seguintes. Trocar o endereço a cada projeto viraria trabalho
 * recorrente do RH deles, e link desatualizado em intranet é link que fica.
 *
 * `unique` porque é por ele que a requisição descobre de quem é a tela, e
 * `nullable` porque o token nasce quando alguém pede o link pela primeira vez
 * — Cliente que nunca vai usar não precisa carregar credencial à toa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('first_access_token', 64)->nullable()->unique()->after('customizations');
            $table->timestamp('first_access_token_at')->nullable()->after('first_access_token');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['first_access_token']);
            $table->dropColumn(['first_access_token', 'first_access_token_at']);
        });
    }
};
