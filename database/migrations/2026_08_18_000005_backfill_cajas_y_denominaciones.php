<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deja utilizable el módulo de caja en las bases ya instaladas.
 *
 * Las cajas y las denominaciones solo se creaban en CajaSeeder, al que
 * DatabaseSeeder nunca llamaba: después de un `migrate` + `db:seed` normal la
 * pantalla de caja quedaba con el selector vacío y el turno no se podía abrir.
 * Los seeders no corren en producción, así que el relleno va aquí.
 *
 * Es reversible en el sentido que importa: no borra nada al bajar, porque para
 * entonces ya puede haber turnos y arqueos colgando de estas filas.
 */
return new class extends Migration
{
    /** Billetes y monedas de Costa Rica en circulación, del mayor al menor. */
    private const DENOMINACIONES = [20000, 10000, 5000, 2000, 1000, 500, 100, 50, 25, 10, 5];

    public function up(): void
    {
        $ahora = now();

        foreach (self::DENOMINACIONES as $orden => $valor) {
            DB::table('denominations')->updateOrInsert(
                ['value' => $valor],
                ['sort_order' => $orden, 'is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora]
            );
        }

        // Solo las sedes que no tienen ninguna: quien ya corrió el seeder no
        // termina con dos «Caja principal».
        $sinCaja = DB::table('branches')
            ->whereNotIn('id', DB::table('cash_registers')->distinct()->pluck('branch_id'))
            ->pluck('id');

        foreach ($sinCaja as $branchId) {
            DB::table('cash_registers')->insert([
                'branch_id'  => $branchId,
                'name'       => 'Caja principal',
                'is_active'  => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        // A propósito no borra: una caja puede tener turnos cerrados y arqueos
        // firmados, y esos no se tiran para revertir un relleno.
    }
};
