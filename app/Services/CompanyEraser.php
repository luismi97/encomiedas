<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Borra una empresa y todo lo suyo.
 *
 * Esto NO es lo que se le hace a un cliente que se va: a ese se le suspende,
 * porque sus comprobantes están transmitidos a Hacienda y tienen que poder
 * consultarse. Esto es para deshacer una empresa cargada por error.
 *
 * Se borra tabla por tabla, en orden, en lugar de confiar en la cascada de la
 * llave foránea de `company_id`, por dos razones:
 *
 *  1. MySQL no promete en qué orden ejecuta las cascadas, y unas pocas tablas
 *     apuntan a sedes y usuarios con RESTRICT —una guía no puede quedarse sin
 *     sede de destino, un turno de caja no puede quedarse sin quién lo abrió—:
 *     intenta borrar las sedes mientras las guías todavía las referencian y
 *     aborta a mitad de camino.
 *  2. En SQLite —donde corren las pruebas— esa llave foránea ni siquiera
 *     existe: no se puede agregar a una tabla ya creada. Confiando en la
 *     cascada, el borrado pasaría en producción y dejaría todo en pie en las
 *     pruebas, que es la peor combinación posible.
 *
 * Las tablas hijas puras —renglones de una guía, conteos de un arqueo, guías de
 * un cierre— no están en la lista: esas SÍ cuelgan de su padre con una cascada
 * declarada al crear la tabla, y esa funciona en los dos motores.
 */
class CompanyEraser
{
    /**
     * De la que más depende a la que menos.
     *
     * El orden es el que exigen las llaves con RESTRICT:
     *   · quotes e invoices antes que users (created_by);
     *   · invoices, dispatches y cash_sessions antes que branches;
     *   · cash_sessions antes que cash_registers.
     * Lo demás va después porque ya no estorba.
     */
    private const EN_ORDEN = [
        'quotes',
        'invoices',
        'dispatches',
        'cash_sessions',
        'cash_registers',
        'credit_statements',
        'customers',
        'rates',
        'activity_logs',
        'print_logs',
        'guide_incidents',
        'electronic_invoices',
        'electronic_billing_sequences',
        'guide_sequences',
        'package_types',
        'denominations',
        'taxes',
        'holidays',
        'company_settings',
        'users',
        'branches',
    ];

    public function borrar(Company $empresa): void
    {
        DB::transaction(function () use ($empresa) {
            foreach (self::EN_ORDEN as $tabla) {
                if (! Schema::hasColumn($tabla, 'company_id')) {
                    continue;
                }

                // Consulta cruda y no Eloquent: son borrados masivos y no hay
                // observador que deba correr sobre algo que está por dejar de
                // existir.
                DB::table($tabla)->where('company_id', $empresa->id)->delete();
            }

            $empresa->delete();
        });
    }
}
