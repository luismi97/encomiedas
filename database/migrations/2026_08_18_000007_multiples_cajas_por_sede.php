<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Habilita varias cajas por sede: «Mostrador 1», «Mostrador 2».
 *
 * Dos cosas hacían falta antes de dejar administrarlas desde la interfaz:
 *
 *  1. Nombre único dentro de la sede. Con dos «Mostrador 1» en San José, el
 *     cajero no puede saber en cuál está abriendo el turno.
 *  2. Que borrar una caja no arrastre su historial. La llave venía en cascada,
 *     así que eliminar una caja borraba en silencio sus turnos, movimientos y
 *     arqueos —incluidos los cerrados y firmados—.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Desempata los nombres repetidos que pudieran existir antes de poder
        // crear el índice: sin esto la migración revienta a mitad.
        $repetidos = DB::table('cash_registers')
            ->select('branch_id', 'name', DB::raw('COUNT(*) as total'))
            ->groupBy('branch_id', 'name')
            ->having('total', '>', 1)
            ->get();

        foreach ($repetidos as $grupo) {
            $filas = DB::table('cash_registers')
                ->where('branch_id', $grupo->branch_id)
                ->where('name', $grupo->name)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1); // La primera conserva el nombre original.

            foreach ($filas as $posicion => $id) {
                DB::table('cash_registers')->where('id', $id)->update([
                    'name' => $grupo->name . ' (' . ($posicion + 2) . ')',
                ]);
            }
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            $table->unique(['branch_id', 'name']);
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropForeign(['cash_register_id']);
            // restrict y no cascade: un turno cerrado es un documento contable.
            $table->foreign('cash_register_id')->references('id')->on('cash_registers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropForeign(['cash_register_id']);
            $table->foreign('cash_register_id')->references('id')->on('cash_registers')->cascadeOnDelete();
        });

        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'name']);
        });
    }
};
