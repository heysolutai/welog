<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin de Projeto (feedback do UAT): novo papel `project_admin`, que enxerga
 * o que o Admin Cliente enxerga, mas escopado a UM Projeto. Ele tem company_id
 * E admin_project_id preenchidos; o Admin Geral do cliente segue admin_client
 * (company_id preenchido, admin_project_id nulo).
 *
 * `nullOnDelete`: excluir o Projeto não pode levar a conta junto; o admin fica
 * sem escopo (não vê Projeto nenhum) até ser revinculado ou removido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('admin_project_id')->nullable()->after('company_id')
                ->constrained('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_project_id');
        });
    }
};
