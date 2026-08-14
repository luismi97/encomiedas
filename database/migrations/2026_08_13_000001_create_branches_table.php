<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Puntos de recogida/entrega de encomiendas a nivel nacional.
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Códigos usados en el consecutivo/clave de Hacienda (sucursal + terminal).
            $table->string('sucursal_code', 3)->default('001');
            $table->string('terminal_code', 5)->default('00001');
            $table->string('address')->nullable();
            $table->string('province', 1)->nullable();
            $table->string('canton', 2)->nullable();
            $table->string('district', 2)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['sucursal_code', 'terminal_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
