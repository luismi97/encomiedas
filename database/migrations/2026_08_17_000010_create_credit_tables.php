<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estado de cuenta de un período: lo que se le factura al cliente de
        // crédito cuando llega su fecha de corte.
        Schema::create('credit_statements', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // EC-000001

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date')->nullable(); // vencimiento del plazo

            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);

            // issued = emitido y por cobrar; paid = saldado
            $table->string('status', 20)->default('issued');

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('due_date');
        });

        // Abonos a la cuenta. Un abono puede ir contra un estado de cuenta
        // concreto o a la cuenta general del cliente.
        Schema::create('credit_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('credit_statement_id')->nullable()->constrained('credit_statements')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->default('cash');
            $table->string('reference')->nullable();
            $table->dateTime('paid_at');

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'paid_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Condición de venta de ESTA guía. Estaba fija en configuración, y
            // con crédito deja de ser global: la misma empresa cobra de contado
            // en mostrador y a crédito a sus clientes con convenio.
            $table->string('sale_condition', 2)->default('01')->after('payment_method');
            // PlazoCredito: Hacienda lo exige cuando la condición es 02.
            $table->unsignedSmallInteger('credit_term_days')->nullable()->after('sale_condition');

            // Estado de cuenta que ya facturó esta guía. Null = todavía sin cortar.
            $table->foreignId('credit_statement_id')->nullable()->after('credit_term_days')
                ->constrained('credit_statements')->nullOnDelete();

            $table->index(['sale_condition', 'credit_statement_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_statement_id');
            $table->dropIndex(['sale_condition', 'credit_statement_id']);
            $table->dropColumn(['sale_condition', 'credit_term_days']);
        });

        Schema::dropIfExists('credit_payments');
        Schema::dropIfExists('credit_statements');
    }
};
