<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // CodigoActividadReceptor de la Factura Electrónica. Es de a quién
            // se factura (remitente, destinatario u otra persona), así que va
            // una sola vez y no por persona.
            $table->string('billing_activity_code', 10)->nullable()->after('billing_email');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('billing_activity_code');
        });
    }
};
