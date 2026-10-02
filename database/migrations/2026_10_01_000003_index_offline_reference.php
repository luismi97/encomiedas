<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La referencia provisional se volvió algo que se busca: el cliente que se
     * fue con el comprobante sin conexión rastrea con ella, y el mostrador la
     * encuentra cuando vuelve con ese papel.
     *
     * Índice común y no único: las referencias viejas (OFF-SJ-7) eran un
     * consecutivo por navegador y se repiten. Las nuevas son al azar, y el
     * rastreo pregunta si alguna vez coinciden dos.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['company_id', 'offline_reference'], 'invoices_empresa_referencia_offline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_empresa_referencia_offline_idx');
        });
    }
};
