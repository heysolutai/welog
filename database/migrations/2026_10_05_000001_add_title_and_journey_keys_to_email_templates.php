<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estúdio de mensagens do resgate.
 *
 * 1. `title`: o título do e-mail vira campo próprio. Ele vivia como a primeira
 *    linha "# ..." do corpo em Markdown — o que obrigava quem escreve a saber
 *    Markdown para trocar uma frase. Linhas antigas seguem com o título dentro
 *    do corpo e `title` nulo; o envio entende os dois jeitos.
 *
 * 2. "Pedido expedido" e "Pedido entregue" ganham modelo próprio. Os dois
 *    dividiam o mesmo `shipment_update`, e um texto que serve para "está a
 *    caminho" e para "chegou" não serve bem para nenhum dos dois.
 *
 * Os padrões das chaves novas nascem AQUI, e não só no seed: o deploy roda as
 * migrações, não o seed, e a tela lista a partir dos padrões gravados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('title')->nullable()->after('subject');
        });

        $definicoes = EmailTemplate::definitions();

        foreach ([EmailTemplate::KEY_REDEMPTION_SHIPPED, EmailTemplate::KEY_REDEMPTION_DELIVERED] as $chave) {
            $existe = DB::table('email_templates')
                ->where('key', $chave)
                ->whereNull('company_id')
                ->whereNull('project_id')
                ->exists();

            if (! $existe) {
                DB::table('email_templates')->insert([
                    'key' => $chave,
                    'name' => $definicoes[$chave]['name'],
                    'subject' => $definicoes[$chave]['subject'],
                    'title' => $definicoes[$chave]['title'],
                    'body' => $definicoes[$chave]['body'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->whereIn('key', [EmailTemplate::KEY_REDEMPTION_SHIPPED, EmailTemplate::KEY_REDEMPTION_DELIVERED])
            ->delete();

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
