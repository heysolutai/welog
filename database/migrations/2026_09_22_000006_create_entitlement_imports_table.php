<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O acompanhamento do cadastro em lote.
 *
 * A importação inteira acontecia dentro da requisição do upload: uma volta por
 * linha, e cada volta cria usuário, autorização, um token de convite (hash
 * bcrypt, ~60ms) e enfileira o e-mail. Uma planilha de nove mil colaboradores
 * são uns doze minutos — e o nginx corta a requisição aos cinco. Metade
 * entrava, o operador via 504 e não tinha como saber onde parou; subir o
 * arquivo de novo recomeçaria do zero.
 *
 * Agora a planilha é lida na requisição (isso é rápido) e o cadastro vai para
 * a fila em lotes. Esta tabela é o que a tela lê para dizer "3.200 de 9.000" e
 * onde os erros de linha esperam, em vez de viverem numa mensagem de sessão
 * que se perde no primeiro F5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Quem subiu: a planilha é processada em nome dele, e as regras de
            // alcance (só itens do pedido, só o Projeto dele) são as do ator.
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('filename');

            $table->unsignedInteger('total');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('admins')->default(0);

            /*
             * Erros de linha, acumulados. Limitado na escrita: uma planilha
             * inteira errada geraria nove mil mensagens, e nenhuma tela mostra
             * isso — as primeiras já dizem o que há de errado com o arquivo.
             */
            $table->json('errors')->nullable();

            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // A tela do Projeto pergunta "há importação rodando aqui?".
            $table->index(['project_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_imports');
    }
};
