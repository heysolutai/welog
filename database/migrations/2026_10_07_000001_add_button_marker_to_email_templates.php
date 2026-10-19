<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O botão e o "Abraços, WeLog" deixam de ser fixos no molde do e-mail e
 * passam a fazer parte do texto: o botão vira o marcador {botao}, que o
 * Cliente posiciona, e a assinatura vira texto que ele edita.
 *
 * Cada mensagem gravada ganha, no fim, exatamente o que o molde acrescentava
 * até hoje. No dia do deploy, todo e-mail sai igual ao de ontem.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_templates')
            ->where('body', 'not like', '%'.EmailTemplate::BOTAO.'%')
            ->orderBy('id')
            ->each(fn ($linha) => DB::table('email_templates')
                ->where('id', $linha->id)
                ->update(['body' => rtrim($linha->body).EmailTemplate::FECHO]));
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('body', 'like', '%'.EmailTemplate::FECHO)
            ->orderBy('id')
            ->each(fn ($linha) => DB::table('email_templates')
                ->where('id', $linha->id)
                ->update(['body' => substr($linha->body, 0, -strlen(EmailTemplate::FECHO))]));
    }
};
