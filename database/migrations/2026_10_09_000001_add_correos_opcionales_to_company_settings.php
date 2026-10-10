<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Los correos que no exige Hacienda, para apagarlos cuando el hosting
            // limita los envíos. Arrancan encendidos: es lo que hacía el sistema.
            $table->boolean('mail_copia_comprobantes')->default(true)->after('accountant_email');
            $table->boolean('mail_aviso_en_destino')->default(true)->after('mail_copia_comprobantes');
            $table->boolean('mail_aviso_por_desechar')->default(true)->after('mail_aviso_en_destino');
            $table->boolean('mail_aviso_entregado')->default(true)->after('mail_aviso_por_desechar');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['mail_copia_comprobantes', 'mail_aviso_en_destino', 'mail_aviso_por_desechar', 'mail_aviso_entregado']);
        });
    }
};
