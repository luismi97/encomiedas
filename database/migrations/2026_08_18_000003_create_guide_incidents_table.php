<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Incidencias por guía: paquete dañado, dirección incorrecta,
        // destinatario ausente.
        //
        // Van aparte de la bitácora de estados porque una incidencia NO cambia
        // el estado: un destinatario ausente deja la guía donde está, y hay que
        // poder registrar el intento fallido sin mover el ciclo.
        Schema::create('guide_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('type', 30);
            $table->text('description');

            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reported_at');

            // Seguimiento: una incidencia abierta es trabajo pendiente.
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'reported_at']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_incidents');
    }
};
