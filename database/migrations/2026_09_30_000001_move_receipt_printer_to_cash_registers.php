<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La impresora es de la caja, no de la sede: una sucursal con dos
        // mostradores puede tener una térmica en uno y una de impacto en el
        // otro. Y el tipo importa aparte del ancho: una de matriz de puntos
        // imprime con tan poca resolución que la letra fina y chica de la
        // térmica sale deshecha.
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->unsignedSmallInteger('receipt_paper_width')->default(80)->after('name');
            $table->string('receipt_printer', 10)->default('termica')->after('receipt_paper_width');
        });

        // Cada caja arranca con el rollo que tenía configurado su sede.
        foreach (DB::table('branches')->get(['id', 'receipt_paper_width']) as $sede) {
            DB::table('cash_registers')
                ->where('branch_id', $sede->id)
                ->update(['receipt_paper_width' => $sede->receipt_paper_width ?: 80]);
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('receipt_paper_width');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedSmallInteger('receipt_paper_width')->default(80)->after('phone');
        });

        // De vuelta a la sede, el de su primera caja.
        foreach (DB::table('cash_registers')->orderBy('id')->get(['branch_id', 'receipt_paper_width']) as $caja) {
            DB::table('branches')->where('id', $caja->branch_id)
                ->where('receipt_paper_width', 80)
                ->update(['receipt_paper_width' => $caja->receipt_paper_width]);
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropColumn(['receipt_paper_width', 'receipt_printer']);
        });
    }
};
