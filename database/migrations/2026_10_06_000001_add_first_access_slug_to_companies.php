<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link de primeiro acesso com o nome do Cliente: /primeiro-acesso/sotreq.
 *
 * Era um código aleatório de 48 caracteres, trocável a qualquer momento. Para
 * o Cliente publicar na intranet, um endereço que se lê e se lembra vale mais:
 * e a porta já recusa e-mail que não esteja cadastrado naquela empresa, então
 * conhecer o endereço não abre nada. Um endereço por Cliente, para sempre.
 *
 * O código antigo continua aceito (FirstAccessController): link já publicado
 * não pode virar 404 no dia do deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('first_access_slug')->nullable()->unique()->after('first_access_token');
        });

        Company::query()->orderBy('id')->each(fn (Company $empresa) => $empresa->slugDePrimeiroAcesso());
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['first_access_slug']);
            $table->dropColumn('first_access_slug');
        });
    }
};
