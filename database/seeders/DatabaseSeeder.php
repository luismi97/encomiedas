<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        CompanySetting::instance();

        $tax = Tax::firstOrCreate(
            ['name' => 'IVA general'],
            ['percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]
        );

        $sanJose = Branch::firstOrCreate(
            ['sucursal_code' => '001', 'terminal_code' => '00001'],
            ['name' => 'San José Central', 'address' => 'San José, Costa Rica', 'province' => '1', 'canton' => '01', 'district' => '01', 'is_active' => true]
        );

        $alajuela = Branch::firstOrCreate(
            ['sucursal_code' => '002', 'terminal_code' => '00001'],
            ['name' => 'Alajuela Centro', 'address' => 'Alajuela, Costa Rica', 'province' => '2', 'canton' => '01', 'district' => '01', 'is_active' => true]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@encomienda.test'],
            [
                'name' => 'Administrador',
                'username' => 'admin',
                'password' => bcrypt('password'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'repartidor@encomienda.test'],
            [
                'name' => 'Repartidor Demo',
                'username' => 'repartidor',
                'password' => bcrypt('password'),
                'role' => User::ROLE_REPARTIDOR,
                'branch_id' => $sanJose->id,
                'is_active' => true,
            ]
        );

        $this->call(DemoDataSeeder::class);
    }
}
