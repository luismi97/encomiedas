<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // Dos sobres iguales van en una sola línea con cantidad 2. `price`
            // sigue siendo el total de la línea (precio por bulto × cantidad):
            // así caja, reportes y crédito, que suman `price`, no cambian.
            $table->unsignedSmallInteger('quantity')->default(1)->after('package_code');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
