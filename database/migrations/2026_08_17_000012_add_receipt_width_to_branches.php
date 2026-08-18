<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Ancho del rollo de la térmica de esta sede, en milímetros. Es por
            // sede porque cada mostrador compra la impresora que consigue.
            $table->unsignedSmallInteger('receipt_paper_width')->default(80)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('receipt_paper_width');
        });
    }
};
