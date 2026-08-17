<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Denomination;
use Illuminate\Database\Seeder;

/**
 * Denominaciones de Costa Rica y una caja por sede.
 *
 * Re-ejecutable: php artisan db:seed --class=Database\\Seeders\\CajaSeeder
 */
class CajaSeeder extends Seeder
{
    /** Billetes y monedas en circulación, del mayor al menor. */
    private const DENOMINACIONES = [20000, 10000, 5000, 2000, 1000, 500, 100, 50, 25, 10, 5];

    public function run(): void
    {
        foreach (self::DENOMINACIONES as $orden => $valor) {
            Denomination::firstOrCreate(
                ['value' => $valor],
                ['sort_order' => $orden, 'is_active' => true]
            );
        }

        foreach (Branch::all() as $sede) {
            CashRegister::firstOrCreate(
                ['branch_id' => $sede->id, 'name' => 'Caja principal'],
                ['is_active' => true]
            );
        }
    }
}
