<?php

/**
 * Smoke test Fluxo B: gera um relatório appnoz no diretório de entrada,
 * consome, e percorre NF → rastreio Flash até a entrega.
 * Uso: php artisan tinker scripts/smoke/fluxo-b.php
 */

use App\Contracts\Integrations\FlashGateway;
use App\Contracts\Integrations\OmieGateway;
use App\Jobs\FluxoB\PollFlashTracking;
use App\Jobs\FluxoB\SyncOmieInvoices;
use App\Models\AppnozImportRow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

$csv = "cpf,nome,email,telefone,codigo_produto,produto,quantidade,cep,endereco,numero,complemento,bairro,cidade,uf\n"
    ."52998224725,José Pintor,jose@pintores.demo,41988887777,SUV-PINCEL-01,Kit Pincéis Suvinil,2,80010000,Rua das Tintas,45,,Centro,Curitiba,PR\n"
    ."15350946056,Ana Pintora,ana@pintores.demo,41977776666,PRODUTO-FANTASMA,Produto Inexistente,1,80020000,Av das Cores,90,,Batel,Curitiba,PR\n";

Storage::disk('local')->put('appnoz-inbox/smoke-'.time().'.csv', $csv);
echo "Arquivo salvo no diretório de entrada\n";

Artisan::call('welog:consume-appnoz-directory');
echo Artisan::output();

$rows = AppnozImportRow::latest('id')->limit(2)->get()->reverse();
foreach ($rows as $row) {
    echo "Linha {$row->row_number}: {$row->product_code} → {$row->status}"
        .($row->block_reason ? " ({$row->block_reason})" : '')
        .($row->omie_order_number ? " pedido {$row->omie_order_number} benefício {$row->benefit_code}" : '')
        ."\n";
}

// Ciclo fiscal + rastreio Flash da linha válida (sem envio de XML)
(new SyncOmieInvoices)->handle(app(OmieGateway::class));
(new PollFlashTracking)->handle(app(FlashGateway::class));
(new PollFlashTracking)->handle(app(FlashGateway::class));
(new PollFlashTracking)->handle(app(FlashGateway::class));

$row = AppnozImportRow::whereNotNull('omie_order_id')->latest('id')->first();
echo "NF: {$row->nf_key}\n";
echo "Rastreio Flash: {$row->tracking_code} — status final: {$row->status}\n";
echo 'Eventos de rastreio: '.$row->trackingEvents()->count()."\n";
