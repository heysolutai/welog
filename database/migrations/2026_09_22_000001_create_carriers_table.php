<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transportadoras como cadastro, não como lista escrita no código.
 *
 * Eram três, fixas em quatro lugares diferentes: uma constante no modelo, um
 * `in:correios,mrb,ddm` na validação, um mapa de rótulos no front e um array
 * de "sem API" no componente de expedição. Contratar a quarta significava
 * abrir o editor — e acertar os quatro, senão ela aparecia na tela e era
 * recusada no servidor.
 *
 * `tracking_field` é a pergunta que o cliente fez por último: cada
 * transportadora entrega rastreio de um jeito. Umas dão um código, que a
 * WeLog transforma em link pelo `tracking_url_template`; outras dão o link
 * pronto, e aí não existe código para digitar. O operador vê o campo certo
 * porque a transportadora já disse qual é.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            // O que aparece na tela, e pode ser corrigido a qualquer momento.
            $table->string('name');
            /*
             * A identidade que os resgates guardam (`redemptions.carrier`).
             * Imutável depois de criada: renomear aqui apagaria a
             * transportadora de todo resgate já expedido, que continuaria
             * apontando para um slug que não existe mais.
             */
            $table->string('slug')->unique();
            // 'code' = operador digita o código; 'link' = digita a URL pronta.
            $table->string('tracking_field')->default('code');
            /*
             * Molde do link para transportadora de código, com {codigo} onde
             * ele entra. Nulo = há código, mas não há para onde levar, e a
             * tela mostra o código sem virar link.
             */
            $table->string('tracking_url_template')->nullable();
            /*
             * Contrato encerrado não pode sumir da tabela: os resgates antigos
             * precisam continuar dizendo por quem foram. Inativa some da
             * expedição e continua existindo no histórico.
             */
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        /*
         * As três que já estavam no código, com o comportamento de hoje: as
         * três trabalham por código. Sem esta carga, toda expedição existente
         * ficaria órfã e a tela de expedir abriria sem nenhuma opção.
         */
        DB::table('carriers')->insert([
            [
                'name' => 'Correios',
                'slug' => 'correios',
                'tracking_field' => 'code',
                'tracking_url_template' => 'https://rastreamento.correios.com.br/app/index.php?objeto={codigo}',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'MRB',
                'slug' => 'mrb',
                'tracking_field' => 'code',
                'tracking_url_template' => null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'DDM Log',
                'slug' => 'ddm',
                'tracking_field' => 'code',
                'tracking_url_template' => null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};
