<?php

use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le pone prefijo a las sedes que quedaron sin él.
 *
 * La migración que agregó la columna rellenó solo las sedes que existían en ese
 * momento; los seeders siguieron creando sedes sin prefijo. En una instalación
 * nueva —migrar y después sembrar— todas quedaban en NULL, y como el observer
 * cae a un código de respaldo cuando no puede armar el de ruta, las guías se
 * registraban como ENC-000045 en vez de SJ-LIM-00005, sin ningún error visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sinPrefijo = DB::table('branches')
            ->where(fn ($q) => $q->whereNull('prefix')->orWhere('prefix', ''))
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($sinPrefijo as $sede) {
            DB::table('branches')->where('id', $sede->id)->update([
                'prefix' => Branch::prefijoSugerido($sede->name, $sede->id),
            ]);
        }
    }

    public function down(): void
    {
        // No se revierte: sin prefijo la sede no puede emitir códigos guía.
    }
};
