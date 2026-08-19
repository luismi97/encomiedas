<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue el flete pagado en origen del flete por cobrar en destino.
 *
 * Es una distinción propia de encomiendas que el sistema no tenía: toda guía
 * se registraba como cobrada en el mostrador de origen, aunque el trato fuera
 * que pagara el destinatario al retirarla. Sin este dato, el cobro entraba al
 * arqueo de una caja donde el dinero nunca estuvo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // prepaid = el remitente pagó al despachar
            // collect = paga el destinatario al retirar
            $table->string('payment_timing', 10)->default('prepaid')->after('payment_method');
            // Fecha en que se cobró un «por cobrar»: hasta entonces es un
            // cobro pendiente y no un ingreso.
            $table->dateTime('collected_at')->nullable()->after('payment_timing');

            $table->index(['payment_timing', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['payment_timing', 'collected_at']);
            $table->dropColumn(['payment_timing', 'collected_at']);
        });
    }
};
