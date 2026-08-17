<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos que necesita una nota de crédito/débito para apuntar al comprobante
 * que corrige (bloque InformacionReferencia del XML v4.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->foreignId('reference_invoice_id')->nullable()->after('invoice_id')
                ->constrained('electronic_invoices')->nullOnDelete();
            $table->string('reference_reason')->nullable()->after('reference_invoice_id');
            $table->json('note_lines')->nullable()->after('reference_reason');
        });
    }

    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reference_invoice_id');
            $table->dropColumn(['reference_reason', 'note_lines']);
        });
    }
};
