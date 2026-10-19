<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado do convite/primeiro acesso (Fase 1 — autenticação do cliente final).
 * invited_at: quando o convite foi (re)enviado.
 * password_set_at: null enquanto o usuário não define a própria senha —
 * até lá o login por senha é bloqueado (só entra pelo link de convite/reset).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('active');
            $table->timestamp('password_set_at')->nullable()->after('invited_at');
        });

        // Usuários já existentes (seed WeGift) têm senha utilizável definida.
        \Illuminate\Support\Facades\DB::table('users')->update(['password_set_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'password_set_at']);
        });
    }
};
