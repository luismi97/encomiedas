<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * Feriados de ley de Costa Rica.
 *
 * Se siembran por año porque varios se trasladan al lunes según la Ley 9875 y
 * no se pueden calcular con una regla fija.
 */
class FeriadosSeeder extends Seeder
{
    public function run(): void
    {
        $anio = (int) date('Y');

        $feriados = [
            "{$anio}-01-01" => 'Año Nuevo',
            "{$anio}-04-02" => 'Jueves Santo',
            "{$anio}-04-03" => 'Viernes Santo',
            "{$anio}-04-11" => 'Batalla de Rivas',
            "{$anio}-05-01" => 'Día del Trabajador',
            "{$anio}-07-25" => 'Anexión del Partido de Nicoya',
            "{$anio}-08-02" => 'Virgen de los Ángeles',
            "{$anio}-08-15" => 'Día de la Madre',
            "{$anio}-09-15" => 'Día de la Independencia',
            "{$anio}-12-01" => 'Abolición del Ejército',
            "{$anio}-12-25" => 'Navidad',
        ];

        foreach ($feriados as $fecha => $nombre) {
            Holiday::firstOrCreate(['date' => $fecha], ['name' => $nombre]);
        }
    }
}
