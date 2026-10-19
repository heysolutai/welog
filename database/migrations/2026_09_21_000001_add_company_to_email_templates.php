<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo de e-mail POR EMPRESA, com o da WeGift como padrão.
 *
 * O texto que chega ao Colaborador é a voz de quem dá o presente, não a nossa:
 * "a Webox preparou um presente para você" escrito do jeito da Webox. Até aqui
 * havia um texto só para todas as empresas, editável apenas pelo Admin WeGift.
 *
 * `company_id` nulo = o modelo PADRÃO, o que a WeGift mantém e que vale para
 * quem não personalizou. A empresa que personaliza ganha uma linha própria com
 * a mesma `key`, e é essa que o envio usa. Apagar a linha da empresa é o
 * "restaurar padrão" — sem cópia de texto, sem sincronizar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            // `nullOnDelete` e não cascade: apagar a empresa não pode levar
            // junto o modelo padrão por acidente de um `company_id` errado —
            // e uma linha órfã reaparece como padrão, que é inofensivo.
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('email_templates', function (Blueprint $table) {
            // A chave deixa de ser única sozinha: agora é única POR empresa.
            $table->dropUnique('email_templates_key_unique');
            $table->unique(['key', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique(['key', 'company_id']);
            $table->dropConstrainedForeignId('company_id');
            $table->unique('key');
        });
    }
};
