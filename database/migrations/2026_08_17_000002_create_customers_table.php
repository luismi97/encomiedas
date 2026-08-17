<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name');                        // nombre o razón social
            $table->string('commercial_name')->nullable();

            // Mismo catálogo que usa Hacienda para el receptor, para que un
            // cliente de crédito se pueda facturar sin volver a digitar nada.
            $table->string('identification_type', 2)->nullable();
            $table->string('identification', 20)->nullable();
            $table->string('activity_code', 6)->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();

            // Sede habitual: precarga el origen al crear una guía.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('payment_condition', 10)->default('cash'); // cash | credit
            $table->decimal('credit_limit', 12, 2)->default(0);
            // Día del mes en que se corta la cuenta. Null = usa el global.
            $table->unsignedTinyInteger('credit_cutoff_day')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Una cédula no se puede repetir, pero sí puede faltar: hay clientes
            // de contado que solo dejan nombre y teléfono.
            $table->unique('identification');
            $table->index(['payment_condition', 'is_active']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
