<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Caja física. Una sede puede tener varias (mostrador 1, mostrador 2).
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Turno: de la apertura al arqueo.
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches');

            $table->foreignId('opened_by')->constrained('users');
            $table->dateTime('opened_at');
            $table->decimal('opening_float', 12, 2)->default(0);

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();

            // Lo que la caja debería tener en efectivo según el sistema.
            $table->decimal('expected_cash', 12, 2)->default(0);
            // Lo que el cajero contó de verdad.
            $table->decimal('counted_cash', 12, 2)->default(0);
            // counted - expected. Negativo = faltante, positivo = sobrante.
            $table->decimal('discrepancy', 12, 2)->default(0);

            $table->string('status', 20)->default('open'); // open | closed
            $table->text('closing_note')->nullable();
            $table->timestamps();

            // Una caja no puede tener dos turnos abiertos: el índice lo impide
            // aunque dos pestañas den clic a la vez.
            $table->index(['cash_register_id', 'status']);
        });

        // Movimientos del turno. Todo cobro de contado cae aquí.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->cascadeOnDelete();

            // sale = cobro de guía; in/out = entradas y salidas de efectivo
            $table->string('type', 20);
            $table->string('payment_method', 20)->default('cash');
            $table->decimal('amount', 12, 2);

            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('reference')->nullable(); // código de guía o recibo
            $table->string('reason')->nullable();    // motivo de una entrada/salida

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('happened_at');
            $table->timestamps();

            $table->index(['cash_session_id', 'type']);
        });

        // Denominaciones para el arqueo. En colones importa: contar por billete
        // es lo que hace que el cajero no "cuadre" a ojo.
        Schema::create('denominations', function (Blueprint $table) {
            $table->id();
            $table->integer('value');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('value');
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->cascadeOnDelete();
            $table->foreignId('denomination_id')->constrained('denominations')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['cash_session_id', 'denomination_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('denominations');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
