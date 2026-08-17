<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(); // etiqueta libre para el listado

            // Ruta. Null en cualquiera de los dos = "para toda sede", lo que
            // permite una tarifa base sin declarar el producto cartesiano de
            // sedes contra sedes.
            $table->foreignId('origin_branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->cascadeOnDelete();

            // Tipo de envío. Null = aplica a todos.
            $table->string('shipment_type', 20)->nullable(); // package | envelope | document

            // Rango de peso en kg, extremo superior EXCLUSIVO para que rangos
            // contiguos (0-1, 1-5) no se traslapen en el límite.
            $table->decimal('min_weight', 8, 2)->default(0);
            $table->decimal('max_weight', 8, 2)->nullable(); // null = sin tope

            $table->decimal('price', 12, 2);
            // Cobro adicional por kilo que pase de max_weight, para el tramo
            // abierto: evita tener que declarar rangos hasta el infinito.
            $table->decimal('price_per_extra_kg', 12, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['origin_branch_id', 'destination_branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
