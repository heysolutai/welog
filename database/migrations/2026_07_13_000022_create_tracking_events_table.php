<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos de rastreio agregados das transportadoras (módulo 9).
 * Polimórfico: pertence a um Redemption (Fluxo A) ou AppnozImportRow (Fluxo B).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->morphs('trackable');
            $table->timestamp('occurred_at');
            $table->string('status');
            $table->string('description');
            $table->string('location')->nullable();
            $table->timestamps();

            $table->unique(['trackable_type', 'trackable_id', 'occurred_at', 'status'], 'tracking_events_dedup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
