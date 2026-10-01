<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // En una sede puede haber quien solo recibe paquetes y quien cobra. En
        // true por defecto: todos los cajeros que ya existen siguen cobrando.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_collect')->default(true)->after('branch_id');
        });

        // Una guía de contado que recibió alguien que no cobra queda esperando
        // su pago en caja. Mientras esté así no es dinero recibido y el
        // paquete no puede salir.
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('awaiting_cashier')->default(false)->after('collected_at');
            $table->index(['pickup_branch_id', 'awaiting_cashier']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['pickup_branch_id', 'awaiting_cashier']);
            $table->dropColumn('awaiting_cashier');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_collect');
        });
    }
};
