<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfis de acesso (módulo 10): admin_wegift, wegift_n1, wegift_n2,
 * admin_client (admin da empresa cliente) e end_customer (cliente final).
 * Clientes finais e admins de cliente pertencem a uma company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('end_customer')->after('password');
            $table->foreignId('company_id')->nullable()->after('role')
                ->constrained()->nullOnDelete();
            $table->string('cpf', 11)->nullable()->after('company_id');
            $table->string('phone')->nullable()->after('cpf');
            $table->boolean('active')->default(true)->after('phone');

            $table->index(['company_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'cpf', 'phone', 'active']);
        });
    }
};
