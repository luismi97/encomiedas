<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Interruptor del modo sin conexión. Apagado por defecto: deja el
            // catálogo de tarifas y los clientes de crédito en el navegador, y
            // eso lo decide cada empresa.
            $table->boolean('offline_mode')->default(false)->after('enabled');

            // La clave de descuentos se guarda cifrada y solo el servidor la
            // descifra. Sin conexión hace falta algo que el navegador pueda
            // comprobar sin conocerla: un verificador PBKDF2.
            $table->string('discount_code_verifier')->nullable()->after('discount_authorization_code');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Identidad de una guía hecha sin conexión, generada en el navegador.
            // El índice único es lo que impide duplicarla por reintentos, dos
            // pestañas o dos equipos sincronizando la misma cola.
            $table->uuid('client_uuid')->nullable()->unique()->after('code');

            // El número provisional que llevó el comprobante impreso sin
            // conexión (OFF-…): con él se encuentra la guía cuando el cliente
            // vuelve con ese papel.
            $table->string('offline_reference', 40)->nullable()->after('client_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn(['client_uuid', 'offline_reference']);
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['offline_mode', 'discount_code_verifier']);
        });
    }
};
