<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Elección explícita del tipo de comprobante. Antes se deducía de si
            // había identificación: quien la digitaba "por si acaso" terminaba
            // emitiendo una FE sin quererlo, y no se podía pedir la cédula como
            // obligatoria porque no había forma de saber si hacía falta.
            $table->enum('bill_type', ['ticket', 'invoice'])->default('ticket')->after('status');
        });

        // Las encomiendas ya registradas conservan el criterio con el que se
        // crearon: con identificación eran factura, sin ella tiquete.
        DB::table('invoices')
            ->whereNotNull('recipient_identification')
            ->where('recipient_identification', '!=', '')
            ->update(['bill_type' => 'invoice']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('bill_type');
        });
    }
};
