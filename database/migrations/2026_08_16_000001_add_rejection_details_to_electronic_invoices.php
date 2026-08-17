<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            // El detalle del rechazo venia aplastado en error_message, unido con
            // " | ": se perdian el codigo y la descripcion de cada error, que es
            // justo lo que se necesita para saber que corregir.
            $table->json('rejection_details')->nullable()->after('error_message');
            $table->dateTime('rejected_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropColumn(['rejection_details', 'rejected_at']);
        });
    }
};
