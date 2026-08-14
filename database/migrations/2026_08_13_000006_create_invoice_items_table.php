<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paquetes/artículos que componen una factura de encomienda.
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->string('package_code'); // código/tracking del paquete
            $table->string('size', 20)->nullable();   // pequeño, mediano, grande, XL...
            $table->decimal('weight', 8, 2)->nullable(); // kg
            $table->string('description')->nullable();
            $table->decimal('price', 12, 5)->default(0); // precio de encomienda de este paquete
            $table->string('cabys_code', 13)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
