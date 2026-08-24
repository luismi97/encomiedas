<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para cuando la tabla de guías deje de caber en memoria.
 *
 * Con decenas de miles de guías, el listado y los reportes filtran siempre por
 * fecha y casi siempre por sede. Sin un índice que cubra esas columnas, cada
 * carga recorre la tabla entera; con 50.000 filas todavía responde, pero el
 * tiempo crece linealmente y no hay aviso hasta que ya molesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // El listado ordena por fecha y filtra por rango.
            $table->index('created_at', 'invoices_created_at_index');

            // Los reportes y el listado acotan por sede además de por fecha.
            $table->index(['pickup_branch_id', 'created_at'], 'invoices_pickup_fecha_index');
            $table->index(['delivery_branch_id', 'created_at'], 'invoices_delivery_fecha_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('created_at', 'activity_logs_created_at_index');
        });

        Schema::table('customers', function (Blueprint $table) {
            // El buscador de clientes filtra por nombre y por cédula.
            $table->index(['is_active', 'name'], 'customers_activo_nombre_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_created_at_index');
            $table->dropIndex('invoices_pickup_fecha_index');
            $table->dropIndex('invoices_delivery_fecha_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_created_at_index');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_activo_nombre_index');
        });
    }
};
