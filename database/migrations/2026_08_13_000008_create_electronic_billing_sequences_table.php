<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consecutivos de Hacienda: uno por sucursal + tipo de comprobante.
        Schema::create('electronic_billing_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('document_type', 2);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_billing_sequences');
    }
};
