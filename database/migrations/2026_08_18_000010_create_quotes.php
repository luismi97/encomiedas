<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proformas: cotizaciones que se le pasan a un cliente y NO se facturan.
 *
 * Van en su propia tabla y no como una guía en estado «borrador» a propósito:
 * una guía consume consecutivo de ruta, entra en los reportes de venta y puede
 * terminar en un comprobante ante Hacienda. Una proforma no es nada de eso
 * hasta que el cliente acepta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // COT-000001

            $table->foreignId('origin_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 30)->nullable();

            $table->string('shipment_type')->nullable();
            $table->text('notes')->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Una cotización sin fecha de vencimiento es una promesa eterna:
            // el combustible y las tarifas cambian.
            $table->date('valid_until')->nullable();

            // Cuándo se le mandó al cliente y a qué correo: sin esto nadie sabe
            // si se envió, y se manda dos veces o ninguna.
            $table->dateTime('sent_at')->nullable();
            $table->string('sent_to')->nullable();

            // Si se convirtió en una guía real, cuál.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['created_at', 'invoice_id']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('package_type_id')->nullable()->constrained('package_types')->nullOnDelete();

            $table->string('description')->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
