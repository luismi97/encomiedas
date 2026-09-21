<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Support\CompanyContext;
use Illuminate\Database\Seeder;

/**
 * Datos de desarrollo.
 *
 * Deja DOS empresas a propósito. Con una sola, el aislamiento parece funcionar
 * aunque esté roto: todo lo que se consulta es de la única que hay. La segunda
 * —con su propia sede «SJ», su propio consecutivo y su propio IVA— es la que
 * delata una consulta que se olvidó de filtrar.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $superadmin = $this->superadmin();

        $principal = $this->empresa(
            nombre: 'Encomiendas Demo',
            adminCorreo: 'admin@encomienda.test',
            sede: 'San José Central',
            prefijo: 'SJ'
        );

        // La segunda empresa comparte prefijo de sede con la primera a
        // propósito: es el choque que el índice único por empresa tiene que
        // permitir, y el que rompía cuando los códigos guía eran globales.
        $this->empresa(
            nombre: 'Transportes Vecinos',
            adminCorreo: 'admin@vecinos.test',
            sede: 'San José Centro',
            prefijo: 'SJ'
        );

        $this->command?->info("Superadministrador: {$superadmin->email} / password");

        // El resto de la demo es de la empresa principal: sedes adicionales,
        // repartidores y guías con historia.
        CompanyContext::para($principal, function () {
            $alajuela = Branch::firstOrCreate(
                ['sucursal_code' => '002', 'terminal_code' => '00001'],
                ['name' => 'Alajuela Centro', 'prefix' => 'ALA', 'address' => 'Alajuela, Costa Rica',
                 'province' => '2', 'canton' => '01', 'district' => '01', 'is_active' => true]
            );

            User::firstOrCreate(
                ['email' => 'repartidor@encomienda.test'],
                [
                    'name' => 'Repartidor Demo',
                    'username' => 'repartidor',
                    'password' => bcrypt('password'),
                    'role' => User::ROLE_REPARTIDOR,
                    'branch_id' => $alajuela->id,
                    'is_active' => true,
                ]
            );

            $this->call(CajaSeeder::class);
            $this->call(DemoDataSeeder::class);
        });
    }

    /** El dueño del sistema: sin empresa, para que las vea todas. */
    private function superadmin(): User
    {
        $superadmin = User::withoutGlobalScopes()->firstOrNew(['email' => 'superadmin@encomienda.test']);

        if (! $superadmin->exists) {
            $superadmin->fill([
                'name' => 'Superadministrador',
                'username' => 'superadmin',
                'password' => bcrypt('password'),
                'role' => User::ROLE_SUPERADMIN,
                'is_active' => true,
            ]);
            $superadmin->sinEmpresa()->save();
        }

        return $superadmin;
    }

    /** Una empresa completa, o la que ya exista con ese nombre. */
    private function empresa(string $nombre, string $adminCorreo, string $sede, string $prefijo): Company
    {
        $existente = Company::where('name', $nombre)->first();

        if ($existente) {
            return $existente;
        }

        return app(CompanyProvisioner::class)->crear([
            'name'           => $nombre,
            'legal_name'     => $nombre . ' S.A.',
            'identification' => null,
            'email'          => $adminCorreo,
            'phone'          => null,
            'expires_on'     => null,
            'notes'          => null,
            'admin_name'     => 'Administrador',
            'admin_email'    => $adminCorreo,
            'admin_username' => null,
            'admin_password' => 'password',
            'branch_name'    => $sede,
            'branch_prefix'  => $prefijo,
        ]);
    }
}
