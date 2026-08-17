<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // El panel cuenta las entregadas de hoy en cada carga.
            $table->index('delivered_at');
            // Los listados y el panel del repartidor filtran por asignado.
            $table->index(['assigned_to', 'status']);
        });

        Schema::table('electronic_invoices', function (Blueprint $table) {
            // hacienda:poll pide los "sent" ordenados por updated_at cada minuto.
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['delivered_at']);
            $table->dropIndex(['assigned_to', 'status']);
        });

        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
        });
    }
};
