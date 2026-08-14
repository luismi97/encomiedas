<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Impuestos configurables aplicables a las facturas de encomienda.
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('percent', 5, 2)->default(0);
            // Código de tarifa IVA de Hacienda (01 exento/0%, 02 1%, 03 2%, 04 4%, 08 13% general, 09 0.5%, 10 exento).
            $table->string('hacienda_code', 2)->default('08');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
