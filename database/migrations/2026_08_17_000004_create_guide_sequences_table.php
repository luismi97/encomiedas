<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consecutivo del código guía por par de ruta: SJ-LIM-00005.
        //
        // Va en tabla y no en un COUNT sobre invoices porque dos sedes pueden
        // emitir a la vez: el contador se reserva bajo candado, igual que ya se
        // hace con los consecutivos de Hacienda.
        Schema::create('guide_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('origin_prefix', 4);
            $table->string('destination_prefix', 4);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['origin_prefix', 'destination_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_sequences');
    }
};
