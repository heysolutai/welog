<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O terceiro nível da personalização: a campanha.
 *
 * Havia dois — o padrão da WeGift e o texto da empresa —, e a empresa é um
 * recorte grosso demais para quem faz várias campanhas por ano. O Natal não
 * fala como o kit de boas-vindas, e escrever um texto que sirva para os dois é
 * escrever um texto que não serve para nenhum.
 *
 * O envio usa o mais específico que existir: Projeto, senão Empresa, senão o
 * padrão. Nada é copiado de um nível para o outro — texto copiado congela no
 * dia da cópia e para de acompanhar as melhorias de cima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('company_id')
                ->constrained()
                // Projeto apagado leva junto o texto que só existia para ele.
                ->cascadeOnDelete();

            /*
             * A trava antiga era (key, company_id), de quando a empresa era o
             * nível mais fundo: com ela, a segunda campanha da mesma empresa
             * era recusada pelo banco — mesma chave, mesma empresa.
             *
             * A nova inclui o Projeto. Sem trava nenhuma, dois cadastros
             * simultâneos criariam duas linhas para o mesmo alcance e o envio
             * passaria a depender de qual o banco devolvesse primeiro.
             */
            $table->dropUnique(['key', 'company_id']);
            $table->unique(['key', 'company_id', 'project_id'], 'email_templates_escopo_unico');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique('email_templates_escopo_unico');
            $table->dropConstrainedForeignId('project_id');
            $table->unique(['key', 'company_id']);
        });
    }
};
