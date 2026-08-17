<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manifiesto de camión: agrupa las guías que salen en un viaje.
        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // CIE-000001

            $table->foreignId('origin_branch_id')->constrained('branches');
            $table->foreignId('destination_branch_id')->constrained('branches');

            $table->string('driver_name')->nullable();
            $table->string('vehicle_plate', 20)->nullable();

            // open        = armándose, admite guías
            // dispatched  = salió; las guías pasaron a "Enviado"
            // received    = se recibió en destino
            $table->string('status', 20)->default('open');

            $table->dateTime('departed_at')->nullable();
            $table->dateTime('received_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Detalle. La recepción se marca por fila: lo que quede sin marcar es
        // un faltante, que es el control que pide el requisito.
        Schema::create('dispatch_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained('dispatches')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->dateTime('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('incident')->nullable(); // faltante, dañado, sobrante

            $table->timestamps();

            // Una guía no puede ir dos veces en el mismo manifiesto.
            $table->unique(['dispatch_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_guides');
        Schema::dropIfExists('dispatches');
    }
};
