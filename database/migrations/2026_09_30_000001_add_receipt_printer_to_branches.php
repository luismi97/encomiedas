<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Térmica o de matriz de puntos. No alcanza con el ancho: una de
            // impacto imprime a tan poca resolución que la letra fina y chica
            // que en térmica se lee perfecto sale deshecha.
            $table->string('receipt_printer', 10)->default('termica')->after('receipt_paper_width');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('receipt_printer');
        });
    }
};
