<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // A quién se le emite la Factura Electrónica. En recipient por
            // defecto: es como salían todas hasta ahora.
            $table->string('bill_to', 10)->default('recipient')->after('bill_type');

            // El remitente solo tenía la cédula suelta: para facturarle hace
            // falta el tipo, y el correo para mandarle el comprobante.
            $table->string('sender_identification_type', 2)->nullable()->after('sender_phone');
            $table->string('sender_email')->nullable()->after('sender_identification');

            // Un tercero que paga y no es ni quien envía ni quien recibe.
            $table->string('billing_name')->nullable()->after('bill_to');
            $table->string('billing_identification_type', 2)->nullable()->after('billing_name');
            $table->string('billing_identification')->nullable()->after('billing_identification_type');
            $table->string('billing_email')->nullable()->after('billing_identification');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'bill_to', 'sender_identification_type', 'sender_email',
                'billing_name', 'billing_identification_type', 'billing_identification', 'billing_email',
            ]);
        });
    }
};
