<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El logo de la empresa.
 *
 * Va en el disco público y no dentro de la fila: una imagen en base64 en la base
 * se lee en cada consulta de configuración —que es de las más frecuentes— y se
 * arrastra a cada copia de seguridad. Acá solo queda la ruta.
 *
 * No va junto al certificado en el disco `hacienda`: ese es privado a propósito
 * y sirve para firmar. El logo, al revés, tiene que poder servirse por HTTP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('commercial_name');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
